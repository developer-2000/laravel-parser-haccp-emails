# Архитектура проекта `parser-emails`

Dev-окружение Laravel 10 в Docker под распределённый парсер писем/лидов.

## Структура

```
parser-emails/
├── Dockerfile                          базовый образ для app/queue/scheduler
├── dev-compose.yml                     5 сервисов
├── .dockerignore                       vendor, node_modules, .git, .env
├── .env                                DB/Redis/Queue настройки Laravel
├── config/horizon.php                  5 supervisor'ов на 5 очередей
└── docker/
    ├── entrypoint.sh                   composer/npm install → exec supervisord
    ├── nginx/default.conf              vhost, fastcgi через unix-сокет
    ├── php/
    │   ├── php.ini                     memory_limit, opcache, max_execution_time
    │   └── www.conf                    пул php-fpm, права на сокет
    └── supervisor/supervisord.conf     master-процесс контейнера app (nginx + php-fpm)
```

## Сервисы

| Сервис | Образ | Команда | Healthcheck |
|---|---|---|---|
| `app` | `parser-emails-app` (build) | entrypoint.sh → `supervisord` (nginx + php-fpm) | `curl -f http://localhost` |
| `queue` | `parser-emails-queue` (build) | `php artisan horizon` (управляет воркерами 5 очередей) | — |
| `scheduler` | `parser-emails-scheduler` (build) | `php artisan schedule:work` | — |
| `db` | `mysql:8` | `--innodb_buffer_pool_size=1G --innodb_log_buffer_size=64M --max_connections=200` | `mysqladmin ping` |
| `redis` | `redis:alpine` | `redis-server --appendonly yes` | `redis-cli ping` |
| `phpmyadmin` | `phpmyadmin:latest` | — (стандартный entrypoint) | — (порт `8081:80`) |

Все: `restart: unless-stopped`. `app/queue/scheduler` — `dns: 8.8.8.8, 8.8.4.4` (ускорение Guzzle-резолва в крауле).

## Тома

| Том | Что хранит |
|---|---|
| `app_vendor` | `/var/www/html/vendor` (общий для app/queue/scheduler) |
| `db_data` | `/var/lib/mysql` |
| `redis_data` | `/data` (AOF persistence очередей) |

Bind-mount `.:/var/www/html` — исходники во все три PHP-сервиса.

## Где что настраивается

| Настройка | Файл | Значение |
|---|---|---|
| Базовый PHP | `Dockerfile` | `php:8.2-fpm` + `pdo_mysql, mbstring, zip, exif, pcntl` |
| PHP Redis ext (для Horizon) | `Dockerfile` | `pecl install redis && docker-php-ext-enable redis` |
| Node.js | `Dockerfile` | NodeSource Node 20 LTS |
| Supervisor (системный) | `Dockerfile` | apt пакет `supervisor` |
| Memory / execution time | `docker/php/php.ini` | `memory_limit=512M`, `max_execution_time=300` |
| OPcache | `docker/php/php.ini` | `enable=1`, `memory=128M`, 8000 файлов, `revalidate_freq=10` |
| PHP-FPM пул | `docker/php/www.conf` | `pm=static`, `pm.max_children=50` |
| Сокет fpm | `docker/php/www.conf` | `/var/run/php/php-fpm.sock`, `listen.owner=www-data`, `listen.mode=0660` |
| Nginx vhost | `docker/nginx/default.conf` | `:80`, root `/var/www/html/public`, fastcgi через unix-сокет, `error_log warn` |
| Master-процесс app | `docker/supervisor/supervisord.conf` | `php-fpm` (priority=10) + `nginx` (priority=20), autorestart, логи в `storage/logs/` |
| Auto-install зависимостей | `docker/entrypoint.sh` | `composer install --prefer-dist --optimize-autoloader`, `npm install`, `set -e` |
| Запуск контейнера app | `docker/entrypoint.sh` | финальное `exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf` |
| MySQL tuning | `dev-compose.yml` (db.command) | buffer 1G, log buffer 64M, 200 connections |
| MySQL пароль | `dev-compose.yml` + `.env` | `root` / `root` (для применения нужен `down -v`) |
| Redis persistence | `dev-compose.yml` (redis.command) | `--appendonly yes` |
| Очереди Laravel | `.env` | `QUEUE_CONNECTION=redis` |
| Horizon supervisors | `config/horizon.php` | по одному supervisor на каждую очередь, `balance=simple` |
| CPU/RAM лимит queue | `dev-compose.yml` (queue.deploy.resources) | `cpus: 2.0`, `memory: 1G` |
| Graceful shutdown queue | `dev-compose.yml` | `stop_signal: SIGTERM`, `stop_grace_period: 30s` |
| Healthcheck старт | `dev-compose.yml` (app) | `start_period: 90s` (под composer/npm install на первом запуске) |
| phpMyAdmin авто-логин | `dev-compose.yml` (phpmyadmin.environment) | `PMA_HOST=db`, `PMA_USER=root`, `PMA_PASSWORD=root`, `UPLOAD_LIMIT=256M` |

