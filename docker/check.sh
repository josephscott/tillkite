#!/bin/sh
# make matrix runs this for each PHP version, in docker/run.sh's copy of the
# repository: lint, analysis and every test, the php-fpm tests too.
set -e

# The image has nginx and php-fpm, so the php-fpm tests run too.
export TILLKITE_FPM=1
make lint analyze tests

# make tests rebuilt dist/tillkite.php from src/, and App::SOURCE_HASH in
# src/app.php, so they should match yours.
for file in dist/tillkite.php src/app.php; do
	if ! cmp -s "$file" "/src/$file"; then
		echo "$file is out of date. Run make dist." >&2
		exit 1
	fi
done
