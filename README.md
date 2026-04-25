# parser-emails

Laravel 10 + Docker — dev-окружение для распределённого парсера писем/лидов.

---

## Быстрый старт

Подробные команды Docker:
[documents/comands_docker.md](documents/comands_docker.md)

Подробное описание архитектуры:
[documents/installProject.md](documents/installProject.md)

Поднять окружение:

```bash
docker compose -f dev-compose.yml up -d
```

Открыть в браузере:
- [Главная Laravel](http://localhost:8080)
- [phpMyAdmin](http://localhost:8081)
- [UI очередей Horizon](http://localhost:8080/horizon)


Остановить:

```bash
docker compose -f dev-compose.yml down
```

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
DB_DATABASE=laravel
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
