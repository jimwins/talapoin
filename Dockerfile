FROM php:8.3.14-fpm-alpine

LABEL maintainer="Jim Winstead <jimw@trainedmonkey.com>"

RUN apk add --no-cache -X http://dl-cdn.alpinelinux.org/alpine/edge/testing \
        freetype-dev \
        gifsicle \
        git \
        jpegoptim \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        linux-headers \
        optipng \
        mpdecimal-dev \
        pngquant \
        mysql-client \
        tzdata \
        zip \
        zlib-dev \
        ${PHPIZE_DEPS} \
      && pecl install decimal \
      && docker-php-ext-enable decimal \
      && pecl install xdebug \
      && docker-php-ext-enable xdebug \
      && docker-php-ext-configure gd --with-freetype --with-jpeg \
      && docker-php-ext-install \
          bcmath \
          gd \
          mysqli \
          opcache \
          pdo \
          pdo_mysql \
          zip \
      && apk del -dev ${PHPIZE_DEPS}

# These defaults are for production usage, other defaults are in
# conf/php/opcache.ini
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY config/php/* "$PHP_INI_DIR/conf.d"
COPY config/php-fpm/* /usr/local/etc/php-fpm.d

WORKDIR /app

COPY . /app

RUN curl -sS https://getcomposer.org/installer | php \
        && mv composer.phar /usr/local/bin/ \
        && ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

RUN composer install \
        --no-dev --no-interaction --no-progress \
        --optimize-autoloader --classmap-authoritative
