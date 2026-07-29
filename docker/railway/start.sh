#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT deve ser um numero inteiro." >&2
        exit 1
        ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT deve estar entre 1 e 65535." >&2
    exit 1
fi

cd /var/www/html

mkdir -p \
    storage/app/private \
    storage/app/backups \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown www-data:www-data \
    storage \
    storage/app \
    storage/app/private \
    storage/app/backups \
    storage/framework \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

PORT="$port" envsubst '${PORT}' \
    < /etc/nginx/http.d/default.conf.template \
    > /etc/nginx/http.d/default.conf

su-exec www-data php artisan optimize

exec /usr/bin/supervisord -c /etc/supervisord.conf
