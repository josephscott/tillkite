SHELL = /bin/bash
.DEFAULT_GOAL := help

# https://mwop.net/blog/2023-12-11-advent-makefile.html
##@ Help
help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

.PHONY: all
all: autoload style lint analyze tests ## <default> Do everything

# ### #

.PHONY: autoload
autoload: ## Regenerate the Composer autoloader, needed after adding or removing a class
	@echo
	@echo "--> Autoload: composer dump-autoload"
	composer dump-autoload
	@echo

.PHONY: style
style: ## Fix any style issues
	@echo
	@echo "--> Style: php-cs-fixer"
	vendor/bin/php-cs-fixer fix -v
	@echo

.PHONY: lint
lint: ## Check if the code is valid
	@echo
	@echo "--> Lint"
	find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0 | xargs -0 php -l
	@echo

.PHONY: analyze
analyze: ## Static analysis, catch problems in code
	@echo
	@echo "--> Analyze: phpstan"
	vendor/bin/phpstan
	@echo

.PHONY: dist
dist: ## Build dist/tillkite.php, every class in src/ in one file, and App::SOURCE_HASH
	@echo
	@echo "--> Dist: dist/tillkite.php"
	php bin/dist.php
	@echo

.PHONY: tests
tests: dist ## Pest tests, against src/ and then dist/tillkite.php
	@echo
	@echo "--> Tests: Pest, against src/"
	vendor/bin/pest
	@echo
	@echo "--> Tests: Pest, against dist/tillkite.php"
	TILLKITE_DIST=1 vendor/bin/pest
	@echo
