# The image that make fpm runs in, so it needs nothing installed besides
# Docker. PHP_VERSION picks the PHP version.
ARG PHP_VERSION=8.5

FROM composer:2.9.8 AS composer

# Tagged tillkite-dev:VERSION: PHP's official php-fpm image, which has the
# PHP CLI and OPcache too, plus nginx and Composer.
FROM php:${PHP_VERSION}-fpm

RUN apt-get update \
	&& apt-get install -y --no-install-recommends nginx unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# php-fpm won't run as root unless it's told to, so everything runs as dev.
# docker/run.sh copies the repository to /work. vendor/ is a Docker volume,
# which starts out owned by dev because this directory is.
RUN useradd --create-home dev \
	&& mkdir -p /work/vendor \
	&& chown -R dev:dev /work
USER dev
WORKDIR /work
