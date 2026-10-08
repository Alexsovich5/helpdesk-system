.PHONY: build up down reset-db install smoke shell test test-unit test-integration

build:
	docker compose build app

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
	docker compose up -d --wait db
	docker compose run --rm test vendor/bin/phpunit --testsuite integration
