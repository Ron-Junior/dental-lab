#!/bin/bash
set -e

# Criar banco de dados SQLite somente se DB_CONNECTION for explicitamente sqlite
if [ "${DB_CONNECTION}" = "sqlite" ]; then
    if [ ! -f /var/www/html/database/database.sqlite ]; then
        touch /var/www/html/database/database.sqlite
    fi
    chown www-data:www-data /var/www/html/database/database.sqlite
    chmod 664 /var/www/html/database/database.sqlite
fi

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:clear
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Creating storage link..."
php artisan storage:link --force

echo "Running migrations..."
php artisan migrate

if [ "${QUEUE_CONNECTION:-database}" != "sync" ]; then
    echo "Starting queue worker..."
    (
        while true; do
            if php artisan queue:work --sleep=3 --tries=3 --timeout=300; then
                echo "Queue worker stopped; restarting." >&2
            else
                echo "Queue worker exited with status $?; restarting." >&2
            fi
            sleep 1
        done
    ) &
fi

echo "Starting PHP-FPM and Nginx..."
php-fpm -D
exec nginx -g "daemon off;"
