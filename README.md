# parser-emails

## Быстрый старт

Подробные команды Docker:
[documents/comands_docker.md](documents/comands_docker.md)

Подробное описание архитектуры:
[documents/installProject.md](documents/installProject.md)

Поднять окружение:

```bash
docker compose -f dev-compose.yml build --no-cache app
docker compose -f dev-compose.yml up -d

docker compose -f dev-compose.yml down

docker compose -f dev-compose.yml exec app npm run dev
docker compose -f dev-compose.yml exec app npm run build

docker compose -f dev-compose.yml exec app php artisan migrate:fresh --seed
docker compose -f dev-compose.yml exec app php artisan migrate

docker compose -f dev-compose.yml restart queue
```

docker compose -f dev-compose.yml ps
docker compose -f dev-compose.yml exec queue php artisan horizon:status

## После изменений в Job
# 1. Применить новый settings.yml — searxng читает конфиг только при старте
docker compose -f dev-compose.yml restart searxng
# 2. Перезапустить queue-worker — Horizon держит PHP-классы (SearchJob, SearxClient)
#    в памяти, без рестарта мои правки кода не применятся
docker compose -f dev-compose.yml restart queue
 - Рестарт Horizon (после деплоя джобов)
docker compose -f dev-compose.yml exec redis redis-cli FLUSHALL
 - Полная очистка Redis
docker compose -f dev-compose.yml exec app php artisan tinker
app(\App\Services\QueryBuilderService::class)->searchQuery(1);


