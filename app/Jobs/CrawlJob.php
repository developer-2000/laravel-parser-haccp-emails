<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AppLogger;
use App\Services\HtmlEncoding;
use App\Services\LeadClassifier;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
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
     * Таймаут (сек) на один HTTP-запрос внутри fetch().
     *
     * 20 сек — соответствует прежнему быстрому-полному поведению. 99% живых
     * сайтов отвечают за 1-2 сек, 20 покрывает редкие медленные хостинги.
     * Для несуществующих путей сервер обычно отвечает 404/500 моментально,
     * так что обход CONTACT_PATHS не растягивается.
     */
    private const FETCH_TIMEOUT = 20;

    /**
     * Username-часть email — буквы/цифры и `._%+-` (согласно RFC 5322 lite).
     * Используется как стройблок в EMAIL_REGEX и в regex'ах JSON-/JS-форматов.
     */
    private const EMAIL_USER = '[a-zA-Z0-9._%+-]+';

    /**
     * Domain-часть email — `host.tld` с TLD 2..6 латинских символов
     * (длиннее уже подозрительно — обычно склейка domain+text после strip_tags).
     */
    private const EMAIL_DOMAIN = '[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}';

    /**
     * Свободный email-regex: ловит адрес в произвольном тексте.
     * Lookahead `(?![a-zA-Z])` исключает захват "deUmsatzsteuer..." после ".de" —
     * типичная склейка после strip_tags соседних блоков HTML.
     */
    private const EMAIL_REGEX = '/' . self::EMAIL_USER . '@' . self::EMAIL_DOMAIN . '(?![a-zA-Z])/';

    /**
     * Anchored-вариант — для финальной валидации одной целой строки,
     * прошедшей через urldecode/html_entity_decode (extractMailtoEmails).
     */
    private const EMAIL_REGEX_ANCHORED = '/^' . self::EMAIL_USER . '@' . self::EMAIL_DOMAIN . '$/';

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

    /**
     * Логгер для записи в crawl.log.
     *
     * Остаётся null до начала handle() — там присваивается резолвнутый из DI
     * экземпляр и используется всеми приватными pipeline-методами через
     * $this->logger. Поле НЕ должно попадать в payload очереди: Job всегда
     * сериализуется ДО handle (когда $this->logger === null), а после handle
     * ре-сериализации не происходит.
     */
    private ?AppLogger $logger = null;

    /**
     * Сервис приведения HTML к валидному UTF-8.
     *
     * Зачем: сырые байты non-UTF-8 .de-сайтов ломают INSERT в utf8mb4-таблицы.
     * Используется в fetch() сразу после получения тела ответа. Поле остаётся
     * null до handle(), где резолвится через DI — ровно по той же схеме, что
     * и $this->logger (не должно попадать в payload очереди).
     */
    private ?HtmlEncoding $encoder = null;

    public function __construct(string $url, int $searchQueryId, ?int $typeBusinessId = null)
    {
        $this->url = $url;
        $this->searchQueryId = $searchQueryId;
        $this->typeBusinessId = $typeBusinessId;
    }

    /**
     * ОСНОВНОЙ PIPELINE:
     * 1. скачать HTML главной страницы и проверить, что сайт не off-topic / не низкий tier;
     * 2. собрать email/phone с homepage + CONTACT_PATHS + menuLinks;
     * 3. очистить, отфильтровать и сохранить компанию в БД.
     */
    public function handle(AppLogger $logger, LeadClassifier $classifier, HtmlEncoding $encoder): void
    {
        $this->logger = $logger;
        $this->encoder = $encoder;
        $startedAt = microtime(true);

        $this->log('===== CrawlJob START: ' . $this->url . ' =====');

        $html = $this->fetchHomepage();
        if ($html === null) {
            $this->log('  pipeline aborted: empty homepage');
            return;
        }

        $title = $this->extractTitle($html);
        $this->log('  TITLE: ' . ($title ?? '(null)'));

        if ($this->isOffTopic($title)) {
            $this->log('  OFF-TOPIC drop (title matched off-topic patterns)');
            return;
        }

        $tier = $classifier->classify($this->url, $html, $this->typeBusinessId);
        $this->log("  LEAD TIER: {$tier}");
        if (!$classifier->passesCrawl($tier)) {
            $this->log("  LOW TIER DROP (tier={$tier} < " . LeadClassifier::MIN_TIER_FOR_CRAWL . ')');
            return;
        }

        [$emails, $phones] = $this->collectContacts($html);

        $this->persistCompany($title, $tier, $emails, $phones);

        $duration = round(microtime(true) - $startedAt, 2);
        $this->log("===== CrawlJob END: {$this->url} (took {$duration}s) =====");
    }

    /**
     * Шорткат для записи строки в crawl.log с двухпробельным отступом
     * для under-stage-логов (визуально выделяет иерархию START/END).
     */
    private function log(string $message): void
    {
        $this->logger->writeFile($message, 'crawl.log');
    }

    /**
     * Скачать HTML главной страницы и сделать sanity-check.
     *
     * Возвращает null, если страница не загрузилась или слишком короткая
     * (< 1000 байт — обычно сайт-заглушка / блокировка / редирект на пустоту).
     * В этом случае пишет EMPTY HTML в crawl.log — saller просто делает return.
     */
    private function fetchHomepage(): ?string
    {
        $startedAt = microtime(true);
        $html = $this->fetch($this->url);
        $duration = round(microtime(true) - $startedAt, 2);

        if (!$html) {
            $this->log("  HOMEPAGE: fetch FAIL (null body) after {$duration}s");
            return null;
        }

        $bytes = strlen($html);
        if ($bytes < 1000) {
            $this->log("  HOMEPAGE: TOO SHORT (bytes={$bytes}, threshold=1000) after {$duration}s — skipping");
            return null;
        }

        $this->log("  HOMEPAGE: ok (bytes={$bytes}) after {$duration}s");

        return $html;
    }

    /**
     * Собрать email и phone с homepage, CONTACT_PATHS и menu-links.
     *
     * Pipeline:
     *   1. Извлечение с homepage (HTML уже в руках, fetch не нужен).
     *   2. Полный обход CONTACT_PATHS (мультиязычные шаблоны: contact, kontakt, ...).
     *   3. Обход найденных в меню ссылок (fleischer-proesch.de/?pageid=11 и т.п.).
     *
     * @return array{0: list<string>, 1: list<string>} Накопленные [emails, phones]
     *         (без финальной очистки/фильтрации — это делает persistCompany).
     */
    private function collectContacts(string $html): array
    {
        $emails = $this->extractEmails($html);
        $phones = $this->extractPhones($html);

        $base = rtrim($this->url, '/');
        foreach (self::CONTACT_PATHS as $path) {
            [$emails, $phones] = $this->collectContactsFrom($base . '/' . $path . '/', $emails, $phones);
        }

        foreach ($this->extractMenuContactLinks($html, $this->url) as $menuUrl) {
            [$emails, $phones] = $this->collectContactsFrom($menuUrl, $emails, $phones);
        }

        return [$emails, $phones];
    }

    /**
     * Финальная очистка контактов и upsert компании в БД.
     *
     * Шаги:
     *   1. дедуп email/phone, убираем пустые;
     *   2. фильтрация шаблонных/tracking email'ов через filterEmails;
     *   3. Company::updateOrCreate по уникальному url;
     *   4. финальный DONE-лог с метриками.
     */
    private function persistCompany(?string $companyName, int $tier, array $emails, array $phones): void
    {
        $emails = $this->filterEmails(array_values(array_unique(array_filter($emails))));
        $phones = array_values(array_unique(array_filter($phones)));

        $existed = Company::where('url', $this->url)->exists();
        Company::updateOrCreate(
            ['url' => $this->url],
            [
                'name'            => $companyName,
                'emails'          => $emails,
                'phones'          => $phones,
                'tier'            => $tier,
                'search_query_id' => $this->searchQueryId,
            ]
        );

        $this->log('  ' . ($existed ? 'UPDATED' : 'INSERTED')
            . ' tier=' . $tier
            . ' emails=' . count($emails) . (empty($emails) ? '' : ' ' . json_encode($emails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . ' phones=' . count($phones) . (empty($phones) ? '' : ' ' . json_encode($phones, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
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
            // Один запрос, без retry — это критично для скорости обхода.
            // На несуществующем path сервер обычно мгновенно отвечает 404/500
            // с маленьким body, который extract'ы возвращают пустыми, и мы
            // двигаемся дальше. Включение retry(...) добавляло 1.5-3 сек
            // на каждом таком path и растягивало обход на минуты.
            $response = Http::timeout(self::FETCH_TIMEOUT)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'text/html',
                ])
                ->get($url);

            return $this->encoder->toUtf8($response->body());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Скачать страницу по url и подмешать найденные email/phone к уже накопленным.
     *
     * Используется в циклах CONTACT_PATHS и menu-links — там одна и та же
     * связка fetch → extractEmails + extractPhones → array_merge встречается
     * повторно. Если fetch вернул null (404 / сетевая ошибка) — возвращаем
     * пары без изменений.
     *
     * @return array{0: list<string>, 1: list<string>} Обновлённые [emails, phones].
     */
    private function collectContactsFrom(string $url, array $emails, array $phones): array
    {
        $pageHtml = $this->fetch($url);
        if (!$pageHtml) {
            return [$emails, $phones];
        }

        return [
            array_merge($emails, $this->extractEmails($pageHtml)),
            array_merge($phones, $this->extractPhones($pageHtml)),
        ];
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
        return $this->cleanEmailCandidates(array_merge(
            $this->extractDirect($html),
            $this->extractObfuscated($html),
            $this->extractJsonAttr($html),
            $this->decodeCfEmails($html),
            $this->extractMailtoEmails($html),
            $this->extractJsConcatEmails($html),
        ));
    }

    /**
     * Проход 1 — прямой regex по сырому HTML.
     *
     * Ловит email, написанный «в открытую» в тексте, в href, в коде. Базовый
     * EMAIL_REGEX содержит lookahead `(?![a-zA-Z])`, который защищает от склеек
     * domain+text после strip_tags соседних блоков.
     */
    private function extractDirect(string $html): array
    {
        preg_match_all(self::EMAIL_REGEX, $html, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Проход 2 — нормализация типичных обфускаций и повторный regex.
     *
     * Что нормализуем перед поиском:
     *   - визуальные псевдо-@ (`∂`, `[at]`, `(at)`, `[@]`, `(@)`, `&#64;`, `&commat;`) → `@`;
     *   - теги заменяем ПРОБЕЛОМ (а не пустой строкой), иначе `strip_tags` склеит
     *     соседние ячейки таблиц/абзацев, и получим мусор вида
     *     "telefonverkaufs.ernst@..." (склейка имени и username соседнего mailto);
     *   - схлопываем пробелы вокруг `@` для кейсов вида "support @ example.com",
     *     которые иначе не матчатся базовым regex'ом.
     */
    private function extractObfuscated(string $html): array
    {
        $normalized = str_replace(
            ['∂', '&#64;', '&#0064;', '&commat;', '[at]', '(at)', '[@]', '(@)', ' [at] ', ' (at) '],
            '@',
            $html
        );
        $normalized = preg_replace('/<[^>]+>/', ' ', $normalized);
        $normalized = preg_replace('/([\w._%+-])\s*@\s*([\w.-])/u', '$1@$2', $normalized);

        preg_match_all(self::EMAIL_REGEX, $normalized, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Проход 3 — извлечение из data-email JSON-атрибутов.
     *
     * CMS типа JTL / Shopware / Joomla кладут адрес в атрибут вида
     * `data-email='{"name":"info","host":"verdie-gmbh.de"}'` и склеивают
     * через JS на клиенте. Мы декодируем содержимое атрибута (html-entities)
     * и ловим пару name/host через EMAIL_USER + EMAIL_DOMAIN.
     */
    private function extractJsonAttr(string $html): array
    {
        if (!preg_match_all('/data-email=["\']([^"\']+)["\']/i', $html, $attrMatches)) {
            return [];
        }

        $emails = [];
        foreach ($attrMatches[1] as $rawAttr) {
            $decoded = html_entity_decode($rawAttr);
            if (preg_match(
                '/"?name"?\s*:\s*"?(' . self::EMAIL_USER . ')"?[^}]*?"?host"?\s*:\s*"?(' . self::EMAIL_DOMAIN . ')/i',
                $decoded,
                $m
            )) {
                $emails[] = $m[1] . '@' . $m[2];
            }
        }

        return $emails;
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
        $regex = '/["\'](' . self::EMAIL_USER . ')["\']\s*\+\s*["\']@["\']\s*\+\s*["\'](' . self::EMAIL_DOMAIN . ')["\']/';

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
            if (preg_match(self::EMAIL_REGEX_ANCHORED, $email)) {
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
        $util = PhoneNumberUtil::getInstance();
        $region = $this->guessRegion();

        try {
            $obj = $util->parse($value, $region);
            if ($util->isValidNumber($obj)) {
                return $util->format($obj, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // fallback ниже
        }

        // Fallback на наивную нормализацию: бывает, что libphonenumber
        // отказывается парсить мусорные подстроки рядом с "Telefon:" из
        // strip_tags, но нам важен хотя бы цифровой каркас номера.
        $digits = preg_replace('/[^0-9+]/', '', $value);

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * Угадать регион по TLD домена для PhoneNumberUtil::parse.
     *
     * Если в тексте номер без +country-prefix (типичный кейс DACH-сайтов:
     * "Telefon: 030 1234567"), libphonenumber требует region-hint, чтобы
     * понять country code. Берём подсказку из TLD домена самой компании.
     *
     * Для непокрытых TLD (например, .com, .eu) возвращаем DE — у нашего
     * ICP это самый частый регион среди DACH.
     */
    private function guessRegion(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?? '';
        $tld = strtolower((string) substr(strrchr($host, '.') ?: '', 1));

        return match ($tld) {
            'at' => 'AT',
            'ch' => 'CH',
            'fr' => 'FR',
            'be' => 'BE',
            'lu' => 'LU',
            'nl' => 'NL',
            'it' => 'IT',
            'es' => 'ES',
            'pt' => 'PT',
            'pl' => 'PL',
            'cz' => 'CZ',
            'sk' => 'SK',
            'hu' => 'HU',
            'dk' => 'DK',
            'se' => 'SE',
            'no' => 'NO',
            'fi' => 'FI',
            'uk', 'gb', 'ie' => 'GB',
            default => 'DE',
        };
    }

    /**
     * STEP D:
     * извлечение title страницы.
     *
     * Возвращает уже очищенную строку (без HTML-тегов и без html-entity)
     * — так её можно одновременно положить в БД как fallback-имя компании
     * и передать в isOffTopic() для проверки маркеров не-производителей,
     * без повторного парсинга <title> на одном HTML.
     *
     * Флаг `s` нужен для многострочного title (DOTALL).
     */
    private function extractTitle(string $html): ?string
    {
        if (!preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($m[1])));

        return $title === '' ? null : $title;
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
     * Принимает уже очищенный title (см. extractTitle) — чтобы не парсить
     * <title> на одном HTML дважды. Если title пустой / null — возвращаем
     * false (cautious default: лучше пропустить шум, чем потерять реального
     * производителя).
     */
    private function isOffTopic(?string $title): bool
    {
        if ($title === null || $title === '') {
            return false;
        }

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
