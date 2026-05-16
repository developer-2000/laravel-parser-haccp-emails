# PHPStan / Larastan

Статический анализатор для Laravel. Ловит то, что обычно ловит только тест в проде:
вызовы методов на `null`, несуществующие свойства, неверные сигнатуры, мёртвый код,
несовпадающие типы в коллекциях и т.п.

---

## Установка

```bash
composer require --dev larastan/larastan --ignore-platform-reqs
```

---

## Конфиг

Файл: [phpstan.neon](phpstan.neon)

- `level: 5` — стартовый, безопасный для существующего кода. Уровни от 0 (мягкий)
  до 9 (строгий). Поднимать постепенно: 5 → 6 → 7. Сразу 9 на старом проекте даёт
  сотни ошибок.
- `paths` — что анализируем. `app` и `routes` достаточно для типовой Laravel-кодбазы.
- `excludePaths` — кэш и сторадж пропускаем.
- `ignoreErrors.identifier: missingType.iterableValue` — отключает требование явных
  дженериков на `array`/`Collection`. Без этого Larastan ругается почти везде, где
  есть `array $x` без `array<int, string>`. (Раньше это была опция
  `checkMissingIterableValueType: false`, но с PHPStan 1.12 она deprecated.)
- `reportUnmatchedIgnoredErrors: false` — не считать ошибкой ситуацию, когда правило
  из `ignoreErrors` не сматчилось ни с одной реальной ошибкой. По умолчанию PHPStan
  ругается «зачем игнор, если игнорить нечего?» — нам это мешает держать
  превентивные игноры вроде `missingType.iterableValue`.

---

## Запуск

### Через composer-скрипт (рекомендуется)

В `composer.json` есть скрипт `stan`:

```bash
composer stan
```

Эквивалентно `phpstan analyse --memory-limit=1G`.

### Напрямую

```bash
vendor/bin/phpstan analyse
```

### С увеличенной памятью (если упало по OOM)

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

### Проверка одного файла

```bash
vendor/bin/phpstan analyse app/Services/BackupService.php
```

### Версия

```bash
vendor/bin/phpstan --version
```

---

## Как читать вывод

Пример:

```txt
 ------ -------------------------------------------------
  Line   UserService.php
 ------ -------------------------------------------------
  15     Cannot call method save() on App\Models\User|null
 ------ -------------------------------------------------
```

Значит: `$user` может быть `null`, а ты вызываешь `save()` без проверки.

Правильно:

```php
$user = $request->user();
if (!$user) {
    return;
}
$user->save();
```

Или короткая форма для цепочек:

```php
$post->author?->name
```

---

## Частые ошибки в Laravel

### `Request::user()` может быть null

`Auth` middleware ещё не отработал — для PHPStan тип `User|null`.

```php
$id = $request->user()->id;          // ругается
$id = $request->user()?->id;         // ok, но даёт null|int
```

### Eloquent-связи

```php
$post->author->name                  // author может быть null
$post->author?->name                 // ok
```

### Дженерики коллекций

Larastan не угадывает тип элементов:

```php
/** @var \Illuminate\Support\Collection<int, \App\Models\User> $users */
$users = User::all();
```

---

## Повышение уровня

Открыть `phpstan.neon`, заменить:

```neon
level: 5
```

на `6`, прогнать, починить ошибки, поднять до `7`, и т.д. Уровни:

- 0–2: базовые синтаксические/типовые проверки.
- 3–4: возвращаемые типы, доступ к свойствам.
- 5–6: типы аргументов, частичный analysis массивов.
- 7: nullable-обработка.
- 8: жёсткие проверки `null`.
- 9: типы `mixed` запрещены.

---

## CI/CD

Пример для GitHub Actions:

```yaml
- name: PHPStan
  run: vendor/bin/phpstan analyse --memory-limit=1G
```

Падение анализа должно блокировать merge — это ключевой смысл инструмента.
