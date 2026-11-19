# The images that make fpm, make apache and make matrix run in, so none needs
# anything installed besides Docker. PHP_VERSION picks the PHP version.
ARG PHP_VERSION=8.5

FROM composer:2.9.8 AS composer

# The apache stage, for make apache, tagged tillkite-apache:VERSION: PHP's
# official Apache image, with PHP as an Apache module, mod_php, as many
# hosts run it, and Apache's modules, mod_rewrite among them, for the
# .htaccess. The tests start Apache with a configuration of their
# own, as user dev.
FROM php:${PHP_VERSION}-apache AS apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN useradd --create-home dev \
	&& mkdir -p /work/vendor \
	&& chown -R dev:dev /work
USER dev
WORKDIR /work

# The last stage, which docker build makes without --target, tagged
# tillkite-dev:VERSION: PHP's official php-fpm image, which has the PHP CLI
# and OPcache too, plus nginx and Composer.
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
