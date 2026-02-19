#!/usr/bin/env sh
set -e

# Optional: ensure storage + cache dirs exist and are writable
if [ -d /var/www/html/storage ]; then
  mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache
  chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
fi

exec "$@"