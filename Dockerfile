FROM php:8.3-cli-alpine

WORKDIR /app

RUN docker-php-ext-install pcntl calendar

COPY composer.json .

RUN curl -sS https://getcomposer.org/composer.phar -o composer.phar \
    && php composer.phar update \
    && rm composer.phar

COPY resources resources
COPY lib lib
COPY dayinfo2mqtt.php .

ENTRYPOINT ["php", "dayinfo2mqtt.php"]