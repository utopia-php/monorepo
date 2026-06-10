ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    openssl-dev \
    curl-dev \
    protobuf-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN pecl install opentelemetry protobuf swoole && \
    docker-php-ext-enable opentelemetry protobuf swoole

RUN docker-php-ext-install sockets

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
CMD ["tail", "-f", "/dev/null"]
