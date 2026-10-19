FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
COPY src ./src
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
EXPOSE 8080
# The built-in server is enough for a demo; production would run php-fpm behind nginx.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
