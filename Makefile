.PHONY: assets build up down reset-db install smoke shell test test-unit test-integration

build:
	docker compose build app ldap

# The image copies vendor/twbs/bootstrap/dist to public/vendor/bootstrap while
# it builds; this copies the same files out to the checkout's public/vendor.
assets: build
	rm -rf public/vendor/bootstrap
	mkdir -p public/vendor
	docker compose run --rm --no-deps -v "$(CURDIR)/public/vendor:/out" test \
		cp -r vendor/twbs/bootstrap/dist /out/bootstrap

up:
	docker compose up -d --wait app

down:
	docker compose down

reset-db:
	docker compose down -v

install:
	docker compose run --rm --no-deps test composer install --prefer-dist --no-interaction

smoke: up
	curl -fsS -o /dev/null -w "GET / -> %{http_code}\n" http://localhost:20680/

shell:
	docker compose run --rm test bash

test: test-unit test-integration

test-unit: build
	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite unit
	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite functional

test-integration: build
	docker compose up -d --wait db ldap
	docker compose run --rm test vendor/bin/phpunit --testsuite integration
