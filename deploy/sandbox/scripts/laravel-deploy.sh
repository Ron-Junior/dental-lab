#!/bin/bash

# Garantir que as dependências do Composer existam
if [ ! -d "/var/www/html/vendor" ] || [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing composer dependencies..."
    composer install --no-dev --optimize-autoloader --working-dir=/var/www/html
fi

# Garantir que os assets compilados do Vite existam
if [ ! -d "/var/www/html/public/build" ]; then
    echo "Building frontend assets..."
    npm install --prefix /var/www/html
    npm run build --prefix /var/www/html
fi

# Se estiver usando SQLite e o arquivo não existir, cria-o automaticamente
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    if [ ! -f /var/www/html/database/database.sqlite ]; then
        touch /var/www/html/database/database.sqlite
    fi
    chown www-data:www-data /var/www/html/database/database.sqlite
    chmod 664 /var/www/html/database/database.sqlite
fi

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Running migrations..."
php artisan migrate --force
