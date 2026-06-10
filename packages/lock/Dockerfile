FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.3-cli-bookworm AS compile

ENV PHP_SWOOLE_VERSION="v6.1.3"
ENV PHP_REDIS_VERSION="6.1.0"

RUN apt-get update && apt-get install -y \
    make \
    automake \
    autoconf \
    gcc \
    g++ \
    git \
    libssl-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install sockets pcntl

FROM compile AS swoole
RUN git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
    cd swoole-src && \
    phpize && \
    ./configure --enable-sockets --enable-openssl && \
    make && make install

FROM compile AS redis
RUN git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git && \
    cd phpredis && \
    phpize && \
    ./configure && \
    make && make install

FROM compile AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /usr/src/code

RUN echo "extension=swoole.so" > /usr/local/etc/php/conf.d/swoole.ini
RUN echo "extension=redis.so" > /usr/local/etc/php/conf.d/redis.ini

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
RUN echo "memory_limit=512M" >> $PHP_INI_DIR/php.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=swoole /usr/local/lib/php/extensions/no-debug-non-zts-20230831/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/
COPY --from=redis /usr/local/lib/php/extensions/no-debug-non-zts-20230831/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/
COPY . /usr/src/code

CMD ["tail", "-f", "/dev/null"]
