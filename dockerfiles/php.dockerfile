FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

ENV UID=${UID}
ENV GID=${GID}

WORKDIR /var/www/html

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN delgroup dialout 2>/dev/null || true

RUN addgroup -g ${GID} laravel \
 && adduser -G laravel -D -s /bin/sh -u ${UID} laravel \
 && mkdir -p /var/www/html /home/laravel/.composer \
 && chown -R laravel:laravel /var/www/html /home/laravel

RUN sed -i "s/user = www-data/user = laravel/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i "s/group = www-data/group = laravel/g" /usr/local/etc/php-fpm.d/www.conf \
 && echo "php_admin_flag[log_errors] = on" >> /usr/local/etc/php-fpm.d/www.conf

# Рантайм-библиотеки остаются в образе, build-зависимости удаляются после сборки.
# OPcache в PHP 8.5 встроен всегда — ставить отдельно не нужно.
RUN apk add --no-cache \
    git unzip libpq icu-libs libzip libpng libjpeg-turbo freetype \
 && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS linux-headers postgresql-dev icu-dev libzip-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo_pgsql pgsql intl zip bcmath pcntl exif \
 && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz \
    | tar -xz -C /tmp \
 && cd /tmp/phpredis-6.3.0 \
 && phpize && ./configure && make -j$(nproc) && make install \
 && docker-php-ext-enable redis \
 && cd / && rm -rf /tmp/phpredis-* \
 && apk del .build-deps

# Лимиты загрузки (вложения в чат: картинки, документы)
COPY ./php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

USER laravel

CMD ["php-fpm", "-y", "/usr/local/etc/php-fpm.conf", "-R"]
