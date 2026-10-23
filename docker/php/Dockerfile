# Imagem PHP-FPM do LONGA (usada por app, worker, scheduler e reverb)
FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        bash git unzip icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        linux-headers $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath pcntl exif opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-longa.ini

# Utilizador com o mesmo UID/GID do anfitrião para evitar problemas de permissões nos volumes
RUN addgroup -g ${GID} longa && adduser -D -u ${UID} -G longa longa

WORKDIR /var/www/html
USER longa
