# The images that make load, make fpm, make apache and make matrix run in,
# so none needs anything installed besides Docker. PHP_VERSION picks the PHP
# version.
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
# and OPcache too, plus nginx and oha for make load, and Composer.
FROM php:${PHP_VERSION}-fpm

RUN apt-get update \
	&& apt-get install -y --no-install-recommends curl nginx unzip \
	&& rm -rf /var/lib/apt/lists/*

# Debian doesn't package oha, so it comes from its GitHub release, checked
# against the release's SHA-256 for each architecture.
ARG OHA_VERSION=1.16.0
ARG TARGETARCH
RUN case "${TARGETARCH}" in \
		amd64) sum=620bb9e16fb53eabc9a3fc45f88bdb41fefa3fee5c05e75892011ce320391716 ;; \
		arm64) sum=99a790eb8c3e0feaca974bd6b32f0f8d4426a0c5b289f39e833e5b2c7529cd39 ;; \
		*) echo "oha has no Linux binary for ${TARGETARCH}" >&2; exit 1 ;; \
	esac \
	&& curl -fsSL -o /usr/local/bin/oha "https://github.com/hatoo/oha/releases/download/v${OHA_VERSION}/oha-linux-${TARGETARCH}" \
	&& echo "${sum}  /usr/local/bin/oha" | sha256sum -c - \
	&& chmod 755 /usr/local/bin/oha

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# php-fpm won't run as root unless it's told to, so everything runs as dev.
# docker/run.sh copies the repository to /work. vendor/ is a Docker volume,
# which starts out owned by dev because this directory is.
RUN useradd --create-home dev \
	&& mkdir -p /work/vendor \
	&& chown -R dev:dev /work
USER dev
WORKDIR /work
