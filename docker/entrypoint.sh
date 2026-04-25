#!/bin/bash
set -e

mkdir -p /var/run/php
chown -R www-data:www-data /var/run/php

cd /var/www/html

if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f "package.json" ]; then
    npm install
fi

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
