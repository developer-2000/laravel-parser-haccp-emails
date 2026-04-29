# План улучшения сбора email с уже найденных доменов

## Цель

Поднять покрытие email с текущих ~20–40% сайтов до ~50–70% **без расширения краулера** и без новых сервисов. Только улучшения внутри `CrawlJob` и его helper-методов.

---

## Что уже есть (отправная точка)

В `app/Jobs/CrawlJob.php`:

- Скачивание главной → `extractEmails($html)` (3 прохода: raw, normalized с заменой обфускаций, JSON `data-email`).
- `resolvePage()` строит только два пути: `/impressum/` и `/kontakt/`.
- `extractMenuContactLinks()` ищет ссылки в меню по тексту: `Kontakt / Contact / Impressum / Anfahrt / Standort`.
- `filterEmails()` чистит мусор: placeholder usernames, image-файлы (`logo@2x.png`), tracking-домены (sentry/wixpress/google), длинный hex-username.
- `extractFooter()` уже есть (используется в `nameFromCopyright`) — можно переиспользовать.

---

## Этапы по приоритету (TOP-1 — самый большой ROI)

### Этап 1. Cloudflare email decoding `[ROI: +20–40% на CF-сайтах]`

**Зачем:** CF protected сайты заменяют все email на `<span data-cfemail="6e07000801..."></span>`. Сейчас мы их полностью теряем.

**Что сделать:**
1. Добавить приватный метод `decodeCfEmails(string $html): array`:
    - Regex `/data-cfemail=["\']([a-f0-9]+)["\']/i` по всему HTML.
    - Для каждого hit — декодер XOR:
      ```php
      $r = hexdec(substr($encoded, 0, 2));
      $email = '';
      for ($n = 2; $n < strlen($encoded); $n += 2) {
          $email .= chr(hexdec(substr($encoded, $n, 2)) ^ $r);
      }
      ```
    - Вернуть массив декодированных email.
2. Вызывать его рядом с `extractEmails()` для каждого fetch'нутого HTML (главная, contact-страницы, footer, меню-ссылки) и мерджить результат в общий пул `$emails`.

**Acceptance:** на тестовом WP-сайте с `data-cfemail` извлечённый email совпадает с ожидаемым (можно проверить декодером онлайн).

---

### Этап 2. Расширить набор contact-страниц `[ROI: +15–30%]`

**Зачем:** сейчас обходим только 2 пути; на немецких сайтах email чаще лежат в `/datenschutz/`, `/agb/`, `/team/`, `/ueber-uns/`.

**Что сделать:**
1. Удалить `resolvePage()` (он жёстко знает только impressum/kontakt) или превратить в построитель с массивом slug'ов.
2. Ввести приватное свойство-константу:
   ```php
   private const CONTACT_PATHS = [
       'impressum', 'kontakt', 'contact', 'contacts',
       'about', 'ueber-uns', 'uber-uns', 'team',
       'company', 'unternehmen',
       'legal', 'privacy', 'datenschutz', 'agb',
   ];
   ```
3. В `handle()` заменить два явных fetch (impressum + contact) на цикл:
   ```php
   foreach (self::CONTACT_PATHS as $path) {
       $pageHtml = $this->fetch(rtrim($this->url, '/') . '/' . $path . '/');
       if (!$pageHtml) continue;
       $emails = array_merge($emails, $this->extractEmails($pageHtml));
       $emails = array_merge($emails, $this->decodeCfEmails($pageHtml));
       $emails = array_merge($emails, $this->extractMailtoEmails($pageHtml));
       $phones = array_merge($phones, $this->extractPhones($pageHtml));
   }
   ```
4. Trailing slash оставить — по опыту на WP/strict permalinks без слеша 403/404.

**Acceptance:** на 3–5 ручных примерах из БД проверить, что хотя бы для одного домена дополнительно нашёлся email на `/team/` или `/datenschutz/`, который не появлялся раньше.

---

### Этап 3. Mailto extraction `[ROI: +10–20%]`

**Зачем:** часть email хранится только в `<a href="mailto:...">`, обычный regex по тексту HTML их не всегда поднимает (например, ссылка без видимого текста, или текст — это иконка).

**Что сделать:**
1. Добавить метод `extractMailtoEmails(string $html): array`:
    - Regex `/mailto:([^"\'>?\s]+)/i` (без query-string и пробелов).
    - Каждое совпадение прогнать через `urldecode()` и `html_entity_decode()`.
    - Отбросить если не подходит под базовую форму email.
2. Вызывать сразу после `extractEmails()` в каждом месте, где уже есть `extractEmails`. Мерджить в общий пул.

**Acceptance:** на сайте с защищённым email (только в `mailto:`) — извлекается.

---

### Этап 4. Footer extraction отдельно `[ROI: +5–15%]`

