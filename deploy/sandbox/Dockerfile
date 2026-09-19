FROM richarvey/nginx-php-fpm:3.1.6

# Instalar Node.js e npm (Alpine Linux)
RUN apk add --no-cache nodejs npm

WORKDIR /var/www/html

# Copiar arquivos do projeto
COPY . .

# Configurações da Imagem Richarvey
ENV SKIP_COMPOSER 1
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1
ENV REAL_IP_HEADER 1

# Configurações do Laravel
ENV APP_ENV production
ENV APP_DEBUG false
ENV LOG_CHANNEL stderr
ENV COMPOSER_ALLOW_SUPERUSER 1

# Sobrescrever a configuração default do Nginx com a do projeto
COPY deploy/sandbox/conf/nginx/nginx-site.conf /etc/nginx/sites-available/default.conf

# Instalar dependências PHP e compilar frontend
RUN composer install --no-dev --optimize-autoloader \
    && npm install \
    && npm run build \
    && php artisan storage:link || true

# Configurar permissões nos diretórios storage, cache e database
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Copiar o script de inicialização para o local esperado pela imagem
COPY deploy/sandbox/scripts/laravel-deploy.sh /var/www/html/scripts/laravel-deploy.sh
RUN chmod +x /var/www/html/scripts/laravel-deploy.sh