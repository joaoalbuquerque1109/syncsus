#!/bin/sh
set -eu

php artisan config:cache
php artisan view:cache

cd public
exec php -S "0.0.0.0:${PORT:-8080}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
