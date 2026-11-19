#!/bin/sh
# Runs a command in Tillkite's Docker image, in a copy of the repository.
# make fpm, make apache and make matrix run it, with the repository mounted read-only
# at /src, so nothing in it changes.
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
exec "$@"
