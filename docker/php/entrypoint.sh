#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

exec "$@"
