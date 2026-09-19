# Stage 1: Build de assets do Frontend com Node 22
FROM node:22-alpine AS frontend-builder
WORKDIR /app
COPY package*.json vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm ci && npm run build

# Stage 2: Produção com PHP 8.4-FPM + Nginx (Alpine)
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

# Copiar executável do Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar arquivos da aplicação
COPY . .

# Copiar assets compilados no Stage 1
COPY --from=frontend-builder /app/public/build ./public/build

# Instalar dependências do PHP com Composer
RUN composer install --no-dev --optimize-autoloader

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