FROM php:8.1-cli

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN pecl install -o -f redis \
        &&  rm -rf /tmp/pear \
        &&  docker-php-ext-enable redis \
        && docker-php-ext-install pdo_mysql

RUN groupadd -g ${GROUP_ID} bench \
    && useradd -l -u ${USER_ID} -g bench bench \
    && install -d -m 0755 -o bench -g bench /home/bench \
    && mkdir -p /home/bench/.ssh/

RUN mkdir /app
WORKDIR /app
USER bench