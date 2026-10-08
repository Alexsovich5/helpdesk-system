.PHONY: assets build up down reset-db install demo smoke shell mail-clean test test-unit test-integration

build:
	docker compose build app ldap smtp-sink

# The image copies vendor/twbs/bootstrap/dist to public/vendor/bootstrap while
# it builds; this copies the same files out to the checkout's public/vendor.
assets: build
	rm -rf public/vendor/bootstrap
	mkdir -p public/vendor
	docker compose run --rm --no-deps -v "$(CURDIR)/public/vendor:/out" test \
		cp -r vendor/twbs/bootstrap/dist /out/bootstrap

up:
	docker compose up -d --wait app ldap

# Rebuilds the helpdesk database from scratch with the demo data (local env
# runs DemoSeeder), so it can be run again at any time. The plain migrate
# first creates the migrations table on a new database, which
# migrate:refresh needs.
demo: up
	docker compose exec -T app php artisan migrate --force
	docker compose exec -T app php artisan migrate:refresh --seed --force

down:
	docker compose down

reset-db:
	docker compose down -v

install:
	docker compose run --rm --no-deps test composer install --prefer-dist --no-interaction

# Signs in through the LDAP simulator and opens the ticket list and reports
# over HTTP; expects the demo data (make demo).
smoke: up
	docker compose run --rm --no-deps -v "$(CURDIR)/docker/smoke.sh:/smoke.sh:ro" test sh /smoke.sh http://app

shell:
	docker compose run --rm test bash

# Removes the captured messages from the mail-sink volume.
mail-clean:
	docker compose run --rm --no-deps test sh -c 'rm -f /var/mail-sink/*.eml'

test: test-unit test-integration

test-unit: build
	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite unit
	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite functional

test-integration: build
	docker compose up -d --wait db ldap smtp-sink
	docker compose run --rm test vendor/bin/phpunit --testsuite integration
	$(MAKE) demo
	$(MAKE) smoke
