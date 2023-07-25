FROM php:7.4-cli-alpine

WORKDIR /app

RUN docker-php-ext-install pcntl calendar

COPY composer.json .

RUN curl -sS https://getcomposer.org/composer.phar -o composer.phar \
    && php composer.phar update \
    && rm composer.phar

COPY resources resources
COPY lib lib
COPY dayinfo2mqtt.php .

# permalink from https://www.data.gouv.fr/en/datasets/contours-geographiques-des-academies/
RUN curl -L -sS https://www.data.gouv.fr/fr/datasets/r/b363e051-9649-4879-ae78-71ef227d0cc5 -o ./resources/fr/academies.csv

# permmalinks from https://www.data.gouv.fr/en/datasets/le-calendrier-scolaire/
RUN curl -L -sS https://www.data.gouv.fr/fr/datasets/r/ee16d126-af0f-4b3b-84d3-080ef8bc0abd -o ./resources/fr/Zone-A.ics
RUN curl -L -sS https://www.data.gouv.fr/fr/datasets/r/c03b7373-6698-4e44-b5f1-9408b4b2cfe8 -o ./resources/fr/Zone-B.ics
RUN curl -L -sS https://www.data.gouv.fr/fr/datasets/r/c594ee20-e694-4f30-810d-752acdf69d70 -o ./resources/fr/Zone-C.ics

ENTRYPOINT ["php", "dayinfo2mqtt.php"]