# ==========================================
# Estágio 1: Dependências do Composer (Vendor)
# ==========================================
FROM composer:2.8 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist \
    --no-dev

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-scripts


# ==========================================
# Estágio 2: Imagem Final de Produção (PHP 8.4)
# ==========================================
FROM php:8.4-apache AS production

LABEL maintainer="Ronaldo Costa"

# 1. Instalar dependências do sistema e extensões essenciais (PostgreSQL / Supabase)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libxml2-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    gd \
    bcmath \
    pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configurar o OPcache nativo e limites de performance do PHP
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'max_execution_time = 60'; \
    echo 'date.timezone = "America/Sao_Paulo"'; \
    } > /usr/local/etc/php/conf.d/custom.ini

# 3. Configurar o Apache para apontar para a pasta public do projeto
ENV APACHE_DOCUMENT_ROOT=/var/www/html/prod-protho/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

RUN a2enmod rewrite headers

WORKDIR /var/www/html/prod-protho

# 4. Copiar código da aplicação e o vendor otimizado
COPY --chown=www-data:www-data . /var/www/html/prod-protho
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/prod-protho/vendor

# 5. Ajustar permissões das pastas storage e bootstrap/cache
RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]