## Структура Docker-инфраструктуры

```
parser-emails/
├── Dockerfile
├── dev-compose.yml
├── .dockerignore
└── docker/
    ├── entrypoint.sh
    ├── nginx/
    │   └── default.conf
    └── php/
        ├── php.ini
        └── www.conf
```

## Сервисы

| Сервис | Назначение | Команда |
|---|---|---|
| `app` | HTTP (nginx + php-fpm под supervisord) | entrypoint.sh (composer/npm install + supervisord) |
| `queue` | Horizon: process manager очередей | `php artisan horizon` |
| `scheduler` | Cron Laravel | `php artisan schedule:work` |
| `db` | MySQL 8 (tuned) | innodb_buffer_pool_size=1G, max_connections=200 |
| `redis` | Очередь + кэш | `redis-server --appendonly yes` (persistence) |
| `phpmyadmin` | Web-UI для MySQL (`:8081`) | стандартный entrypoint, авто-логин `root/root` через `PMA_*` |

- Сборка образа

```bash
docker compose -f dev-compose.yml build
```

- Запуск контейнеров

```bash
docker compose -f dev-compose.yml up -d
```

- Остановить и удалить контейнеры

```bash
docker compose -f dev-compose.yml down
```

- Установка PHP-зависимостей (если entrypoint не отработал)
- Когда нужен ручной exec composer install
    - Том app_vendor повреждён или удалён (docker compose down -v).
    - В composer.json появились новые зависимости, и нужно «доставить» их.
    - entrypoint упал по сети или другой причине, и vendor/autoload.php остался отсутствовать.

```bash
docker compose -f dev-compose.yml exec app composer install
```

- Создание `.env` (если ещё не существует)

```bash
docker compose -f dev-compose.yml exec app cp .env.example .env
```
- Настройка БД в `.env`

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=root
```

Также `REDIS_HOST=redis`.

- Генерация APP_KEY (если ещё не сгенерирован)

```bash
docker compose -f dev-compose.yml exec app php artisan key:generate
```

- Миграции БД

```bash
docker compose -f dev-compose.yml exec app php artisan migrate
```

- Пересборка после изменения конфигов

```bash
docker compose -f dev-compose.yml up -d --build app
```

- Просмотр логов контейнера `app` в реальном времени

```bash
docker compose -f dev-compose.yml logs -f app
```

- Перезапуск контейнера `app`

```bash
docker compose -f dev-compose.yml restart app
```

- Полная очистка с удалением томов

```bash
docker compose -f dev-compose.yml down -v
```

## Команды для очередей и cron

- Логи воркера queue в реальном времени

```bash
docker compose -f dev-compose.yml logs -f queue
```

- Логи scheduler в реальном времени

```bash
docker compose -f dev-compose.yml logs -f scheduler
```

- Перезапуск воркера (например, после деплоя нового кода джобов)

```bash
docker compose -f dev-compose.yml restart queue
```

- Масштабирование воркеров (N идентичных контейнеров queue)

```bash
docker compose -f dev-compose.yml up -d --scale queue=3
```

- Очистить очереди в Redis (полный сброс)

```bash
docker compose -f dev-compose.yml exec redis redis-cli FLUSHALL
```

- Проверить очереди в Redis

```bash
docker compose -f dev-compose.yml exec redis redis-cli KEYS "queues:*"
```

- Поставить тестовую джобу из tinker

```bash
docker compose -f dev-compose.yml exec app php artisan tinker
```

```php
dispatch(new \App\Jobs\TestJob())->onQueue('search');
```