## Pipeline парсера

```
[ search ] → [ crawl ] → [ classify ] → [ enrich ] → [ save ]
```

Очереди обрабатываются параллельно — у каждой свой supervisor в Horizon с числом процессов из `config/horizon.php` (для `local` env: search×2, crawl×3, classify×2, enrich×2, save×1 = 10 воркеров).

Масштабирование:
- внутри одного контейнера queue — увеличить `maxProcesses` в `config/horizon.php`,
- горизонтально — `docker compose up -d --scale queue=N`.

## Horizon

UI: `http://localhost:8080/horizon` — статус supervisors, очередей, метрики, failed jobs, retry.

Доступ по умолчанию открыт только в `local` env через гейт в `app/Providers/HorizonServiceProvider.php` (`viewHorizon`).

Horizon заменил ручной `php artisan queue:work`: он сам форкает воркеров согласно `config/horizon.php`, перезапускает упавших, балансирует.

## Ключевые решения

### Vendor через именованный том

`vendor/` смонтирован отдельным томом `app_vendor` поверх bind-mount исходников. Хостовый `vendor/` для контейнера невидим. Сделано из-за медленного Windows-bind на десятках тысяч мелких файлов.

`composer install` запускается автоматически в `entrypoint.sh` при пустом томе.

### Права на сокет PHP-FPM

В `docker/php/www.conf` обязательны:

```ini
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
```

Без них master-процесс fpm (root) создаёт сокет с правами `root:root`, и nginx (от `www-data`) валится в **502 Bad Gateway** (`Permission denied`).

### LF-окончания в `entrypoint.sh`

На Windows файл должен быть сохранён с **LF**, не CRLF. Иначе bash в контейнере падает с `bad interpreter: No such file or directory`.

### `set -e` в entrypoint

Без него `composer install` или `npm install` могут упасть, а контейнер всё равно стартанёт (с пустым vendor). С `set -e` контейнер падает явно, проблема видна.

### Supervisord только в контейнере `app`

Supervisord нужен там, где в одном контейнере живут **связанные** процессы (nginx + php-fpm — общаются через unix-сокет). Если php-fpm упадёт — nginx не упадёт сам, но без supervisord контейнер останется живым с мёртвым php-fpm.

В `queue` и `scheduler` свой PID 1 — Laravel-команда (`horizon` / `schedule:work`), их перезапуском управляет Docker через `restart: unless-stopped`.

### Horizon вместо `queue:work` + supervisord

Horizon уже содержит свой process manager: внутри одного процесса `php artisan horizon` он форкает supervisor'ов на каждую очередь, далее каждый supervisor форкает воркеров. Дублировать это системным supervisord для очередей было бы нагромождением.

### `phpredis` extension (а не `predis`)

Horizon **требует** PHP-расширение `redis` (через PECL), не работает с `predis/predis` пакетом. Установлено в Dockerfile через `pecl install redis && docker-php-ext-enable redis`.

### DNS через compose, не через Dockerfile

`RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf` бесполезно — Docker перезаписывает `/etc/resolv.conf` при старте контейнера. Поэтому DNS задан через `dns:` в `dev-compose.yml`.

### `start_period: 90s` для healthcheck app

При первом старте `composer install` (1–3 мин) + `npm install` (1–2 мин) тянутся долго. До их завершения nginx не отвечает, и без `start_period` healthcheck закидывает контейнер в `unhealthy`.

### MySQL пароль и пересоздание тома

При смене с `MYSQL_ALLOW_EMPTY_PASSWORD` на `MYSQL_ROOT_PASSWORD=root` старый том `db_data` уже знал пустой пароль и продолжал бы его требовать. Для применения нужен `docker compose down -v` (потеря данных) — миграции потом перенакатываются.

## Code-level требования (не Docker)

| Требование | Где применяется | Почему |
|---|---|---|
| `usleep(500000)` или job-delay между HTTP-запросами | Job-классы краулера | Иначе `429` / captcha / бан |
| Browser-like `User-Agent` в Guzzle | HTTP-клиент парсера | Default `GuzzleHttp/7.x` режется многими сайтами |

## Запуск с нуля

```bash
docker compose -f dev-compose.yml up -d
docker compose -f dev-compose.yml exec app php artisan migrate
```

`entrypoint.sh` сам поставит зависимости при первом старте контейнера `app`. Дальнейшие команды и сценарии — в [README.md](../README.md) и [comands_docker.md](comands_docker.md).
