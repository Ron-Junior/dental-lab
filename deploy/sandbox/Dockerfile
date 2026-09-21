# ==========================================
# Stage 1: Dependências do PHP (Composer)
# ==========================================
FROM composer:latest AS composer-builder
WORKDIR /app

# Copia os arquivos essenciais do projeto para rodar o composer
COPY composer.json composer.lock ./
COPY . .

# Instala as dependências do PHP. O flag --ignore-platform-reqs é usado 
# pois esta imagem base não tem as extensões do PHP que a imagem final terá.
RUN composer install --ignore-platform-reqs --optimize-autoloader

# ==========================================
# Stage 2: Build de assets do Frontend (Node 22)
# ==========================================
FROM node:22-alpine AS frontend-builder
WORKDIR /app

COPY package*.json vite.config.js ./
COPY resources ./resources
COPY public ./public

COPY --from=composer-builder /app/vendor ./vendor

RUN npm ci && npm run build

# ==========================================
# Stage 3: Produção com PHP 8.4-FPM + Nginx
# ==========================================
FROM php:8.4-fpm-alpine

# Instalar dependências do sistema, Nginx e extensões PHP necessárias para o Laravel
RUN apk add --no-cache \
    nginx \
    bash \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql pgsql mbstring exif pcntl bcmath gd opcache

WORKDIR /var/www/html

# Copiar arquivos da aplicação original
COPY . .

# Copiar a pasta vendor (dependências PHP) gerada no Stage 1
COPY --from=composer-builder /app/vendor ./vendor

# Copiar assets compilados (Frontend) gerados no Stage 2
COPY --from=frontend-builder /app/public/build ./public/build

# Ajustar permissões para storage, cache e banco SQLite
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Configuração do Nginx
COPY deploy/sandbox/conf/nginx/nginx-site.conf /etc/nginx/http.d/default.conf

# Copiar o script de inicialização
COPY deploy/sandbox/scripts/laravel-deploy.sh /usr/local/bin/laravel-deploy.sh
RUN chmod +x /usr/local/bin/laravel-deploy.sh

EXPOSE 80

CMD ["/usr/local/bin/laravel-deploy.sh"]