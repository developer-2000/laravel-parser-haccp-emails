<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AppLogger;
use App\Services\LeadClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CrawlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Slug'и контактных/юридических/о-компании страниц, по которым шлём
     * дополнительные fetch'и в поиске email и телефонов.
     *
     * Список мультиязычный (топ-7 языков EU + общие). Для редких языков
     * работает extractMenuContactLinks(), который читает ссылки прямо
     * с главной по их фактическому названию.
     */
    private const CONTACT_PATHS = [
        // contact / kontakt / contatti / contacto / contato
        'contact', 'contact-us', 'contacts',
        'kontakt', 'kontakti', 'kontaktai',
        'contacto', 'contatti', 'contato',
        'kapcsolat', 'yhteystiedot',

        // О компании
        'about', 'about-us',
        'ueber-uns', 'uber-uns',
        'chi-siamo', 'sobre-nos', 'sobre-nosotros',
        'qui-sommes-nous', 'a-propos',
        'o-nas', 'o-nama', 'over-ons', 'om-oss',

        // Команда / компания / impressum
        'team', 'equipe', 'equipo', 'squadra',
        'company', 'unternehmen', 'firma', 'empresa', 'azienda',
        'impressum', 'imprint',

        // Юр. / приватность
        'legal', 'privacy', 'privacy-policy',
        'datenschutz', 'agb',
        'mentions-legales', 'aviso-legal', 'privacidade',

        // Поддержка / продажи
        'support', 'help', 'customer-service',
        'sales', 'vertrieb', 'office',
    ];

    /**
     * URL компании, которую мы будем скрапить
     * (это домен из SearchJob)
     */
    public string $url;
    public int $searchQueryId;

    /**
     * id типа бизнеса (type_business.id) — нужен LeadClassifier'у
     * для выбора набора сигналов из config/site/meat_scoring.php.
     */
    public ?int $typeBusinessId;

    public function __construct(string $url, int $searchQueryId, ?int $typeBusinessId = null)
    {
        $this->url = $url;
        $this->searchQueryId = $searchQueryId;
        $this->typeBusinessId = $typeBusinessId;
    }

    /**
     * ОСНОВНОЙ PIPELINE:
     * 1. скачать HTML главной страницы
     * 2. извлечь базовые данные (email, phone, title)
     * 3. пройти по мультиязычному списку контактных страниц (CONTACT_PATHS)
     * 4. объединить данные
     * 5. очистить и дедуплицировать
     * 6. сохранить в БД как компанию
     */
    public function handle(AppLogger $logger, LeadClassifier $classifier): void
    {
        // лог начала обработки URL
        $logger->write('CrawlJob START: ' . $this->url, 'crawl.log');

        /**
         * STEP 1:
         * загрузка HTML главной страницы сайта
         */
        $html = $this->fetch($this->url);

        /**
         * защита от пустых/битых страниц
         * (если сайт блокирует или отдаёт мусор)
         */
        if (!$html || strlen($html) < 1000) {
            $logger->write('EMPTY HTML: ' . $this->url, 'crawl.log');
            return;
        }

        /**
         * STEP 1.5 — Off-topic фильтр (Tier 2).
         * До любых extract'ов и до записи в БД проверяем title на маркеры не-производителей:
         * Anlagenbau / Maschinenbau / Edelstahl / Verband / Großhandel / Logistik /
         * Versicherung / Steuerberater / Statistik / "Human Verification" / "Security Check".
         * Если матчится — сайт точно не мясной производитель → ничего не пишем в БД.
         */
        if ($this->isOffTopic($html)) {
            $logger->write('OFF-TOPIC: ' . $this->url, 'crawl.log');
            return;
        }

        /**
         * STEP 1.6 — Финальная классификация лида через LeadClassifier.
         *
         * Считает tier 0..3 на основе сигналов URL и HTML:
         *   0 — IGNORE  (drop)
         *   1 — WEAK    (drop, только premium лиды доходят до записи в БД)
         *   2 — GOOD    (сохраняем)
         *   3 — IDEAL   (сохраняем)
         *
         * Порог tier < 2 — компания не пишется в БД. Это убирает D2C-бренды,
         * розничных мясников и компании без сильных ICP-сигналов.
         */
        $tier = $classifier->classify($this->url, $html, $this->typeBusinessId);
        $logger->write("LEAD TIER: {$tier} {$this->url}", 'crawl.log');

        if ($tier < 2) {
            $logger->write("LOW TIER DROP: tier={$tier} {$this->url}", 'crawl.log');
            return;
        }

        /**
         * STEP 2:
         * извлечение базовых данных с главной страницы
         */
        $emails = $this->extractEmails($html);   // email через regex
        $phones = $this->extractPhones($html);   // телефоны через regex

        /**
         * извлекаем title сайта как fallback название компании
         */
        $companyName = $this->extractTitle($html);

        /**
         * STEP 3:
         * проходим по мультиязычному списку контактных страниц
         * (CONTACT_PATHS) и собираем email/phone со всех существующих.
         * Для редких языков работает STEP 5.5 (extractMenuContactLinks).
         */
        $base = rtrim($this->url, '/');
        foreach (self::CONTACT_PATHS as $path) {
            $pageHtml = $this->fetch($base . '/' . $path . '/');
            if (!$pageHtml) {
                continue;
            }
            $emails = array_merge($emails, $this->extractEmails($pageHtml));
            $phones = array_merge($phones, $this->extractPhones($pageHtml));
        }

        /**
         * STEP 5.5:
         * поиск контактных ссылок в меню главной страницы.
         *
         * Зачем: CMS типа typo3/joomla с pageid-навигацией отдают контакты
         * по нестандартным URL вроде /index.php?pageid=11, и шаблонные
         * /impressum/ + /kontakt/ выше дадут 404. Здесь читаем <a href>
         * с текстом ссылки = Kontakt/Contact/Impressum/Anfahrt и идём
         * прямо по найденному URL (только same-domain, дедуп).
         *
         * Пример (fleischer-proesch.de): меню "Kontakt" → ?pageid=11.
         */
        $menuLinks = $this->extractMenuContactLinks($html, $this->url);
        foreach ($menuLinks as $menuUrl) {
            $menuHtml = $this->fetch($menuUrl);
            if (!$menuHtml) {
                continue;
            }
            $emails = array_merge($emails, $this->extractEmails($menuHtml));
            $phones = array_merge($phones, $this->extractPhones($menuHtml));
        }

        /**
         * STEP 6:
         * очистка данных:
         * - убираем дубликаты
         * - убираем null/empty
         */
        $emails = array_values(array_unique(array_filter($emails)));
        $phones = array_values(array_unique(array_filter($phones)));

        /**
         * STEP 7:
         * фильтрация мусорных email (test/example/dummy)
         */
        $emails = $this->filterEmails($emails);

        /**
         * STEP 8:
         * сохранение/обновление компании в базе
         *
         * ключ:
         * - url (уникальность)
         *
         * данные:
         * - name
         * - emails
         * - phones
         * - raw_checked = обработан ли сайт
         */
        Company::updateOrCreate(
            ['url' => $this->url],
            [
                'name' => $companyName,
                'emails' => json_encode($emails),
                'phones' => json_encode($phones),
                'tier' => $tier,
                'search_query_id' => $this->searchQueryId,
            ]
        );

        /**
         * финальный лог результата
         */
        $logger->write('CrawlJob DONE: ' . $this->url, 'crawl.log');
        $logger->write('emails=' . count($emails) . ' phones=' . count($phones), 'crawl.log');
    }

    /**
     * STEP A:
     * скачивание HTML страницы.
     *
     * Защита:
     *   - timeout
     *   - try/catch (сайт может упасть или блокировать)
     *   - нормализация кодировки в UTF-8 (старые .de-сайты часто отдают
     *     ISO-8859-1 / Windows-1252, и сырые байты типа \xF6 (ö) ломают
     *     INSERT в MySQL utf8mb4 → джоба падает).
     */
    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'text/html',
                ])
                ->get($url);

            return $this->toUtf8($response->body());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Привести HTML к валидному UTF-8.
     *
     * Алгоритм:
     *   1. Если <meta charset=...> или <meta http-equiv="Content-Type" charset=...>
     *      указывает не-UTF-8 — конвертируем из неё.
     *   2. Иначе — авто-детект через mb_detect_encoding между UTF-8/ISO-8859-1/Windows-1252.
     *   3. Если всё равно не UTF-8 валидный — финально прогоняем через mb_convert_encoding
     *      с заменой неверных байт.
     */
    private function toUtf8(string $body): string
    {
        $encoding = null;

        // 1. Декларация в <meta charset>.
        if (preg_match('/<meta[^>]*charset=["\']?([\w-]+)/i', $body, $m)) {
            $declared = strtoupper(trim($m[1]));
            $declared = match ($declared) {
                'LATIN1', 'LATIN-1' => 'ISO-8859-1',
                'CP1252' => 'WINDOWS-1252',
                default => $declared,
            };
            if (in_array($declared, ['ISO-8859-1', 'WINDOWS-1252', 'ISO-8859-15'], true)) {
                $encoding = $declared;
            }
        }

        // 2. Авто-детект если meta-тега нет (или он сказал UTF-8, но байты невалидны).
        if (!$encoding && !mb_check_encoding($body, 'UTF-8')) {
            $encoding = mb_detect_encoding(
                $body,
                ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'],
                true
            ) ?: 'ISO-8859-1';
        }

        if ($encoding && $encoding !== 'UTF-8') {
            $body = mb_convert_encoding($body, 'UTF-8', $encoding);
        }

        // 3. Финальная страховка — заменим оставшиеся невалидные байты.
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        return $body;
    }

    /**
     * STEP B:
     * извлечение email — 6 проходов:
     *   1. Прямой regex по сырому HTML (для незашитых email).
     *   2. После нормализации обфускаций (∂ / [at] / (at) / &#64; / &commat; → @,
     *      удаление <span>...</span> и других тегов между частями адреса).
     *   3. Парсинг data-email='{"name":"X","host":"Y"}' атрибутов (CMS типа JTL,
     *      Shopware, Joomla кладут туда контакт-email и склеивают через JS).
     *   4. Cloudflare email protection (data-cfemail / cdn-cgi/l/email-protection)
     *      — XOR-зашифрованные email на сайтах за CF.
     *   5. Mailto-ссылки с URL-encoded или HTML-entity содержимым
     *      (mailto:%69%6e%66%6f@site.de или mailto:info&#64;site.de) —
     *      обычный regex такие пропускает.
     *   6. JS-конкатенация ("info" + "@" + "company.de") — частая обфускация
     *      от ботов, обычный regex её не видит.
     *
     * Ограничения базового regex:
     *   - TLD длиной 2..6 — чтобы не захватить "deUmsatzsteuer..." после .de.
     *   - (?![a-zA-Z]) — гарантирует, что после TLD идёт не-буква.
     */
    private function extractEmails(string $html): array
    {
        $regex = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}(?![a-zA-Z])/';

        // Проход 1: сырой HTML.
        preg_match_all($regex, $html, $direct);

        // Проход 2: нормализация обфускаций.
        $normalized = $html;
        // Заменяем визуальные псевдо-@: ∂ (partial differential), &#64;, &commat;, [at], (at), [@], (@)
        $normalized = str_replace(
            ['∂', '&#64;', '&#0064;', '&commat;', '[at]', '(at)', '[@]', '(@)', ' [at] ', ' (at) '],
            '@',
            $normalized
        );
        // Заменяем теги ПРОБЕЛОМ (а не пустой строкой). strip_tags склеивает соседние
        // ячейки таблиц/абзацев без разделителя — это даёт мусор вида
        // "telefonverkaufs.ernst@..." (имя из ячейки + username из мейлто рядом).
        $normalized = preg_replace('/<[^>]+>/', ' ', $normalized);
        // Схлопываем пробелы вокруг @ — кейс "support [at] example.com" после
        // подмены [at]→@ оставляет "support @ example.com", который regex не видит.
        $normalized = preg_replace('/([\w._%+-])\s*@\s*([\w.-])/u', '$1@$2', $normalized);
        preg_match_all($regex, $normalized, $obfuscated);

        // Проход 3: data-email JSON-атрибуты вида {"name":"info","host":"verdie-gmbh.de"}.
        // CMS экранируют внутренние кавычки как &quot; — берём содержимое атрибута,
        // декодируем html-entities ОТДЕЛЬНО, потом ищем name/host пару.
        $jsonEmails = [];
        if (preg_match_all('/data-email=["\']([^"\']+)["\']/i', $html, $attrMatches)) {
            foreach ($attrMatches[1] as $rawAttr) {
                $decoded = html_entity_decode($rawAttr);
                if (preg_match(
                    '/"?name"?\s*:\s*"?([a-zA-Z0-9._%+-]+)"?[^}]*?"?host"?\s*:\s*"?([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})/i',
                    $decoded,
                    $m
                )) {
                    $jsonEmails[] = $m[1] . '@' . $m[2];
                }
            }
        }

        // Проход 4: Cloudflare email protection.
        $cfEmails = $this->decodeCfEmails($html);

        // Проход 5: mailto-ссылки с URL-encoded / HTML-entity содержимым.
        $mailtoEmails = $this->extractMailtoEmails($html);

        // Проход 6: JS-конкатенация ("info" + "@" + "company.de").
        $jsConcatEmails = $this->extractJsConcatEmails($html);

        return $this->cleanEmailCandidates(array_merge(
            $direct[0] ?? [],
            $obfuscated[0] ?? [],
            $jsonEmails,
            $cfEmails,
            $mailtoEmails,
            $jsConcatEmails
        ));
    }

    /**
     * Финальная очистка кандидатов внутри extractEmails:
     *   - нормализация (strtolower + trim) — чтобы INFO@SITE.DE и info@site.de
     *     схлопывались на этапе array_unique;
     *   - отсев псевдо-email из имён файлов (logo@2x.png, font@2x.woff2),
     *     раньше эта проверка стояла в filterEmails;
     *   - отсев пустых строк.
     *
     * Шаблонные имена и tracking-домены здесь НЕ отсекаем — это финальная
     * сетка filterEmails в handle().
     */
    private function cleanEmailCandidates(array $emails): array
    {
        $assetExt = '/\.(png|jpe?g|gif|webp|svg|ico|bmp|css|js|woff2?|ttf|eot)$/i';

        $clean = [];
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email === '' || preg_match($assetExt, $email)) {
                continue;
            }
            $clean[] = $email;
        }

        return $clean;
    }

    /**
     * Извлекает email, склеенный в JS из строковых литералов:
     *   var email = "info" + "@" + "company.de";
     *   var email = 'info' + '@' + 'company.de';
     *
     * Кавычки могут быть смешанными между сегментами. Регекс намеренно простой
     * (3 части), более экзотические варианты (split на 2 куска) не покрываем —
     * ROI пограничный.
     *
     * Используется в extractEmails() как 6-й проход.
     */
    private function extractJsConcatEmails(string $html): array
    {
        $regex = '/["\']([a-zA-Z0-9._%+-]+)["\']\s*\+\s*["\']@["\']\s*\+\s*["\']([a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})["\']/';

        if (!preg_match_all($regex, $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $emails = [];
        foreach ($matches as $m) {
            $emails[] = $m[1] . '@' . $m[2];
        }

        return $emails;
    }

    /**
     * Извлекает email из mailto-ссылок с раскрытием URL-encoded и HTML-entity.
     *
     * Ловит случаи, которые обычный regex по тексту HTML пропускает:
     *   - mailto:%69%6e%66%6f@site.de  (URL-encoded имя)
     *   - mailto:info&#64;site.de      (HTML-entity вместо @)
     *
     * Используется в extractEmails() как 5-й проход.
     */
    private function extractMailtoEmails(string $html): array
    {
        if (!preg_match_all('/mailto:([^"\'>?\s]+)/i', $html, $matches)) {
            return [];
        }

        $emails = [];
        foreach ($matches[1] as $raw) {
            $email = urldecode(html_entity_decode($raw));
            if (preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/', $email)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Извлекает все Cloudflare-зашифрованные email из HTML.
     *
     * CF прячет email двумя способами:
     *   - <span class="__cf_email__" data-cfemail="6e07000801...">[email protected]</span>
     *   - <a href="/cdn-cgi/l/email-protection#6e07000801...">contact</a>
     *
     * Используется в extractEmails() как 4-й проход.
     */
    private function decodeCfEmails(string $html): array
    {
        if (!preg_match_all(
            '/(?:data-cfemail=["\']|cdn-cgi\/l\/email-protection#)([a-f0-9]+)/i',
            $html,
            $matches
        )) {
            return [];
        }

        $emails = [];
        foreach ($matches[1] as $encoded) {
            $decoded = $this->decodeCfEmail($encoded);
            if ($decoded !== null) {
                $emails[] = $decoded;
            }
        }

        return $emails;
    }

    /**
     * Декодирует один CF-email: первый байт строки — XOR-ключ,
     * остальные байты — символы email, зашифрованные XOR'ом с этим ключом.
     *
     * Возвращает null, если строка некорректна или результат не похож на email.
     */
    private function decodeCfEmail(string $encoded): ?string
    {
        $len = strlen($encoded);
        if ($len < 4 || $len % 2 !== 0) {
            return null;
        }

        $key = hexdec(substr($encoded, 0, 2));
        $email = '';

        for ($n = 2; $n < $len; $n += 2) {
            $email .= chr(hexdec(substr($encoded, $n, 2)) ^ $key);
        }

        return preg_match('/^[^@\s]+@[^@\s]+\.[a-zA-Z]{2,}$/', $email) ? $email : null;
    }

    /**
     * STEP C:
     * извлечение телефонов из HTML-кода страницы
     *
     * Логика работы:
     * 1. сначала ищем телефонные значения только в текстовом контексте
     *    рядом с маркерами: Telefon / Tel / Phone
     *
     * 2. затем извлекаем потенциальные номера из найденных фрагментов
     *
     * 3. очищаем результат от лишних символов (пробелы, тире, скобки)
     *
     * 4. отбрасываем нерелевантные значения:
     *    - даты
     *    - ID / артефакты HTML
     *    - слишком короткие или слишком длинные последовательности
     *
     * Цель:
     * получить только реальные телефонные номера компаний (EU формат),
     * а не любые числовые последовательности из страницы
     */
    private function extractPhones(string $html): array
    {
        $phones = [];

        // 1. сначала нормализуем текст (убираем HTML шум)
        $text = strip_tags($html);

        // 2. ищем только строки где явно есть телефонный контекст
        preg_match_all(
            '/(telefon|tel|phone)\s*[:\-]?\s*([^\n<]{6,40})/i',
            $text,
            $matches
        );

        if (!empty($matches[2])) {
            foreach ($matches[2] as $raw) {
                $phones[] = $this->cleanPhone($raw);
            }
        }

        return array_values(array_filter($phones));
    }

    private function cleanPhone(string $value): ?string
    {
        // убираем всё кроме цифр и +
        $value = preg_replace('/[^0-9+]/', '', $value);

        // базовая проверка длины
        if (strlen($value) < 7 || strlen($value) > 15) {
            return null;
        }

        return $value;
    }

    /**
     * STEP D:
     * извлечение title страницы
     *
     * используется как fallback название компании
     */
    private function extractTitle(string $html): ?string
    {
        preg_match('/<title>(.*?)<\/title>/i', $html, $m);
        return $m[1] ?? null;
    }

    /**
     * STEP F:
     * фильтрация мусорных email (финальная сетка).
     *
     * Убираем:
     *   - шаблонные placeholder'ы (test/example/dummy/mustermann/mymail/youremail)
     *   - tracking/телеметрия (Sentry, Google Tag Manager, Wix-pixel)
     *   - long-hex usernames (>=20 hex-символов до @ — это ID, не email)
     *
     * Asset-файлы (logo@2x.png и т.п.) отсекаются раньше — внутри
     * extractEmails через cleanEmailCandidates.
     */
    private function filterEmails(array $emails): array
    {
        // Placeholder'ы, которые встречаются как ПОЛНЫЙ username (до @).
        $placeholderUsers = ['noreply', 'no-reply', 'mymail', 'youremail', 'test', 'dummy', 'name'];

        // Placeholder'ы для домена — match если первый сегмент домена равен одному из этих
        // (mustermann.de, example.com и т.п. — точное совпадение, не substring).
        $placeholderDomains = ['mustermann', 'example', 'test', 'dummy', 'beispiel'];

        // array_values — переиндексирует, иначе после array_filter ниже остаются дырки в ключах
        // (вроде [63 => 'hof@...', 64 => 'info@...']) и json_encode превращает массив в JSON-object.
        return array_values(
            array_filter($emails, function ($email) use ($placeholderUsers, $placeholderDomains) {
                // Структурный разбор: user@domain → берём username и первую часть домена (до точки).
                $parts = explode('@', $email, 2);
                if (count($parts) !== 2) {
                    return false;
                }
                $user = strtolower($parts[0]);
                $domainHead = strtolower(explode('.', $parts[1])[0] ?? '');

                // 1. Username — целиком плейсхолдер (noreply@..., test@..., name@...).
                if (in_array($user, $placeholderUsers, true)) {
                    return false;
                }

                // 2. Домен — целиком плейсхолдер (max@mustermann.de, foo@example.com).
                if (in_array($domainHead, $placeholderDomains, true)) {
                    return false;
                }

                // 3. Длинный hex-username (Sentry-DSN, GA-ID, и подобные tracking-эндпоинты).
                //    Пример: 605a7baede844d278b89dc95ae0a9123@sentry-next.wixpress
                if (preg_match('/^[a-f0-9]{20,}@/i', $email)) {
                    return false;
                }

                // 4. Известные tracking/analytics домены
                if (preg_match('/@(sentry|wixpress|google-analytics|googletagmanager|hotjar|hubspot)/i', $email)) {
                    return false;
                }

                // 5. Sanity-проверка качества (ловит склейки типа telefonverkaufs.ernst@…,
                //    9966-250zentrale@…det, freitagj.freitag@…).
                if (!$this->validateEmailQuality($email)) {
                    return false;
                }

                return true;
            })
        );
    }

    /**
     * Sanity-проверка качества email — ловит склейки и битые адреса,
     * прорвавшиеся сквозь все 6 проходов извлечения.
     *
     * Правила:
     *   1. Домен содержит точку.
     *   2. TLD из whitelist EU (de/at/ch + EN/.com/EU + 25 национальных).
     *      Отсекает мусор вроде ".det", ".cim", ".dee" — артефакты склеек
     *      domain-text после strip_tags.
     *   3. Username не длиннее 32 символов.
     *   4. Username не начинается с 2+ цифр (телефон, склеившийся с email).
     *   5. Username не содержит текстовый мусор (telefon/fax/durchwahl/…).
     *   6. Username без двойного повторения слова длиннее 3 символов
     *      (схватывает склейки вроде demuths.demuth, freitagj.freitag).
     *   7. Username содержит максимум 2 точки.
     */
    private function validateEmailQuality(string $email): bool
    {
        $parts = explode('@', strtolower($email), 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$user, $domain] = $parts;

        if (!str_contains($domain, '.')) {
            return false;
        }

        $tldWhitelist = '/\.(?:'
            // global
            . 'com|net|org|eu|info|io|biz'
            // DACH
            . '|de|at|ch|li'
            // FR / BE / LU
            . '|fr|be|lu|mc'
            // IT / IB
            . '|it|sm'
            // ES / PT
            . '|es|pt|ad'
            // NL
            . '|nl'
            // CEE
            . '|pl|cz|sk|hu|ro|bg|hr|si'
            // Nordics
            . '|dk|se|no|fi|is'
            // British Isles + IE
            . '|ie|uk|gb|im|je'
            // South-EU
            . '|gr|cy|mt|tr'
            // Baltics
            . '|lt|lv|ee'
            . ')$/';
        if (!preg_match($tldWhitelist, $domain)) {
            return false;
        }

        if (strlen($user) > 32) {
            return false;
        }

        if (preg_match('/^\d{2,}/', $user)) {
            return false;
        }

        if (preg_match('/telefon|fax|durchwahl|verkauf|innendienst|tssicherung/', $user)) {
            return false;
        }

        if (preg_match('/([a-z]{3,}).*\1/', $user)) {
            return false;
        }

        if (substr_count($user, '.') > 2) {
            return false;
        }

        return true;
    }

    /**
     * STEP G — Off-topic фильтр (Tier 2 классификации).
     *
     * Цель: отсечь сайты, которые URL-фильтр в SearchJob пропустил, но по
     * содержимому очевидно НЕ являются мясными производителями:
     *   - Equipment / Anlagenbau / Maschinenbau / Edelstahl / Verpackung
     *   - Verband / Verein / Industrie-Vereinigung
     *   - Großhandel / Wholesale (оптовики, не производители)
     *   - Logistik / Spedition / Transport
     *   - Reinigung / Arbeitsmedizin / Arbeitssicherheit
     *   - Versicherung / Steuerberater / Rechtsanwalt
     *   - Immobilien / Real Estate / Statistik
     *   - "Human Verification" / "Security Check" — captcha/bot-страницы блокировок
     *
     * Что проверяем: только <title> страницы. Это самый сильный сигнал — title
     * почти всегда отражает суть бизнеса. Достаточно для отсечки большинства
     * случаев типа "ten Brink Anlagenbau", "Schleifer Maschinenbau", "VDF Verband".
     *
     * Если title пустой / отсутствует — возвращаем false (cautious default:
     * лучше пропустить шум, чем потерять реального производителя).
     */
    private function isOffTopic(string $html): bool
    {
        if (!preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            return false;
        }

        $title = html_entity_decode(strip_tags($m[1]));

        $patterns = [
            // Equipment / промышленное оборудование
            'Anlagenbau', 'Maschinenbau', 'Maschinen', 'Edelstahl',
            'Verpackungsmaschine', 'Verpackungstechnik', 'Equipment',
            // Ассоциации / союзы
            'Verband', 'Verein', 'Innung', 'Fachverband', 'Bundesverband',
            // Оптовики / b2b торговля
            'Großhandel', 'Grosshandel', 'Wholesale',
            // Сервис / клининг / охрана труда
            'Reinigung', 'Arbeitsmedizin', 'Arbeitssicher',
            // Не-производители (околотемы)
            'Logistik', 'Spedition',
            'Immobilien', 'Real Estate',
            'Versicherung', 'Steuerberater', 'Rechtsanwalt',
            'Statistik', 'Statistical',
            // Captcha / bot-блокировки
            'Human Verification', 'Security Check', 'Robot Check', 'Access Denied',
        ];

        $regex = '/' . implode('|', array_map('preg_quote', $patterns)) . '/i';

        return (bool) preg_match($regex, $title);
    }

    /**
     * STEP I — поиск контактных ссылок в меню главной страницы.
     *
     * Парсит все <a href="X">Y</a>, фильтрует по тексту ссылки Y
     * (Kontakt / Contact / Impressum / Anfahrt / Standort), резолвит
     * относительные href в абсолютные URL и оставляет только same-domain.
     *
     * Дедуп — по абсолютному URL. Лимит 5 ссылок, чтоб не зацикливаться
     * на сайтах, где "Kontakt" встречается в каждом блоке (footer, header,
     * sidebar часто дублируют ссылку).
     *
     * Возвращает массив абсолютных URL.
     */
    private function extractMenuContactLinks(string $html, string $baseUrl): array
    {
        $keywords = [
            // контакт
            'kontakt', 'contact', 'contacts',
            'contatti', 'contacto', 'contato',
            'kapcsolat', 'yhteystiedot',

            // импрессум / о компании
            'impressum', 'imprint',
            'about', 'about us',
            'über uns', 'ueber uns', 'uber uns',
            'chi siamo', 'sobre nos', 'sobre nosotros',
            'qui sommes nous', 'a propos', 'à propos',
            'o nas', 'over ons', 'om oss',
            'unternehmen', 'company', 'firma',
            'azienda', 'empresa',

            // команда
            'team', 'equipe', 'équipe',
            'equipo', 'squadra',

            // поддержка / продажи / офис
            'support', 'service', 'kundenservice',
            'customer service', 'customer care', 'customer-service',
            'sales', 'vertrieb', 'office', 'help',

            // адрес / расположение
            'anfahrt', 'standort',
        ];

        if (!preg_match_all(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $links = [];

        foreach ($matches as $m) {
            $text = mb_strtolower(trim(html_entity_decode(strip_tags($m[2]))));
            if ($text === '') {
                continue;
            }

            $matched = false;
            foreach ($keywords as $kw) {
                // \b-граница, чтобы "kontaktformular" тоже ловился, но не цеплять рандомные подстроки
                if (preg_match('/\b' . preg_quote($kw, '/') . '/u', $text)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            $abs = $this->absoluteUrl($m[1], $baseUrl);
            if (!$abs) {
                continue;
            }

            // только same-domain (отсеиваем ссылки на facebook/google maps/и т.п.)
            if (parse_url($abs, PHP_URL_HOST) !== $baseHost) {
                continue;
            }

            $links[$abs] = true;
            if (count($links) >= 5) {
                break;
            }
        }

        return array_keys($links);
    }

    /**
     * STEP J — резолв относительного href в абсолютный URL.
     *
     * Поддерживает:
     *   - http(s):// — возвращаем как есть
     *   - //host/path — добавляем схему base
     *   - /path — добавляем origin base
     *   - relative/path — клеим к директории base
     *
     * Отсекаем нерелевантные схемы: mailto:, tel:, javascript:, #anchor.
     */
    private function absoluteUrl(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        if (preg_match('/^(mailto:|tel:|javascript:)/i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (str_starts_with($href, '//')) {
            return $parts['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        // относительный путь — клеим к директории base
        $path = $parts['path'] ?? '/';
        if (!str_ends_with($path, '/')) {
            $pos = strrpos($path, '/');
            $path = $pos !== false ? substr($path, 0, $pos + 1) : '/';
        }

        return $origin . $path . $href;
    }
}
