default: help

.PHONY: help
help: # Show help for each of the Makefile recipes.
	@grep -E '^[a-zA-Z0-9 -]+:.*#'  Makefile | sort | while read -r l; do printf "\033[1;32m$$(echo $$l | cut -f 1 -d':')\033[00m:$$(echo $$l | cut -f 2- -d'#')\n"; done

.PHONY: composer-install
composer-install: # Install PHP dependencies via Composer.
	docker run --rm --interactive --tty --volume $(CURDIR):/app composer install --ignore-platform-reqs

.PHONY: mago-analyze
mago-analyze: # Run static analysis via mago.
	docker run --rm -it -v $(CURDIR):/app mago mago analyze 

.PHONY: mago-lint
mago-lint: # Run linting via mago.
	docker run --rm -it -v $(CURDIR):/app mago mago lint 

.PHONY: mago-lint-fix
mago-lint-fix: # Run linting with auto-fix via mago.
	docker run --rm -it -v $(CURDIR):/app mago mago lint --fix

.PHONY: mago-lint-format
mago-format: # Run code formatting via mago.
	docker run --rm -it -v $(CURDIR):/app mago mago format

.PHONY: run-tests
run-tests: # Run unit tests via PHPUnit.
	docker compose run --rm php ./vendor/bin/phpunit --colors=always --configuration ./tests/phpunit.xml ./tests/