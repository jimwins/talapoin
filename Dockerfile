FROM dunglas/frankenphp

LABEL maintainer="Jim Winstead <jimw@trainedmonkey.com>"

RUN install-php-extensions \
    gd \
    zip \
    opcache

ENV SERVER_NAME=":8000"

# These defaults are for production usage, other defaults are in
# conf/php/opcache.ini
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

WORKDIR /app

COPY . /app

RUN curl -sS https://getcomposer.org/installer | php \
        && mv composer.phar /usr/local/bin/ \
        && ln -s /usr/local/bin/composer.phar /usr/local/bin/composer

RUN composer install \
        --no-dev --no-interaction --no-progress \
        --optimize-autoloader --classmap-authoritative
