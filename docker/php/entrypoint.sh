#!/usr/bin/env sh
set -e

mkdir -p /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache || true

exec "$@"