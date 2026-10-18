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
RUN composer dump-autoload --classmap-authoritative


# ==========================================
# Estágio 2: Imagem Final de Produção
# ==========================================
FROM php:8.3-apache AS production

LABEL maintainer="Seu Nome <seu-email@dominio.com>"

# 1. Instalar dependências do sistema e extensões do PostgreSQL (Supabase)
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
    opcache \
    pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Configurar o Apache apontando para a pasta pública do seu projeto específico
ENV APACHE_DOCUMENT_ROOT=/var/www/html/prod-protho/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Habilitar mod_rewrite do Apache para as rotas do Laravel e Livewire
RUN a2enmod rewrite headers

# 3. Copiar configurações de performance (OPcache e PHP)
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/conf.d/custom.ini /usr/local/etc/php/conf.d/custom.ini

# 4. Definir o diretório de trabalho apontando para a pasta do projeto
WORKDIR /var/www/html/prod-protho

# 5. Copiar o código da aplicação e o vendor gerado no estágio anterior
COPY --chown=www-data:www-data . /var/www/html/prod-protho
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/prod-protho/vendor

# 6. Ajustar permissões das pastas essenciais do Laravel
RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]