# helpdesk-system — Implementation Plan

Companion to `docs/SPEC.md`. There are 17 tasks, and each one is a single commit that leaves `make test` green.
Commands run through Docker only; nothing needs installing on the host except Docker with Compose v2.

Conventions used in every task:

- `make test` = `make test-unit` (PHPUnit suite `unit`, then suite `functional`, as two calls; sqlite in-memory) then
  `make test-integration` (suite `integration` against the compose services). Every suite runs in the compose service `test`.
- Unit/functional tests extend `TestCase` (filters re-enabled, session started, POSTs carry `_token`);
  integration tests extend `IntegrationTestCase` (environment `integration`, own MySQL database `helpdesk_test`, migrated, per-test transaction rollback). See T1.
- Integration fixtures use `firstOrCreate` for categories, users and SLA policies, and every count assertion is scoped to rows the test created
  (by id or a fixture-only date range), so the suite passes in any file order and after `make demo` (which only touches the `helpdesk` database).
- New code goes in `app/Helpdesk/**` (namespace `Helpdesk\`, PSR-0), controllers in `app/controllers`,
  models in `app/models`, migrations in `app/database/migrations`, tests in `app/tests/{unit,functional,integration}`.
- Write the listed tests first, watch them fail, then implement.

---

## T1 — Scaffold Laravel 4.2, Docker stack, Makefile and test harness

**Goal:** a bootable Laravel 4.2 skeleton with pinned 2014 dependencies, a PHP 5.6/Apache image, a MySQL 5.6
service, and a `make test` that runs a unit suite and an integration suite from the first commit.

**Files (create):**
- `composer.json`: exactly the manifest below, plus `"autoload": {"classmap": ["app/commands","app/controllers","app/models","app/database/migrations","app/database/seeds","app/tests/TestCase.php","app/tests/IntegrationTestCase.php"], "psr-0": {"Helpdesk\\": "app/"}}` and the standard 4.2 `scripts` (`php artisan clear-compiled`, `optimize`).
- `composer.lock`: generated inside the image and committed. All 47 packages must be dated ≤ 2014-08-31.
- Laravel 4.2 skeleton taken from `laravel/laravel` tag `v4.2.0`: `artisan`, `server.php`, `bootstrap/{autoload,paths,start}.php`,
  `public/{index.php,.htaccess,robots.txt,favicon.ico}`, `app/{routes,filters}.php`, `app/start/{global,local,artisan}.php`,
  `app/config/*.php` (read `DB_*`, `MAIL_*`, `APP_KEY` via `getenv()`), `app/config/testing/{app,database,cache,session,mail}.php`
  (sqlite `:memory:`, `array` cache/session drivers, `mail.pretend=true`), `app/lang/en/*`, `app/storage/**/.gitignore`,
  `app/views/hello.php`, `app/models/User.php` (stock), `app/controllers/{BaseController,HomeController}.php`, `app/database/seeds/DatabaseSeeder.php`,
  `app/tests/TestCase.php`.
- `app/tests/TestCase.php` changed from stock: `setUp()` calls `parent::setUp()`, then `Route::enableFilters()` (Laravel 4.2's
  `RoutingServiceProvider` disables filters when env is `testing`) and `Session::start()`; a helper `post($uri, $data)` adds
  `_token => Session::token()`. `app/config/app.php` keeps `'key' => getenv('APP_KEY')` (no `key:generate`; key in `.env.local.php`).
- `app/tests/IntegrationTestCase.php`: extends `TestCase`; `createApplication()` sets `$unitTesting = true; $testEnvironment = 'integration';`
  and requires `bootstrap/start.php` (the stock `'testing'` value would force sqlite/fake LDAP/mail.pretend regardless of `APP_ENV`);
  `setUp()` runs `Artisan::call('migrate')` once per class (static flag), then `DB::beginTransaction()`; `tearDown()` rolls back.
  A `protected $useTransaction = true;` switch lets DDL-running tests (T15) opt out.
- `bootstrap/start.php` environment detection: `$env = $app->detectEnvironment(function () { return getenv('APP_ENV') ?: 'production'; });`
- `Dockerfile` (package retrieval replaced by verified downloads in T17): `FROM php:5.6-apache`; replace `/etc/apt/sources.list` with `deb [trusted=yes] http://archive.debian.org/debian stretch main`
  and run `apt-get -o Acquire::Check-Valid-Until=false update` (the image's archive keys are expired, so signed-source checks fail; documented
  deviation in SPEC §7; fallback `-o Acquire::AllowInsecureRepositories=true` + `--allow-unauthenticated`);
  install `libmcrypt-dev libldap2-dev unzip git mysql-client`; `docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu` (arch-aware);
  `docker-php-ext-install mcrypt pdo_mysql ldap`; `a2enmod rewrite`; DocumentRoot `/var/www/html/public`;
  Composer 2.2.24 phar at `/usr/local/bin/composer`; `COPY composer.json composer.lock` then `composer install --prefer-dist --no-scripts --no-autoloader`, then copy the source and run `composer dump-autoload --optimize` (the classmap directories under `app/` do not exist before the source copy, and Composer aborts on a missing classmap path).
- `docker/apache/000-default.conf`, `docker/php/php.ini` (`date.timezone=UTC`).
- `docker-compose.yml`: top-level `name: helpdesk-system`; default network on subnet `172.46.0.0/24`; `app` (build ., image `helpdesk-system:app`, port 20680:80 — host port and subnet set to this project's lane on a shared Docker host instead of 8080, env `APP_ENV=local`, `DB_*` pointing at database `helpdesk`, depends on `db`),
  `db` (`mysql:5.6`, `platform: linux/amd64`, env `MYSQL_ROOT_PASSWORD=root`, `MYSQL_DATABASE=helpdesk`, `MYSQL_USER=helpdesk`, `MYSQL_PASSWORD=helpdesk`,
  named volume `db-data`, `./docker/mysql/init:/docker-entrypoint-initdb.d:ro`, healthcheck `mysqladmin ping`),
  `test` service (profile `test`) reusing the app image, env `DB_HOST=db`, `DB_DATABASE=helpdesk`, `DB_TEST_DATABASE=helpdesk_test`,
  `DB_USERNAME=helpdesk`, `DB_PASSWORD=helpdesk`, `APP_KEY=<same sample key as .env.local.php>`; it is the only service that runs PHPUnit
  (SPEC §5.6). Later tasks add `LDAP_HOST=ldap` (T4) and `MAIL_HOST=smtp-sink`, `MAIL_PORT=1025` (T10) to it.
- `docker/mysql/init/01-test-db.sql`: `CREATE DATABASE IF NOT EXISTS helpdesk_test CHARACTER SET utf8 COLLATE utf8_unicode_ci;`
  `GRANT ALL PRIVILEGES ON helpdesk_test.* TO 'helpdesk'@'%'; FLUSH PRIVILEGES;` (the mysql entrypoint creates `MYSQL_USER` before it runs
  init scripts; scripts run only on a fresh `db-data` volume, so `make down` has a `-v` variant `make reset-db`).
- `.env.local.php` holding the local `APP_KEY` and a matching `.env.testing.php`. These are sample keys for a local stack; neither is a secret. (Removed in T17: a published key lets anyone forge cookies; keys are now generated per install.)
- `Makefile` targets: `build`, `up`, `down`, `reset-db` (`docker compose down -v`), `install` (composer install in container), `smoke`, `shell`, and:
  ```
  test: test-unit test-integration
  test-unit: build
  	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite unit
  	docker compose run --rm --no-deps test vendor/bin/phpunit --testsuite functional
  test-integration: build
  	docker compose up -d --wait db
  	docker compose run --rm test vendor/bin/phpunit --testsuite integration
  ```
  (PHPUnit 4.2 `--testsuite` takes a single name; the environment is chosen by the test base class, not `APP_ENV`.
  The `test` service runs the code baked into the `helpdesk-system:app` image, with no bind mount, so the test targets depend on
  `build`; the rebuild is a cached layer copy plus `dump-autoload`, which also keeps the classmap current when later tasks add classes.
  T1's `smoke` target is `up` plus `curl -f http://localhost:20680/`; T15 replaces it with `docker/smoke.sh`.)
- `phpunit.xml`: bootstrap `bootstrap/autoload.php`, suites `unit` (`app/tests/unit`), `functional` (`app/tests/functional`), `integration` (`app/tests/integration`).
- `app/config/integration/database.php`: `default => mysql`, MySQL host/user/password via `DB_*` env, and
  `'database' => getenv('DB_TEST_DATABASE') ?: 'helpdesk_test'`, so integration tests never touch the demo database `helpdesk`.
- `app/tests/unit/EnvironmentTest.php`, `app/tests/integration/MysqlConnectionTest.php` (extends `IntegrationTestCase`).
- `.gitignore` (`/vendor`, `/app/storage/*` except `.gitignore`, `.DS_Store`).

Manifest (verified with `tools/check_period.py`: 47 checked, 0 problems):
```json
{
  "require": {
    "php": ">=5.4.0", "ext-mcrypt": "*", "ext-pdo_mysql": "*", "ext-ldap": "*",
    "laravel/framework": "v4.2.8", "twbs/bootstrap": "v3.2.0",
    "classpreloader/classpreloader": "1.0.2", "d11wtq/boris": "v1.0.7", "filp/whoops": "1.1.2",
    "ircmaxell/password-compat": "1.0.1", "jeremeamia/superclosure": "1.0.1", "monolog/monolog": "1.10.0",
    "nesbot/carbon": "1.11.0", "nikic/php-parser": "v0.9.5", "patchwork/utf8": "v1.1.25",
    "phpseclib/phpseclib": "0.3.7", "predis/predis": "v0.8.7", "psr/log": "1.0.0", "stack/builder": "v1.0.2",
    "swiftmailer/swiftmailer": "v5.2.1",
    "symfony/browser-kit": "v2.5.4", "symfony/console": "v2.5.4", "symfony/css-selector": "v2.5.4",
    "symfony/debug": "v2.5.4", "symfony/dom-crawler": "v2.5.4", "symfony/event-dispatcher": "v2.5.4",
    "symfony/filesystem": "v2.5.3", "symfony/finder": "v2.5.3", "symfony/http-foundation": "v2.5.3",
    "symfony/http-kernel": "v2.5.3", "symfony/process": "v2.5.4", "symfony/routing": "v2.5.4",
    "symfony/security-core": "v2.5.3", "symfony/translation": "v2.5.3"
  },
  "require-dev": {
    "phpunit/phpunit": "4.2.4", "phpunit/php-code-coverage": "2.0.11", "phpunit/php-file-iterator": "1.3.4",
    "phpunit/php-text-template": "1.2.0", "phpunit/php-timer": "1.0.5", "phpunit/php-token-stream": "1.3.0",
    "phpunit/phpunit-mock-objects": "2.2.0", "ocramius/instantiator": "1.1.3", "ocramius/lazy-map": "1.0.0",
    "sebastian/comparator": "1.0.1", "sebastian/diff": "1.2.0", "sebastian/environment": "1.0.1",
    "sebastian/exporter": "1.0.1", "sebastian/version": "1.0.3", "symfony/yaml": "v2.5.4",
    "mockery/mockery": "0.9.1", "fzaninotto/faker": "v1.4.0"
  },
  "config": {"preferred-install": "dist"},
  "minimum-stability": "stable"
}
```

**Tests to write first:**
- `EnvironmentTest`: `App::environment() === 'testing'`; `Illuminate\Foundation\Application::VERSION` starts with `4.2.`; `PHP_VERSION` starts with `5.6.`; `extension_loaded('mcrypt')`, `'ldap'`, `'pdo_mysql'` are all true; route filters run (a route registered with a `before` filter that returns a 418 response answers 418 — Laravel 4.2's `Router` has `enableFilters()`/`disableFilters()` but no `filtersEnabled()` getter); `GET /` returns 200 (no auth filter yet).
- `MysqlConnectionTest` (`@group integration`): `App::environment() === 'integration'`; `DB::connection()->getDriverName() === 'mysql'`;
  `DB::select('SELECT VERSION() AS v')[0]->v` starts with `5.6`; `DB::connection()->getDatabaseName() === 'helpdesk_test'`.

**Acceptance command:** `make build && make test && python3 ../../tools/check_period.py .` (`make build` proves the archive apt sources install.)

**Commit message:**
```
Scaffold Laravel 4.2 app with Docker stack and test harness

Pin all Composer dependencies to 2014 releases, add a PHP 5.6/Apache
image, MySQL 5.6 service, and a Makefile whose test target runs the
unit and integration suites.
```

---

## T2 — Users, local authentication and responsive base layout

**Goal:** a `users` table, local (bcrypt) login/logout, `auth`/`guest`/`role:*` filters, and the Bootstrap 3.2 master layout.

**Files:**
- create `app/database/migrations/2014_06_02_000000_create_users_table.php`,
  `app/controllers/AuthController.php`, `app/views/layouts/master.blade.php` (viewport meta, collapsing navbar),
  `app/views/auth/login.blade.php`, `app/views/home/index.blade.php`, `public/css/app.css`,
  `public/js/jquery-1.11.1.min.js` (vendored from code.jquery.com; version in filename),
  `app/database/seeds/UserTableSeeder.php` (local `admin` break-glass account, `APP_ENV=local` only).
- modify `app/routes.php`, `app/filters.php` (add `role` filter: `Route::filter('role', function($route,$req,$role){...})`), `app/models/User.php` (the T1 stock file: keeps `UserInterface`/`RemindableInterface`, adds `role`/`source` and `isAgent()`, `isAdmin()`), `app/tests/unit/EnvironmentTest.php` (`GET /` as a guest now expects a redirect to `/login`), `Dockerfile` (after `dump-autoload`, copies `vendor/twbs/bootstrap/dist` into `public/vendor/bootstrap` inside the image, because `vendor/` exists only in the image and the containers have no bind mount), `Makefile` (`assets` target depends on `build` and copies the same `dist` out of the image into the checkout's `public/vendor/bootstrap`), `.gitignore` and `.dockerignore` (`/public/vendor`), `app/controllers/HomeController.php` (`getIndex` renders `home.index`, since the stock action rendered the deleted `hello` view).
- delete `app/views/hello.php`.

**Tests to write first:** `app/tests/functional/AuthTest.php`: a guest hitting `/` is redirected to `/login`; a valid local login redirects to `/` and `Auth::check()`; a bad password returns to `/login` with errors and no session; logout; `role:admin` returns 403 for a requester; a login POST without `_token` throws `Illuminate\Session\TokenMismatchException` (proves the base `TestCase` re-enabled filters); `app/tests/unit/UserTest.php` role helpers.

**Acceptance command:** `make test`

**Commit message:**
```
Add local user accounts, login filters and Bootstrap layout

Users table with requester/agent/admin roles, bcrypt login, role
filter, and a responsive Bootstrap 3.2 master layout.
```

---

## T3 — LDAP authentication provider with fake gateway

**Goal:** sign in with directory credentials through an `ldap` auth driver, with first-login provisioning, group→role mapping and local fallback.

**Files:**
- create `app/Helpdesk/Auth/LdapGateway.php` (interface from SPEC §5.7), `app/Helpdesk/Auth/NativeLdapGateway.php` (`ldap_connect`, `ldap_set_option(LDAP_OPT_PROTOCOL_VERSION,3)`, `LDAP_OPT_REFERRALS 0`, service bind, `ldap_search` with `LDAP_USER_FILTER` and escaped input, `memberOf` or group search by `member`), `app/Helpdesk/Auth/FakeLdapGateway.php`,
  `app/Helpdesk/Auth/RoleMapper.php`, `app/Helpdesk/Auth/LdapUserProvider.php`, `app/Helpdesk/Auth/LdapServiceProvider.php` (`Auth::extend('ldap', …)`, binds `LdapGateway` from `ldap.driver` = `native|fake`),
  `app/config/ldap.php`, `app/config/testing/ldap.php` (`driver => fake`, seeded users).
- modify `app/config/auth.php` (`driver => ldap`), `app/config/app.php` (register provider).

**Tests to write first:** `app/tests/unit/Auth/RoleMapperTest.php` (the highest role wins; no group → requester; map parsing from the env string), `app/tests/unit/Auth/LdapUserProviderTest.php` (unknown user → null; wrong password → false; a correct password provisions a `source=ldap` user with the mapped role; a changed group updates the role on the next login; a local user with a password still authenticates when LDAP has no entry; empty password is rejected (anonymous-bind guard); filter input `*)(uid=*` is escaped), `app/tests/functional/LdapLoginTest.php` (end-to-end login through `/login` with the fake).

**Acceptance command:** `make test`

**Commit message:**
```
Authenticate against LDAP with role mapping and local fallback

Custom Laravel auth driver backed by an LdapGateway interface, with a
native ext-ldap implementation and an in-memory fake for tests.
```

---

## T4 — OpenLDAP directory simulator and integration tests

**Goal:** a period OpenLDAP container that stands in for Active Directory, and integration tests that exercise `NativeLdapGateway` against it.

**Files:**
- create `docker/ldap/Dockerfile` (package retrieval replaced by verified downloads in T17; `FROM debian:wheezy`; `/etc/apt/sources.list` = `deb [trusted=yes] http://archive.debian.org/debian wheezy main`,
  plus `deb [trusted=yes] http://archive.debian.org/debian-security wheezy/updates main` (the image's `perl-base` is a security revision, so slapd's `perl` dependency needs that archive; SPEC §7),
  `apt-get -o Acquire::Check-Valid-Until=false update`, fallback `--force-yes` (wheezy keys expired; SPEC §7 deviation); `slapd ldap-utils`; debconf preseed domain `helpdesk.local`, admin password `admin`), `docker/ldap/seed.ldif` (ou=people, ou=groups; `alice`/`bob`/`carol` with password `password`; `groupOfNames` `helpdesk-admins`, `helpdesk-agents`), `docker/ldap/entrypoint.sh` (loads seed once, runs `slapd -d 0`),
  `app/config/integration/ldap.php` (`driver => native`, host `ldap`), `app/tests/integration/LdapDirectoryTest.php` (extends `IntegrationTestCase`, so the `users` table exists in MySQL).
- modify `docker-compose.yml` (service `ldap`, image `helpdesk-system:ldap`, with `platform: linux/amd64` since `debian:wheezy` has no arm64 image, named volume `ldap-data` on `/var/lib/ldap`, healthcheck `ldapsearch -x -b dc=helpdesk,dc=local`), `Makefile` (`build` also builds `ldap`, so a changed `docker/ldap` is rebuilt; `test-integration` also waits for `ldap`); `test` service env adds `LDAP_HOST=ldap`.

**Tests to write first:** `LdapDirectoryTest`: `findUser('bob')` returns the DN, email and `helpdesk-agents`; `bind(dn,'password')` is true and `bind(dn,'wrong')` false; `findUser('nobody')` null; `Auth::attempt(['username'=>'alice','password'=>'password'])` provisions (or updates, if already present) an admin, asserted on the returned user's `role` and `source`, not on a row count.

**Acceptance command:** `make build && make test`

**Commit message:**
```
Add OpenLDAP simulator standing in for Active Directory

Debian wheezy slapd container seeded with test users and groups, and
integration tests for the native LDAP gateway against it.
```

---

## T5 — Ticket domain: schema, numbering and status machine

**Goal:** persist tickets, comments and events; enforce the status workflow in a service.

**Files:**
- create migrations `2014_06_09_000000_create_categories_table.php`, `…_create_sla_policies_table.php` (table only; populated in T7), `…_create_tickets_table.php` (`response_due_at`/`resolution_due_at` nullable: due dates are only computed from T7), `…_create_ticket_comments_table.php`, `…_create_ticket_events_table.php`;
  models `Category.php`, `Ticket.php` (scopes `visibleTo($user)`, `open()`), `TicketComment.php`, `TicketEvent.php`;
  `app/Helpdesk/Tickets/StatusMachine.php`, `TicketNumber.php`, `TicketService.php` (`create`, `assign`, `comment`, `transition($ticket, $to, $actor)`: a non-agent actor may only do `resolved → open|closed` on their own ticket, else `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException`; each writes a `ticket_events` row and fires `ticket.*` events), `InvalidTransitionException.php`;
  `app/database/seeds/CategoryTableSeeder.php`.
- modify `DatabaseSeeder.php`.

**Tests to write first:** `app/tests/unit/Tickets/StatusMachineTest.php` (every allowed and a sample of disallowed transitions per SPEC §5.2), `TicketNumberTest.php` (`HD-000001`, uses id), `TicketServiceTest.php` (sqlite: create sets `new` and writes a `created` event; assign on `new` → `open` plus two events; the first public agent comment sets `first_responded_at` and an internal one does not; `resolved` sets `resolved_at`, reopen clears it; closed → any throws; a requester may reopen/close their own resolved ticket but not resolve an open one or touch another's; `Event::fire` called with `ticket.created`, `ticket.assigned`, `ticket.commented`, `ticket.status`).

**Acceptance command:** `make test`

**Commit message:**
```
Add ticket model, numbering and status workflow service

Tickets, comments and an append-only event log, with a status machine
that rejects invalid transitions and fires domain events.
```

---

## T6 — Ticket web UI with filters and role-based visibility

**Goal:** list/create/show/comment/assign/transition tickets in the browser.

**Files:**
- create `app/controllers/TicketController.php`, `CommentController.php`, views `tickets/index.blade.php` (filters, search `q` over number/subject, `table-responsive`, pagination 20), `tickets/create.blade.php`, `tickets/show.blade.php` (details, timeline of events and comments, agent sidebar with assign/status forms), `tickets/_status_label.blade.php`.
- modify `app/routes.php` (SPEC §5.4 ticket routes, `csrf` on POST; `/tickets/{number}/status` uses `auth` + `ticket.access`, not `role:agent`), `app/filters.php` (`ticket.access` filter: owner or agent), `home/index.blade.php` (my open tickets / unassigned queue).

**Tests to write first:** `app/tests/functional/TicketFlowTest.php`: a requester creates a ticket and is redirected to `/tickets/HD-000001`; validation errors on empty subject; a requester gets 403 on someone else's ticket; the index shows only own tickets to a requester and all tickets to an agent; the status/priority filter narrows results; an agent assigns and resolves; the requester then reopens their resolved ticket via `/status` (302) but gets 403 trying to resolve it; a requester POSTing `is_internal=1` has the flag ignored; internal comments are hidden from the requester's view; POST without `_token` → `TokenMismatchException`.

**Acceptance command:** `make test`

**Commit message:**
```
Add ticket list, detail and agent workflow screens

Filterable ticket list, creation form, ticket timeline with comments,
and agent-only assign and status controls guarded by filters.
```

---

## T7 — SLA policies and due-date calculation

**Goal:** compute response/resolution due dates from priority and evaluate SLA state.

**Files:**
- create `app/Helpdesk/Sla/SlaCalculator.php` (`dueDates(Ticket)`, `state(Ticket, Carbon $now)` per SPEC §5.3), `app/models/SlaPolicy.php`, `app/database/seeds/SlaPolicyTableSeeder.php` (defaults from SPEC §5.1), the priority change route + `TicketService::changePriority` (recompute due dates, write `priority` event).
- modify `TicketService::create` (sets due dates), `TicketService::transition` (on entering `resolved`, stores `state(ticket, resolved_at)` as `sla_state`), `tickets/show.blade.php` and `tickets/index.blade.php` (due times, SLA badge), `DatabaseSeeder.php`.

**Tests to write first:** `app/tests/unit/Sla/SlaCalculatorTest.php` with `Carbon::setTestNow`: urgent created 10:00 → response 10:30 and resolution 14:00; `ok` at 10:10; `warning` at 10:24 (80 %); `breached` at 10:31; the response clock stops once `first_responded_at` is set; worst-of-clocks rule; a response given after `response_due_at` keeps the response clock `breached`; a ticket resolved before `resolution_due_at` returns `ok` at any later `now`, one resolved after it returns `breached` (stopped clocks never report `warning`); null due dates count as `ok`. `app/tests/functional/PriorityChangeTest.php`: changing to urgent recomputes the due dates.

**Acceptance command:** `make test`

**Commit message:**
```
Compute SLA due dates and state from per-priority policies

Seeded response and resolution targets per priority, due dates set on
creation and on priority change, with warning and breach thresholds.
```

---

## T8 — SLA monitor command and scheduler container

**Goal:** `php artisan sla:check` updates `sla_state` and records events only on change; runs every minute in compose.

**Files:**
- create `app/Helpdesk/Sla/SlaMonitor.php` (`run(Carbon $now, $dryRun)` returns counts; fires `ticket.sla` with old/new state), `app/commands/SlaCheckCommand.php`, `app/tests/integration/SlaCheckMysqlTest.php` (extends `IntegrationTestCase`; inside the transaction gets its categories, users and SLA policies with `firstOrCreate` (policy keyed by the UNIQUE `priority`) and creates its own 3 open tickets).
- modify `app/start/artisan.php` (register), `docker-compose.yml` (`scheduler` service: same image, `sh -c 'while true; do php artisan sla:check; sleep 60; done'`).

**Tests to write first:** `app/tests/unit/Sla/SlaMonitorTest.php`: ok→warning writes a `sla_warning` event and fires once; running twice at the same time fires nothing the second time; warning→breached fires `sla_breached`; resolved and closed tickets are skipped (their stored `sla_state` is left as is); `--dry-run` writes nothing. `app/tests/functional/SlaCheckCommandTest.php`: `Artisan::call('sla:check')` output `checked 3, warning 1, breached 1` (sqlite, empty DB). Integration: the same scenario on MySQL, with assertions scoped to the 3 tickets it created: their `sla_state` values (`ok`/`warning`/`breached`) and the `ticket_events` rows whose `ticket_id` is one of theirs; the global `checked N` total is not asserted.

**Acceptance command:** `make test`

**Commit message:**
```
Add sla:check command and scheduler service

Evaluate open tickets against their SLA, record warning and breach
events once per state change, and run the check every minute.
```

---

## T9 — E-mail notifications for ticket and SLA events

**Goal:** send the notifications listed in SPEC §2 feature 5.

**Files:**
- create `app/Helpdesk/Notifications/TicketNotifier.php` (event subscriber: `subscribe($events)` for `ticket.created|assigned|commented|status|sla`; recipients as in the SPEC; never mails the actor about their own action; internal comments notify agents only), views `app/views/emails/ticket_created.blade.php`, `ticket_assigned`, `ticket_commented`, `ticket_status`, `ticket_sla` (plain HTML, link to ticket).
- modify `app/start/global.php` (`Event::subscribe('Helpdesk\Notifications\TicketNotifier')`), `app/config/mail.php` (`MAIL_HOST`, `MAIL_PORT`, `from`).
- create `app/config/integration/mail.php` (`pretend => true`): the integration suite already creates tickets (T8) and has no SMTP server until T10, which switches this file to `pretend => false`.
- Recipient rule detail: the creation acknowledgement goes to the requester even though they are the actor; every other mail skips the actor. Internal comments go to the assignee only.

**Tests to write first:** `app/tests/unit/Notifications/TicketNotifierTest.php` with a Mockery mock of `Illuminate\Mail\Mailer` bound in the IoC: created → requester + all agents; assigned → assignee only; a public comment by an agent → requester; a comment by the requester → assignee; an internal comment → no requester mail; SLA breach on an unassigned ticket → all agents; the subject contains the ticket number.

**Acceptance command:** `make test`

**Commit message:**
```
Send e-mail notifications for ticket and SLA events

Event subscriber that mails requesters, assignees and agents on
creation, assignment, comments, status changes and SLA alerts.
```

---

## T10 — Fake SMTP sink and mail integration test

**Goal:** a local SMTP stand-in that captures mail to files, and an integration test proving real SMTP delivery.

**Files:**
- create `docker/smtp-sink/Dockerfile` (`FROM python:2.7`), `docker/smtp-sink/sink.py` (subclass `smtpd.SMTPServer`; `process_message` writes `/var/mail-sink/<timestamp>-<n>.eml`; listens on 1025), `app/tests/integration/MailDeliveryTest.php` (extends `IntegrationTestCase`; gets its category and `carol` user with `firstOrCreate` keyed by name/`username`).
- modify `app/config/integration/mail.php` (`pretend => false`, host `smtp-sink`) (created in T9 with `pretend => true`), `docker-compose.yml` (service `smtp-sink`, named volume `mail-sink` mounted in `smtp-sink`, `app`, `scheduler` and test runs; `test` env adds `MAIL_HOST=smtp-sink`, `MAIL_PORT=1025`), `Makefile` (`mail-clean` target; `test-integration` waits for `smtp-sink`).

**Tests to write first:** `MailDeliveryTest`: empty the sink dir; create a ticket via `TicketService` as `carol`; within 5 s a `.eml` exists whose `To:` contains carol's address and whose `Subject:` contains the number of the ticket just created.

**Acceptance command:** `make test`

**Commit message:**
```
Add fake SMTP sink service and mail delivery integration test

Python 2.7 smtpd server that writes each message to a shared volume,
used to verify notifications leave the app over SMTP.
```

---

## T11 — Knowledge base with search and ticket linking

**Goal:** KB categories and articles, published/draft, keyword search, and linking to tickets.

**Files:**
- create migrations `…_create_kb_categories_table.php`, `…_create_kb_articles_table.php`, `…_create_ticket_kb_article_table.php`; models `KbCategory.php`, `KbArticle.php` (scope `published()`); `app/Helpdesk/Kb/ArticleSearch.php` (terms split on whitespace, AND of `LIKE` on title/body, published only unless agent); controllers `KbArticleController.php`, `KbCategoryController.php`; views `kb/index`, `kb/show`, `kb/form`, `kb/categories`; ticket show partial `tickets/_articles.blade.php`; `TicketService::linkArticle`.
- modify `app/routes.php`, `layouts/master.blade.php` (nav link), `DatabaseSeeder.php` (sample categories and articles).

**Tests to write first:** `app/tests/unit/Kb/ArticleSearchTest.php` (multi-term AND; case-insensitive; drafts are hidden from requesters and visible to agents); `app/tests/functional/KnowledgeBaseTest.php` (an agent creates and publishes; a requester cannot open `/kb/create` (403); a requester sees a published article but gets 404 on a draft; an agent links an article to a ticket and it shows on the ticket page with an `article_linked` event; body output is escaped, so `<script>` is rendered inert).

**Acceptance command:** `make test`

**Commit message:**
```
Add knowledge base articles with search and ticket links

Categorised articles with draft and published states, keyword search,
and agent linking of articles to tickets.
```

---

## T12 — Asset register, ticket linking and CSV import

**Goal:** track IT assets, link them to tickets, show each asset's ticket history, and bulk-import from CSV.

**Files:**
- create migration `…_create_assets_table.php` and `…_add_asset_id_to_tickets_table.php`; `app/models/Asset.php`; `app/controllers/AssetController.php` (resource; writes admin-only); views `assets/index`, `assets/show` (ticket history), `assets/form`; asset select on the ticket create form and the agent sidebar; `TicketService::linkAsset`;
  `app/Helpdesk/Assets/AssetCsvImporter.php` (upsert by `asset_tag`, validate status enum, resolve `assigned_username`, collect row errors), `app/commands/AssetsImportCommand.php`, `app/tests/fixtures/assets.csv` (valid, update and invalid rows).
- modify `app/routes.php`, `app/start/artisan.php`, `layouts/master.blade.php`.

**Tests to write first:** `app/tests/unit/Assets/AssetCsvImporterTest.php` (creates new rows, updates existing ones by tag, skips a bad status and reports its line number, unknown username → unassigned plus a warning, missing header → exception); `app/tests/functional/AssetTest.php` (an admin creates an asset; an agent cannot create one (403) but can view; linking an asset to a ticket shows the ticket on the asset page); `app/tests/functional/AssetsImportCommandTest.php` (`created 2, updated 1, skipped 1`).

**Acceptance command:** `make test`

**Commit message:**
```
Add asset register with ticket history and CSV import

Assets can be linked to tickets and show their ticket history; an
artisan command upserts assets from an inventory CSV export.
```

---

## T13 — Reporting dashboard and CSV export

**Goal:** the reports listed in SPEC §2 feature 7.

**Files:**
- create `app/Helpdesk/Reports/ReportService.php` (fetches tickets in range with plain Eloquent queries, no `TIMESTAMPDIFF` or sqlite date functions, and computes all durations and percentages in PHP with Carbon so sqlite and MySQL agree; `summary(Carbon $from, Carbon $to)` → counts by status/priority/category, created vs resolved, response and resolution SLA compliance % (met / measured, null when nothing measured), mean minutes to first response and to resolution, per-agent open/resolved), `app/Helpdesk/Reports/CsvExporter.php` (writes with `fputcsv` to `php://temp`), `app/controllers/ReportController.php`, `app/views/reports/index.blade.php` (date-range form, tables, progress bars), `app/tests/integration/ReportServiceMysqlTest.php` (same 6-ticket fixture on MySQL gives the same numbers as the unit test; categories, users and SLA policies via `firstOrCreate`; the fixture uses fixed 2014-01 timestamps and `summary()` is called for that month only, so the counts cover only rows the test created).
- modify `app/routes.php`, `layouts/master.blade.php`.

**Tests to write first:** `app/tests/unit/Reports/ReportServiceTest.php` (a fixture of 6 tickets with fixed timestamps → exact counts, compliance 66.7 %, mean times; the date range excludes outside tickets; an empty range gives zeros and null compliance); `CsvExporterTest.php` (header row, quoting of commas and quotes); `app/tests/functional/ReportTest.php` (a requester gets 403; an agent gets 200; export returns `Content-Type: text/csv` and `Content-Disposition` with the date range).

**Acceptance command:** `make test`

**Commit message:**
```
Add reporting dashboard and ticket CSV export

Volume, SLA compliance, response and resolution times, and per-agent
workload over a date range, with CSV export of the ticket list.
```

---

## T14 — Mobile-responsive pass across all screens

**Goal:** confirm every page works at phone width: collapsing nav, stacked forms, responsive tables, and touch-sized controls.

**Files:**
- modify all views under `app/views/{tickets,kb,assets,reports,home,auth}` (grid classes `col-xs-12 col-md-*`, `table-responsive` wrappers, `hidden-xs` on secondary columns, `btn-block` on phone forms), `public/css/app.css`.
- create `app/tests/functional/ResponsiveMarkupTest.php`.

**Tests to write first:** `ResponsiveMarkupTest` with a data provider over every GET page (as an agent): the response contains `<meta name="viewport" content="width=device-width, initial-scale=1">`, the `navbar-toggle` button with `data-target`, every `<table` is inside a `table-responsive` div (checked with `Symfony\Component\DomCrawler\Crawler`), and the bootstrap CSS/JS and `jquery-1.11.1.min.js` are referenced.

**Acceptance command:** `make test`

**Commit message:**
```
Make every screen usable on phone-width displays

Responsive grid classes, wrapped tables and a collapsing navbar across
all views, with a markup test covering each page.
```

---

## T15 — Demo data, full-stack MySQL integration and HTTP smoke test

**Goal:** a one-command demo, and integration coverage of migrations, seeds and a real HTTP round trip through Apache.

**Files:**
- create `app/database/seeds/DemoSeeder.php` (Faker 1.4 with a fixed seed: 3 categories, 10 assets, 25 tickets across statuses, KB articles; LDAP users pre-provisioned as `source=ldap` without passwords), `app/tests/integration/MigrateAndSeedTest.php`, `docker/smoke.sh` (curl: login page 200; POST login as `bob`/`password` via the LDAP simulator with cookie jar and CSRF token scraped; `/tickets` 200 containing `HD-`; `/reports` 200).
- modify `Makefile` (`demo`: `up` + `docker compose exec app php artisan migrate --force` (creates the `migrations` table on a new database; Laravel 4.2's `migrate:refresh` fails without it) then `php artisan migrate:refresh --seed --force` against `helpdesk`, so it can be re-run without duplicate-key errors; `up` also starts `ldap`, which the login needs; `smoke`: runs `docker/smoke.sh` in a curl-capable container on the compose network; `test-integration` runs `demo` then `smoke` after PHPUnit, because the smoke test logs in and lists tickets from the demo data), `DatabaseSeeder.php` (call `DemoSeeder` only when `App::environment('local')`).

**Tests to write first:** `MigrateAndSeedTest` (extends `IntegrationTestCase` with `$useTransaction = false`, since migrations commit implicitly on MySQL; runs against `helpdesk_test`): `migrate:refresh --seed` then `db:seed --class=DemoSeeder` (the integration env does not call `DemoSeeder` automatically) on MySQL succeeds; the row counts match the seeder; `ticket_events` exist for every ticket; a FULLTEXT-free LIKE search works under MySQL collation. Its `tearDown()` runs `Artisan::call('migrate:refresh')` without `--seed` before `parent::tearDown()` (an instance method rather than `tearDownAfterClass`, because the static hook has no booted app), so the classes that run after it (`MysqlConnectionTest`, `ReportServiceMysqlTest`, `SlaCheckMysqlTest`) start from an empty schema. `docker/smoke.sh` exits non-zero on any unexpected status.

**Acceptance command:** `make test && make demo && make smoke`

**Commit message:**
```
Add demo seed data and full-stack smoke test

Deterministic demo dataset, MySQL migrate-and-seed integration test,
and a curl smoke test logging in through the LDAP simulator.
```

---

## T16 — Regenerate README from the template

**Goal:** replace the "not implemented" README with an honest one built from `tools/readme_template.md`.

**Files:**
- modify `README.md`: title "IT Help Desk Ticketing System"; one-paragraph description; "Personal project built on the 2014-era stack (PHP 5.6, Laravel 4.2, MySQL 5.6, Bootstrap 3.2, jQuery 1.11)"; **Implemented** bullets = SPEC §2 features 1–8, each naming its code and test; **Not implemented / known limitations** = SPEC §3 and §11, including: "Active Directory is simulated with an OpenLDAP slapd container (`docker/ldap`); the SMTP relay is simulated by a Python smtpd sink (`docker/smtp-sink`); neither has been run against a real directory or mail server"; Built with = pinned versions from `composer.json`; Running it = `docker compose up` + `make demo && make smoke`; Tests = `make test` plus one sentence on coverage; Layout = output of `git ls-files | tree --fromfile` (or an equivalent script run in the container), pasted verbatim; the template's rules comment removed.
- add `docker/layout-tree.php`: renders `git ls-files` output as a tree (`git ls-files | php docker/layout-tree.php`); ReadmeTest includes it to rebuild the expected Layout block.
- modify `Makefile`: `test-unit` mounts the checkout's `.git` read-only at `/var/www/html/.git` for the `unit` suite, so the Layout check runs instead of being skipped (the image build excludes `.git`).
- Order of work: write `ReadmeTest.php` and the README, `git add -A`, then generate the Layout tree from `git ls-files` (so it includes `ReadmeTest.php`), paste it, `git add README.md`, run the tests, commit.
- No employer, role, dates, metrics, badges, or "Status: Complete".

**Tests to write first:** `app/tests/unit/ReadmeTest.php`: every backticked repo path in `README.md` exists; the README matches none of the generic patterns `/\*\*Role\*\*|\*\*Timeline\*\*|Status: Complete|uptime|MTTR|developed during|\d+\.\d+\s?%/i` (decimal percentages such as uptime figures; the 80 % SLA threshold stays allowed) (no employer or role names are written into the repo); the Layout block equals the current `git ls-files` tree (skipped when `.git` is not mounted).

**Acceptance command:** `make test && git ls-files | xargs -I{} test -e {}`

**Commit message:**
```
Rewrite README to document the working help desk system

Describe implemented features, simulated LDAP and SMTP services, how
to run and test the stack, and the file layout from git ls-files.
```

---

## T17 — Security and hygiene hardening

**Goal:** close the security findings of the whole-repository review without changing the features.

**Found and changed:**
- **Committed encryption key.** `.env.local.php`, `.env.testing.php` and `docker-compose.yml` carried one fixed `APP_KEY`; Laravel 4.2 decrypts and unserializes cookies with it, so a published key allows forged cookies and PHP object injection. Both files are removed (only `.env.local.php.example` is tracked, `/.env.*.php` is ignored); `docker/app/entrypoint.sh` (the image's entry point) writes a random 32-character key to the `app-secrets` volume on first start, the test runner gets a fresh key each run, `app/config/{testing,integration}/app.php` fall back to a random key, and `Helpdesk\Security\AppKey` stops the app from booting with a missing, short or published key.
- **Debug pages.** `app/config/local/app.php` had `debug => true` while compose published the port with `APP_ENV=local`. Debug is now off unless `APP_DEBUG=true`; the port is published on `127.0.0.1` only.
- **CSRF.** The filter compared with `!=` and was attached per route after `auth`/`ticket.access`, and sign-out was a GET. `Helpdesk\Security\CsrfToken::matches()` requires two non-empty strings and compares with `hash_equals`; `Route::when('*', 'csrf', ['post','put','patch','delete'])` covers every write route ahead of its own filters; `/logout` is a POST form in the navbar. A token mismatch renders a 403 page.
- **LDAP role mapping by group name.** `NativeLdapGateway` reduced group DNs to their first CN and searched the whole base DN, so a `helpdesk-admins` group in any OU granted admin. The role map is keyed by full normalised DNs (`LDAP_ROLE_MAP` = `dn:role;dn:role`), the gateway returns DNs and keeps only groups below `group_base_dn` (default `ou=groups,<base_dn>`), and the group search runs there only. Connection, service-bind and search failures throw `LdapUnavailableException` instead of looking like "no such user".
- **Stale agents kept receiving ticket mail.** Migration `2014_07_07_000000_add_account_state_to_users_table` adds `users.active`, `role_verified_at`, `last_login_at`. Staff notifications go only to active agents/admins whose role is local or was confirmed within `ldap.role_max_age_days` (30); an ineligible assignee is replaced by the agent list. A sign-in for a directory user whose entry is gone, ambiguous or disabled (AD `userAccountControl` bit 2) deactivates and demotes them; an outage only refuses the sign-in. `php artisan users:sync-roles` (`Helpdesk\Auth\RoleSync`, hourly in `scheduler`) re-checks agents/admins, looks everyone up before writing, and changes nothing on an outage. Deactivated users are signed out on their next request and are not offered as assignees.
- **Host header in links.** `URL::to()` used the request's `Host`, so a request with a forged `Host` put that host into notification links. `Helpdesk\Http\HostGuard` roots every URL at `app.url` (`APP_URL`, required in production) at boot and per request, and answers 400 for a `Host` that is not `app.url`'s host or in `TRUSTED_HOSTS` (compose: `localhost,127.0.0.1,app`).
- **CSV formula injection.** `CsvExporter::cell()` prefixes `'` to every string cell (header included) starting with `=`, `+`, `-`, `@`, tab or CR; numbers stay numbers. The export is `text/csv; charset=utf-8` as an attachment.
- **Secrets in logs.** PHP 5.6 traces contain call arguments, so the logged `PDOException` of a failed connection held the database password. `Helpdesk\Support\ExceptionLog` logs class, message, file, line and argument-free traces (previous exceptions included) and masks the app key and every configured password.
- **Unauthenticated package retrieval.** Both period images installed from `[trusted=yes] http://` archive sources (`--force-yes` on wheezy). A `debian:bookworm-slim` stage now runs `docker/debs/fetch_verified_debs.sh` on `docker/debs/app.list` / `ldap.list`: HTTPS-only downloads from snapshot.debian.org, `gpgv` against the archive and removed-keys keyrings (expired keys still verified; a modified Release must fail), then Packages and `.deb` SHA256 and size. The period stages remove all apt sources and `dpkg -i` the verified files; `php:5.6-apache` and `debian:wheezy` are pinned by digest and Composer's phar is checked against its published SHA256. The `ldap` service builds with the repository root as context.
- **Directory simulator.** `seed.ldif` adds `ou=delegated` with a same-named `helpdesk-admins` group (member `carol`) and a duplicated `dana`; the entrypoint stores the seed's SHA256 and reloads the directory from the package's empty database when the seed changes.
- **Error page with status 200.** Laravel 4.2 answers 200 "Error in exception handler" when an `App::error` handler throws; `make demo` ran artisan as root through `docker compose exec`, so a root-owned `laravel.log` made every later error a 200. The logging handler now catches its own failure (the 500 page is shown), `make demo` runs artisan as `www-data`, and processes started with `docker compose exec` (which skip the entry point) read the key through `APP_KEY_FILE` in `app/config/app.php`.
- Minor: non-string login fields fail the sign-in instead of raising an error; the smoke test checks that a foreign `Host` gets 400; the stock `@author` tag in `public/index.php` is gone.

**Files:** `Makefile`, `public/index.php`, `Dockerfile`, `docker/ldap/Dockerfile`, `docker/ldap/entrypoint.sh`, `docker/ldap/seed.ldif`, `docker/debs/*`, `docker/app/entrypoint.sh`, `docker-compose.yml`, `docker/smoke.sh`, `.gitignore`, `.env.local.php.example` (and removal of `.env.local.php`, `.env.testing.php`), `app/Helpdesk/{Security,Http,Support}/*`, `app/Helpdesk/Auth/*`, `app/Helpdesk/Notifications/TicketNotifier.php`, `app/Helpdesk/Reports/CsvExporter.php`, `app/commands/UsersSyncRolesCommand.php`, migration above, `app/models/User.php`, `app/routes.php`, `app/filters.php`, `app/start/{global,artisan}.php`, `app/config/{app,ldap}.php`, `app/config/{local,testing,integration}/app.php`, `app/config/testing/ldap.php`, controllers `Auth`, `Report`, `Ticket`, `app/views/layouts/master.blade.php`, `public/css/app.css`, `DemoSeeder.php`, `README.md`, `docs/SPEC.md`.

**Tests written first (seen failing against the previous code):** `app/tests/unit/Security/{BuildSourcesTest,SecretsAndExposureTest,CsrfTokenTest}.php`, `app/tests/unit/Auth/NativeLdapGatewayTest.php`, new cases in `RoleMapperTest`, `LdapUserProviderTest`, `TicketNotifierTest`, `CsvExporterTest`, `ReportTest`, `LdapDirectoryTest`, and `app/tests/functional/{CsrfTest,HostHeaderTest,SecretRedactionTest,UsersSyncRolesCommandTest}.php`.

**Acceptance command:** `make test`

**Commit message:**
```
Harden secrets, CSRF, LDAP roles and package retrieval

Generate APP_KEY per install, map roles by group DN, retire stale
agents, pin links to APP_URL, sanitise CSV cells, redact logged
secrets and install only gpg-verified Debian packages.
```
