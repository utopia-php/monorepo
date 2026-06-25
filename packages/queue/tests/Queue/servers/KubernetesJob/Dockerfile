FROM php:8.4-cli-alpine

WORKDIR /app

RUN echo "zend.assertions=1" > /usr/local/etc/php/conf.d/asserts.ini \
    && echo "assert.exception=1" >> /usr/local/etc/php/conf.d/asserts.ini

COPY composer.json composer.lock ./
COPY vendor ./vendor
COPY src ./src
COPY tests ./tests

CMD ["php", "tests/Queue/servers/KubernetesJob/worker.php"]
