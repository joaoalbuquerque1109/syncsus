#!/bin/sh
set -eu

mode="${SYNC_SUS_RAILWAY_MODE:-web}"
case "$mode" in
    web)
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
        supervisor_configuration=/etc/supervisord.conf
        ;;
    tenant-worker)
        supervisor_configuration=/etc/supervisord.tenant-worker.conf
        ;;
    *)
        echo "SYNC_SUS_RAILWAY_MODE deve ser web ou tenant-worker." >&2
        exit 1
        ;;
esac

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

if [ "$mode" = "web" ]; then
    PORT="$port" envsubst '${PORT}' \
        < /etc/nginx/http.d/default.conf.template \
        > /etc/nginx/http.d/default.conf
fi

su-exec www-data php artisan optimize

exec /usr/bin/supervisord -c "$supervisor_configuration"
