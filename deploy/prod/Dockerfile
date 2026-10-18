FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libwebp-dev \
    libjpeg-turbo-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    postgresql-dev \
    libxml2-dev \
    autoconf \
    g++ \
    make

# Configura explicitamente o GD e o caminho do PostgreSQL para o PHP 8.5
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-configure pdo_pgsql --with-pdo-pgsql=/usr

# Instala as extensões sem erros
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    bcmath \
    gd \
    zip \
    opcache

# --- CONFIGURAÇÃO DO OPCACHE ---
RUN { \
    echo '[opcache]'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Instalando o Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]