FROM php:8.5-fpm-alpine

# Instala todas as dependências do sistema necessárias, incluindo o postgresql-dev para o pdo_pgsql
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

# Configura o GD
RUN docker-php-ext-configure gd --with-jpeg --with-webp

# Instala as extensões necessárias para o Laravel e Supabase
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