Открыть в браузере:
- [Главная Laravel](http://localhost:8080)
- [phpMyAdmin](http://localhost:8081)
- [UI очередей Horizon](http://localhost:8080/horizon)

## Стек

- **Laravel 10**
    - Основной фреймворк
- **Laravel Horizon**
    - UI и process manager очередей
- **PHP-FPM 8.2**
    - Unix-socket
    - OPcache
    - `max_execution_time = 300`
- **PHP `redis` extension (phpredis через PECL)**
    - Требование Horizon
- **Supervisor**
    - Master-процесс контейнера `app`
    - Держит nginx + php-fpm с автоперезапуском
- **Nginx**
- **MySQL 8 (tuned)**
    - `innodb_buffer_pool_size = 1G`
    - `innodb_log_buffer_size = 64M`
    - `max_connections = 200`
- **Redis (alpine)**
    - `--appendonly yes` для persistence очередей
- **Node.js 20 LTS**
    - Поставлен из NodeSource
- **Composer 2**
    - Auto-install через entrypoint
    - Флаги: `--prefer-dist --optimize-autoloader`

---

## Сервисы

- **`app`**
    - HTTP (nginx + php-fpm под supervisord)
    - Порт: `8080:80`
    - Healthcheck: `curl -f http://localhost`
- **`queue`**
    - Команда: `php artisan horizon`
    - Управляет воркерами 5 очередей
- **`scheduler`**
    - Команда: `php artisan schedule:work`
    - Эквивалент cron'а Laravel внутри контейнера
- **`db`**
    - MySQL 8
    - Healthcheck: `mysqladmin ping`
- **`redis`**
    - Очередь и кэш с persistence через AOF
    - Healthcheck: `redis-cli ping`
- **`phpmyadmin`**
    - Web-UI для MySQL
    - Порт: `8081:80`
    - Авто-логин под `root` / `root` через `PMA_USER` + `PMA_PASSWORD`

Все сервисы — `restart: unless-stopped`.

У `app`, `queue`, `scheduler` подмена DNS на `8.8.8.8 / 8.8.4.4` для ускорения резолвинга в краулере.

---

## Horizon — UI очередей

URL:

```
http://localhost:8080/horizon
```

Что показывает:

- статус всех supervisor'ов
- активные / проваленные / повторённые джобы
- метрики throughput и runtime
- retry упавших джобов

Доступ:

- только в `local` env
- контролируется в `App\Providers\HorizonServiceProvider::gate()`

Конфиг — [config/horizon.php](config/horizon.php), секция `environments.local`:

- `search × 2`
- `crawl × 3`
- `classify × 2`
- `enrich × 2`
- `save × 1`

Итого 10 воркеров. Изменил конфиг — выполни `restart queue` либо `horizon:terminate`.

---

## phpMyAdmin — web-UI для MySQL

URL:

```
http://localhost:8081
```

Авто-вход:

- хост: `db` (имя сервиса compose)
- пользователь: `root`
- пароль: `root`

Параметры подключения заданы через переменные окружения контейнера:

- `PMA_HOST=db`
- `PMA_USER=root`
- `PMA_PASSWORD=root`
- `UPLOAD_LIMIT=256M`

Образ — `phpmyadmin:latest` (на момент установки 5.2.3).

---

---

## Команды

### Жизненный цикл

- **`docker compose -f dev-compose.yml build`**
    - Собрать образы
- **`docker compose -f dev-compose.yml up -d`**
    - Поднять стек в фоне
- **`docker compose -f dev-compose.yml up -d --build`**
    - Пересобрать и поднять (после правки `Dockerfile` или `docker/**`)
- **`docker compose -f dev-compose.yml ps`**
    - Статус сервисов
- **`docker compose -f dev-compose.yml down`**
    - Остановить и удалить контейнеры
    - Тома сохраняются
- **`docker compose -f dev-compose.yml down -v`**
    - То же + удалить тома
    - Полный сброс окружения

### Laravel

- **`docker compose -f dev-compose.yml exec app php artisan migrate`**
    - Применить миграции
- **`docker compose -f dev-compose.yml exec app php artisan tinker`**
    - REPL для Laravel
- **`docker compose -f dev-compose.yml exec app composer install`**
    - Доустановить зависимости вручную
- **`docker compose -f dev-compose.yml exec app bash`**
    - Shell внутри контейнера

### Очереди и scheduler

- **`docker compose -f dev-compose.yml logs -f queue`**
    - Лог Horizon
- **`docker compose -f dev-compose.yml logs -f scheduler`**
    - Лог cron-сервиса
- **`docker compose -f dev-compose.yml restart queue`**
    - Рестарт Horizon (после деплоя джобов)
- **`docker compose -f dev-compose.yml up -d --scale queue=3`**
    - Поднять N идентичных Horizon-контейнеров

Управление Horizon:

- **`php artisan horizon:list`**
    - Список активных supervisor'ов и воркеров
- **`php artisan horizon:status`**
    - Статус: `running` / `paused` / `inactive`
- **`php artisan horizon:pause`**
    - Пауза приёма новых джобов
- **`php artisan horizon:continue`**
    - Возобновить после pause
- **`php artisan horizon:terminate`**
    - Мягкая остановка
    - Текущие джобы доделываются
    - Контейнер перезапускается через `restart: unless-stopped`

Запуск через docker:

```bash
docker compose -f dev-compose.yml exec queue php artisan horizon:list
docker compose -f dev-compose.yml exec queue php artisan horizon:status
docker compose -f dev-compose.yml exec queue php artisan horizon:pause
docker compose -f dev-compose.yml exec queue php artisan horizon:continue
docker compose -f dev-compose.yml exec queue php artisan horizon:terminate
```

### Redis

- **`docker compose -f dev-compose.yml exec redis redis-cli ping`**
    - Проверка живости
- **`docker compose -f dev-compose.yml exec redis redis-cli KEYS "queues:*"`**
    - Посмотреть очереди
- **`docker compose -f dev-compose.yml exec redis redis-cli FLUSHALL`**
    - Полная очистка Redis

### Логи

- **`docker compose -f dev-compose.yml logs -f app`**
    - Лог nginx + php-fpm
- **`docker compose -f dev-compose.yml logs -f db`**
    - Лог MySQL

---

## `.env`

```env
DB_HOST=db
DB_DATABASE=parser_haccp
DB_USERNAME=root
DB_PASSWORD=root
REDIS_HOST=redis
QUEUE_CONNECTION=redis
```

Замечания:

- `DB_HOST=db` и `REDIS_HOST=redis` — это **имена сервисов compose**, не `127.0.0.1`
- `QUEUE_CONNECTION=redis` обязательно для работы Horizon

---

## Pipeline парсера

```
[ search ] → [ crawl ] → [ classify ] → [ enrich ] → [ save ]
```

Поведение:

- очереди обрабатываются **параллельно**
- у каждой очереди свой Horizon-supervisor
- число воркеров на каждую очередь — из [config/horizon.php](config/horizon.php)

Масштабирование:

- внутри одного контейнера — увеличить `maxProcesses` в `config/horizon.php`
- горизонтально — `docker compose -f dev-compose.yml up -d --scale queue=N`

---

## Как работают очереди

### Картина целиком

```
.env                     QUEUE_CONNECTION=redis
   ↓
Redis (контейнер redis)  хранит сами джобы по ключам queues:search, queues:crawl, ...
   ↑
Horizon (контейнер queue)  читает Redis, держит N воркеров на каждую очередь, перезапускает упавшие
   ↑
Твой код                 SearchJob::dispatch(...)->onQueue('search') кладёт джобу в Redis
```

Главное:

- **Воркеры всегда крутятся** (контейнер `queue` запускается с `php artisan horizon`). Самому `queue:work` дёргать не нужно никогда.
- **Dispatch = постановка на выполнение.** Как только `dispatch` отработал — джоба в Redis, воркер её подхватит сам в течение долей секунды. Дополнительной "команды на запуск" не существует.
- **Горизонт держит код в памяти.** Любая правка в `app/Jobs/*`, `app/Services/*`, `app/Models/*` требует `docker compose -f dev-compose.yml restart queue`. Иначе воркер выполнит старый код.

### Точка входа в пайплайн

Артизан-команды для старта **нет**. Точка входа — метод сервиса:

```php
app(\App\Services\QueryBuilderService::class)->searchQuery($querySetId);
```

Что он делает:

1. Собирает строку запроса по `QuerySet` (`query_ids` + `group_ids` + `exclude_ids`).
2. Диспатчит `SearchJob` в очередь `search` с готовой строкой.
3. Возвращает строку запроса (для UI-превью).

Дальше Horizon крутит цепочку сам:

```
QueryBuilderService::searchQuery($id)
        ↓ dispatch
[ search ]  SearchJob   → парсит выдачу Google
                        → CrawlJob::dispatch на каждую новую ссылку
                        → self::dispatch для следующей страницы (до countPage)
        ↓ dispatch
[ crawl  ]  CrawlJob    → обходит один сайт
        ↓
[ classify ] [ enrich ] [ save ]   ← добавляются по мере реализации
```

### Способы дёрнуть точку входа

**Tinker (для проверки руками):**

```bash
docker compose -f dev-compose.yml exec app php artisan tinker
```

```php
app(\App\Services\QueryBuilderService::class)->searchQuery(1);
```

**Через HTTP (для UI / Postman):** добавить роут в [routes/web.php](routes/web.php) или [routes/api.php](routes/api.php), вызвать `searchQuery($id)` в контроллере, вернуть строку запроса в ответе.

### Как посмотреть, что в очереди

**Horizon UI** — [http://localhost:8080/horizon/dashboard](http://localhost:8080/horizon/dashboard):

- Блок **Current Workload** — таблица с колонкой `Jobs` (= pending) и `Processes` (= сколько воркеров жрут эту очередь).
- Меню **Pending Jobs** — список ждущих в очереди.
- Меню **Failed Jobs** — упавшие. **Сюда смотри в первую очередь, если "стоит и не выполняется".**

**Redis напрямую** (быстрее всего, не врёт):

```bash
docker compose -f dev-compose.yml exec redis redis-cli LLEN queues:search
docker compose -f dev-compose.yml exec redis redis-cli LLEN queues:crawl
docker compose -f dev-compose.yml exec redis redis-cli --scan --pattern "queues:*"
```

`LLEN` = сколько джобов лежит в очереди и ждёт. Если `0`, а в Horizon UI на этой очереди тоже `Jobs: 0` — джоба либо уже отработана, либо вообще не была положена (см. ниже).

**CLI Horizon:**

```bash
docker compose -f dev-compose.yml exec queue php artisan horizon:status   # running / paused / inactive
docker compose -f dev-compose.yml exec queue php artisan horizon:list     # все supervisor'ы и воркеры
```

### Если джоба "стоит и не выполняется"

Идти по этому чек-листу сверху вниз — почти всегда падает на одном из шагов:

**1. Джоба вообще попала в Redis?**

```bash
docker compose -f dev-compose.yml exec redis redis-cli LLEN queues:search
```

Если `0` сразу после `dispatch` — `QUEUE_CONNECTION` не `redis`. Проверь:

```php
// в tinker
config('queue.default');   // должно вернуть "redis"
```

Если вернуло `sync` — конфиг закэширован старый:

```bash
docker compose -f dev-compose.yml exec app php artisan config:clear
```

**2. Воркер этой очереди жив?**

В Horizon UI → Current Workload → у `search` должно быть `Processes ≥ 1`. Если `0` — Horizon упал/перезагружается:

```bash
docker compose -f dev-compose.yml logs --tail=100 queue
docker compose -f dev-compose.yml restart queue
```

**3. Джоба падает с exception?**

Horizon UI → **Failed Jobs**. Открой запись — увидишь stack trace. Самые частые причины:

- `Base table or view not found: 'companies'` — забыл накатить миграцию:
  ```bash
  docker compose -f dev-compose.yml exec app php artisan migrate
  ```
- `Class App\Jobs\SearchJob not found` или другой class-not-found — воркер держит старый код. Рестарт:
  ```bash
  docker compose -f dev-compose.yml restart queue
  ```
- Сетевые ошибки на Google — это норма, сработает retry (`tries = 3` в [config/horizon.php](config/horizon.php)).

**4. Воркер видит, но не подхватывает?**

Очень редкий случай — обычно несовпадение имени очереди в `dispatch(...)->onQueue('X')` и в `config/horizon.php`. Очереди должны совпадать буква-в-букву: `search`, `crawl`, `classify`, `enrich`, `save`.

### Полный сброс

Если очереди забились мусором и хочется начать с нуля:

```bash
docker compose -f dev-compose.yml exec redis redis-cli FLUSHALL
docker compose -f dev-compose.yml restart queue
```

`FLUSHALL` сносит всё в Redis: pending-джобы, failed-джобы, метрики Horizon. Для dev это нормально, на проде так не делать.

### TL;DR

| Хочу                          | Делаю                                                                  |
|-------------------------------|------------------------------------------------------------------------|
| Запустить парсинг             | `app(QueryBuilderService::class)->searchQuery($id)` (tinker / роут)    |
| Увидеть сколько в очереди     | Horizon UI → Current Workload, либо `redis-cli LLEN queues:search`     |
| Понять почему джоба не идёт   | Horizon UI → Failed Jobs                                               |
| Применил миграцию / правил код| `docker compose -f dev-compose.yml restart queue`                      |
| Сбросить всё                  | `redis-cli FLUSHALL` + рестарт `queue`                                 |
