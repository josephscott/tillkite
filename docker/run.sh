#!/bin/sh
# Runs a command in Tillkite's Docker image, in a copy of the repository.
# make load, make fpm, make apache, make root and make matrix run it, with
# the repository mounted read-only at /src, so nothing in it changes.
#
# The copy leaves out vendor/, which is a Docker volume for each PHP
# version, so composer install has nothing to do after the first run. It
# uses your composer.lock when there is one, so the versions match yours.
set -e

tar -C /src \
	--exclude=./.git \
	--exclude=./.claude \
	--exclude=./vendor \
	--exclude='./example/var/*.php' \
	-cf - . | tar -C /work -xf -

composer install --no-interaction --no-progress --quiet

# make root runs as root. vendor/ goes back to dev, so the next run, as dev,
# can still update it.
if [ "$(id -u)" = 0 ]; then
	chown -R dev:dev /work/vendor
fi

exec "$@"