**Зачем:** в footer'е плотность email высокая, мусор/JS/tracking ниже. Разовый прогон даёт чистые контакты.

**Что сделать:**
1. Использовать существующий `extractFooter($homeHtml)` (уже есть).
2. После шага fetch главной, если `extractFooter` вернул блок:
   ```php
   $footer = $this->extractFooter($html);
   if ($footer) {
       $emails = array_merge($emails, $this->extractEmails($footer));
       $emails = array_merge($emails, $this->decodeCfEmails($footer));
       $emails = array_merge($emails, $this->extractMailtoEmails($footer));
   }
   ```
3. То же сделать для footer'ов impressum/contact страниц (если есть смысл; на их страницах footer обычно тот же — повтор не страшен, дедуп их свернёт).

**Acceptance:** на сайте, где email есть только в подвале, он попадает в результат.

---

### Этап 5. JS-конкатенация email `[ROI: небольшой, но почти бесплатный]`

**Зачем:** часть сайтов прячет email в JS:
```js
var email = "info" + "@" + "company.de";
```

**Что сделать:**
1. Метод `extractJsConcatEmails(string $html): array`:
    - Regex: `/"([a-zA-Z0-9._%+-]+)"\s*\+\s*"@"\s*\+\s*"([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})"/`
    - Склейка `$m[1] . '@' . $m[2]`.
2. Вызывать рядом с `decodeCfEmails`/`extractMailtoEmails` для каждого fetch'нутого HTML.

**Acceptance:** unit-проверка на синтетическом примере: строка с такой склейкой → email извлечён.

---

### Этап 6. Расширить keywords меню `[ROI: +5–15%]`

**Зачем:** найти ссылки на нестандартные контактные страницы (`/support/`, `/sales/`, `/vertrieb/`).

**Что сделать:**
1. В `extractMenuContactLinks()` к существующему списку keyword'ов:
   ```
   kontakt, contact, impressum, anfahrt, standort
   ```
   добавить:
   ```
   support, service, kundenservice, customer-service, customer-care,
   sales, vertrieb, office, help,
   team, ueber-uns, uber-uns, about, company, unternehmen
   ```
2. Лимит на 5 ссылок — оставить (защита от sites где «Kontakt» в каждом блоке).

**Acceptance:** в логе `crawl.log` появляются переходы на новые URL вроде `/support/`, `/team/`.

---

### Этап 7. Нормализация и ранняя фильтрация `[чистота результата, не количество]`

**Что сделать:**
1. Перед `array_unique` нормализовать email: `strtolower(trim($email))`. Это схлопнет `INFO@SITE.DE` и `info@site.de` в одну запись.
2. Перенести фильтр image/css/js файлов из `filterEmails` в `extractEmails` (сразу после regex-ловли) — чтобы мусор не тащился через mailto/cfemail/JS-методы. Тот же фильтр применить в `extractMailtoEmails` и `decodeCfEmails`.
3. Tracking-домены (sentry, wixpress, google-analytics, googletagmanager, hotjar, hubspot) — оставить в `filterEmails` как финальную сетку.

**Acceptance:** в БД больше нет `INFO@…` дублей с `info@…`; нет email с расширениями `.png/.jpg/.css/.js`.

---

### Этап 8. Visible text pass `[опционально, ROI: малый]`

**Зачем:** часть email написана в виде текста с обходом тегов. У нас уже есть «Проход 2» в `extractEmails`, который делает strip_tags. Нужно убедиться, что он действительно гоняется на каждом fetch'нутом HTML, а не теряется.

**Что сделать:** ревью существующего `extractEmails` после правок этапов 1–7 — убедиться, что строка `strip_tags($html)` стоит до regex и не сломалась рефакторингом.

---

## Что НЕ делаем

- ❌ Selenium / Playwright для крауля сайтов (только DDG).
- ❌ JS rendering, OCR, AI/ML классификация.
- ❌ Перебор поддоменов, sitemap.xml, robots.txt парсинг.
- ❌ Linked / соцсети.
- ❌ Усложнение пайплайна (микросервисы, очереди enrichment'а).
- ❌ Idle perfection: edge-case'ы вида JSON-LD `mailto`-в-Schema.org, vCard, `application/ld+json` с emails.

---

## Порядок реализации

Делаем по этапам **сверху вниз**, по одному. После каждого этапа проверяем `crawl.log` и БД на 2–3 живых доменах:
1. Этап 1 — Cloudflare decoder.
2. Этап 2 — расширенный набор contact-страниц.
3. Этап 3 — mailto extraction.
4. Этап 4 — footer extraction отдельно.
5. Этап 5 — JS concat extraction.
6. Этап 6 — расширенные keywords меню.
7. Этап 7 — нормализация + ранняя фильтрация мусора.
8. Этап 8 — ревью visible text pass.

После всех этапов — short smoke test: пересобрать БД, прогнать пару запросов, сверить email-coverage до/после.
