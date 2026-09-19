FROM php:8.4-cli

WORKDIR /var/www

# Instala dependências do sistema e Node.js
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copia o Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copia o código do projeto
COPY . .

# Instala dependências do PHP e compila o Vite/Livewire
RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 10000 5173

# Roda as migrações e inicia o servidor
CMD php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=10000