FROM php:8.3-fpm-alpine

# System deps
RUN apk add --no-cache \
    bash curl git unzip zip \
    icu-dev oniguruma-dev \
    libzip-dev \
    freetype-dev libjpeg-turbo-dev libpng-dev \
    $PHPIZE_DEPS

# PHP extensions commonly needed by Laravel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql mbstring zip exif pcntl intl gd opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Entrypoint
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm", "-F"]
