# helpdesk-system — Specification

Rebuild target: a working IT help desk ticketing web application on the mid-2014 PHP stack
(PHP 5.6, Laravel 4.2, MySQL 5.6, Bootstrap 3.2, jQuery 1.11). Period window: 2014-06-01 to 2014-08-31.
Every dependency is pinned to a release dated on or before 2014-08-31.

## 1. Problem

A small IT team needs one place where staff can report IT problems and the IT team can track them
through to resolution: who reported it, who is working on it, how urgent it is, whether it is late
against an agreed response/resolution time, which piece of equipment it concerns, and which
known fix (knowledge-base article) applies. Staff should sign in with their existing directory
(LDAP/Active Directory) account, be notified by e-mail as their ticket progresses, and use the
system from a phone as well as a desktop. The team lead needs simple reports on volume and SLA
compliance.

## 2. In scope (numbered features)

1. **Ticket management and tracking** — create, view, list, filter and search tickets; ticket
   number `HD-000123`; subject, description, category, priority, requester, assignee, optional
   linked asset; status workflow `new → open → pending ↔ open → resolved → closed` (reopen
   `resolved → open`); public and internal comments; an append-only event history per ticket;
   role-based visibility (requesters see only their own tickets).
2. **SLA monitoring and alerts** — SLA policy per priority (first-response and resolution
   minutes); due timestamps computed at creation and recomputed on priority change;
   `php artisan sla:check` flags tickets as `warning` (≥ 80 % of the window used) or `breached`,
   records an event, and sends a single alert e-mail per state change; run every minute by a
   `scheduler` container.
3. **Knowledge base** — categories and articles (title, body, published/draft, author); agents
   create/edit, everyone reads published articles; keyword search; an agent can link an article
   to a ticket, which is shown on the ticket page.
4. **Asset tracking integration** — asset register (asset tag, name, type, serial, location,
   status, assigned user); tickets may reference an asset; asset page lists its ticket history;
   `php artisan assets:import <file.csv>` bulk-imports/updates assets from a CSV export (the
   integration surface for an external inventory).
5. **E-mail notification system** — SMTP e-mail (Swift Mailer via Laravel `Mail`) on: ticket
   created (requester + agents), ticket assigned (assignee), public comment added (other party),
   status changed (requester), SLA warning/breach (assignee, or all agents if unassigned).
6. **Mobile-responsive interface** — Bootstrap 3.2 grid, collapsing navbar, `table-responsive`
   lists, viewport meta; every page usable at 320 px width.
7. **Reporting and analytics** — dashboard for agents/admins over a date range: tickets by
   status, priority and category; created vs resolved; SLA compliance % (response and
   resolution); mean time to first response and to resolution; per-agent open/resolved counts;
   CSV export of the ticket list for the range.
8. **LDAP authentication** — sign-in by LDAP simple bind (configurable user filter so
   `(uid=%s)` for OpenLDAP or `(sAMAccountName=%s)` for Active Directory), first-login user
   provisioning, group-to-role mapping (`admin`, `agent`, `requester`), fallback to local
   database accounts (bcrypt) for break-glass admin.

## 3. Out of scope (with reason)

| Item from original README | Reason |
|---|---|
| IMAP support (inbound e-mail → ticket) | Needs a mailbox server, MIME parsing and threading heuristics; too large for a solo core. Tickets are created through the web form only; outbound SMTP is in scope. |
| Real Active Directory | Proprietary/unavailable; simulated by an OpenLDAP `slapd` container with AD-style groups (see §8). The same code path works against AD by changing the user filter. |
| Live integration with an external asset/inventory product | No specific product named; the CSV import command is the integration point. |
| Business-hours / holiday SLA calendars | Adds a calendar engine; SLA clocks run on wall-clock time (24×7). |
| SLA clock pause while `pending` | Kept simple: the clock does not pause; listed as a known limitation. |
| Charts/graphs in reports | Reports are tables and Bootstrap progress bars; a charting library adds a JS build step without changing the analytics. |
| `.env` file + `php artisan key:generate` flow as written in the old README | That is the Laravel 5 convention; Laravel 4.2 uses `.env.php` / `.env.<environment>.php` arrays and environment variables. `key:generate` is not used: in 4.2 it string-replaces a literal key inside `app/config/app.php`, which cannot work once `app.php` reads `getenv('APP_KEY')`. The key comes from `.env.local.php` (sample value, not a secret). |
| Employer/role/timeline/"Status: Complete" claims | Not technical scope; not reproduced anywhere. |

## 4. Architecture

```
                 browser (desktop / phone)
                          |
                          v  HTTP :8080
  +---------------------------------------------------------+
  | app  (php:5.6-apache, Laravel 4.2)                      |
  |  routes.php -> filters (auth, role) -> controllers      |
  |  controllers -> services (app/Helpdesk/*) -> Eloquent   |
  |  Blade views + Bootstrap 3.2 + jQuery 1.11.1            |
  |  Event::listen('ticket.*') -> TicketNotifier -> Mail    |
  +------+-------------------+-------------------+----------+
         | PDO mysql         | ext-ldap           | SMTP :1025
         v                   v                    v
  +-------------+   +--------------------+  +-----------------------+
  | db          |   | ldap (SIMULATED AD)|  | smtp-sink (FAKE SMTP) |
  | mysql:5.6   |   | debian:wheezy +    |  | python:2.7 smtpd,     |
  | helpdesk DB |   | slapd 2.4.31, LDIF |  | writes .eml files     |
  +-------------+   +--------------------+  +-----------------------+
         ^
         | same image as app, loop: php artisan sla:check; sleep 60
  +------+------------------+
  | scheduler               |
  +-------------------------+
```

Components (all under `app/`):

- `app/routes.php`, `app/filters.php` — routing; `auth`, `guest`, `csrf`, `role:agent`, `role:admin` filters.
- `app/controllers/` — `AuthController`, `TicketController`, `CommentController`,
  `KbArticleController`, `KbCategoryController`, `AssetController`, `ReportController`,
  `HomeController`.
- `app/models/` — Eloquent models (see §5).
- `app/Helpdesk/` (PSR-0 namespace `Helpdesk\`, registered in composer `autoload`):
  - `Auth/LdapGateway.php` (interface), `Auth/NativeLdapGateway.php` (ext-ldap),
    `Auth/FakeLdapGateway.php` (in-memory, tests), `Auth/LdapUserProvider.php`
    (Laravel `UserProviderInterface`, registered as auth driver `ldap`), `Auth/RoleMapper.php`.
  - `Tickets/TicketService.php` (create, assign, comment, transition, link asset/article),
    `Tickets/StatusMachine.php` (allowed transitions), `Tickets/TicketNumber.php`.
  - `Sla/SlaCalculator.php` (due dates, state), `Sla/SlaMonitor.php` (used by `sla:check`).
  - `Notifications/TicketNotifier.php` (event subscriber → `Mail::send`).
  - `Assets/AssetCsvImporter.php`.
  - `Reports/ReportService.php`, `Reports/CsvExporter.php`.
  - `Kb/ArticleSearch.php`.
- `app/commands/` — `SlaCheckCommand` (`sla:check`), `AssetsImportCommand` (`assets:import`).
- `app/views/` — Blade layout `layouts/master.blade.php` + per-feature views; `emails/*` templates.

Data flow for a ticket: form POST → `TicketController@store` → `TicketService::create`
(assigns number, calls `SlaCalculator` for due dates, writes `tickets` + `ticket_events`) →
`Event::fire('ticket.created')` → `TicketNotifier` → SMTP. `sla:check` reads open tickets,
asks `SlaCalculator::state()`, updates `sla_state`, writes an event and fires `ticket.sla`
only on a state change.

## 5. Data model & interfaces

### 5.1 MySQL schema (Laravel 4.2 migrations, InnoDB, utf8_unicode_ci)

| Table | Columns |
|---|---|
| `users` | id, username (unique), name, email, password (nullable, bcrypt; null for LDAP-only), role enum(`requester`,`agent`,`admin`), source enum(`local`,`ldap`), remember_token, timestamps |
| `categories` | id, name (unique), timestamps |
| `sla_policies` | id, priority enum(`low`,`normal`,`high`,`urgent`) unique, response_minutes int, resolution_minutes int, timestamps |
| `assets` | id, asset_tag (unique), name, type, serial (nullable), location (nullable), status enum(`in_use`,`in_stock`,`repair`,`retired`), assigned_user_id (nullable FK users), timestamps |
| `tickets` | id, number (unique, `HD-%06d`), subject, description text, status enum(`new`,`open`,`pending`,`resolved`,`closed`), priority enum, category_id FK, requester_id FK users, assignee_id nullable FK users, asset_id nullable FK assets, response_due_at nullable, resolution_due_at nullable (set by `SlaCalculator` from T7 on; null only for rows created before SLA policies exist), first_responded_at nullable, resolved_at nullable, closed_at nullable, sla_state enum(`ok`,`warning`,`breached`) default `ok`, timestamps; indexes on (status), (assignee_id), (sla_state) |
| `ticket_comments` | id, ticket_id FK, user_id FK, body text, is_internal bool, timestamps |
| `ticket_events` | id, ticket_id FK, user_id nullable FK, type (`created`,`assigned`,`status`,`priority`,`comment`,`sla_warning`,`sla_breached`,`asset_linked`,`article_linked`), from_value nullable, to_value nullable, created_at |
| `kb_categories` | id, name (unique), timestamps |
| `kb_articles` | id, kb_category_id FK, author_id FK users, title, body text, is_published bool, timestamps |
| `ticket_kb_article` | ticket_id FK, kb_article_id FK, primary(ticket_id, kb_article_id) |

Default SLA seed: urgent 30 / 240 min; high 60 / 480; normal 240 / 1440; low 480 / 4320.

### 5.2 Status machine

```
new      -> open, resolved, closed
open     -> pending, resolved
pending  -> open, resolved
resolved -> open (reopen), closed
closed   -> (terminal)
```
Assigning a `new` ticket moves it to `open`. The first public comment by an agent sets
`first_responded_at`. Entering `resolved` sets `resolved_at`; reopen clears it.

### 5.3 SLA state rule

For each clock (response until `first_responded_at`, resolution until `resolved_at`):

- **Running clock** (stop timestamp not set): `elapsed = now - created_at`, `window = due - created_at`.
  `breached` if now > due; `warning` if elapsed ≥ 0.8 × window; else `ok`.
- **Stopped clock** (stop timestamp set): `breached` if the stop timestamp > due, else `ok`
  (a stopped clock never reports `warning`).
- A clock whose due timestamp is null counts as `ok`.

`SlaCalculator::state(ticket, now)` = worst of both clocks. It therefore returns the *final* state for a
resolved/closed ticket: one resolved after `resolution_due_at` stays `breached`, one resolved in time is `ok`.
When a ticket enters `resolved`, `TicketService` stores `state(ticket, resolved_at)` as its `sla_state`.
`SlaMonitor` (`sla:check`) skips `resolved` and `closed` tickets; a reopened ticket is evaluated again.

### 5.4 HTTP routes

| Method | Path | Filter | Action |
|---|---|---|---|
| GET/POST | `/login` | guest(+csrf) | `AuthController@getLogin/postLogin` |
| GET | `/logout` | auth | `AuthController@getLogout` |
| GET | `/` | auth | dashboard (my tickets / queue) |
| GET | `/tickets` | auth | list: `?status=&priority=&category=&assignee=&q=&page=` |
| GET/POST | `/tickets/create`, `/tickets` | auth | new ticket |
| GET | `/tickets/{number}` | auth + owner-or-agent | show |
| POST | `/tickets/{number}/comments` | auth + owner-or-agent | add comment (`is_internal` agents only) |
| POST | `/tickets/{number}/assign` | role:agent | assign |
| POST | `/tickets/{number}/status` | auth + owner-or-agent | transition; `TicketService` allows a requester only `resolved → open` / `resolved → closed` on their own ticket (anything else → 403) |
| POST | `/tickets/{number}/priority` | role:agent | change priority (recomputes SLA) |
| POST | `/tickets/{number}/asset` | role:agent | link asset |
| POST | `/tickets/{number}/articles` | role:agent | link KB article |
| GET | `/kb`, `/kb/{id}` | auth | browse/search (`?q=`), read |
| GET/POST/PUT | `/kb/create`, `/kb`, `/kb/{id}/edit`, `/kb/{id}` | role:agent | author |
| resource | `/assets` | role:agent (index/show), role:admin (write) | asset register |
| GET | `/reports?from=YYYY-MM-DD&to=YYYY-MM-DD` | role:agent | dashboard |
| GET | `/reports/export.csv?from=&to=` | role:agent | CSV |

### 5.5 Artisan commands

```
php artisan migrate --seed                 # schema + SLA defaults + demo data (local env)
php artisan sla:check [--dry-run]          # evaluate SLA states; prints "checked N, warning W, breached B"
php artisan assets:import path/to.csv      # upsert by asset_tag; prints "created C, updated U, skipped S"
```
CSV header: `asset_tag,name,type,serial,location,status,assigned_username` (UTF-8, comma).

### 5.6 Configuration (environment variables read in `app/config/*.php`)

| Variable | Default (docker) | Used in |
|---|---|---|
| `APP_ENV` | `local` | `bootstrap/start.php` environment detection |
| `APP_KEY` | 32-char string in `.env.local.php` | `app/config/app.php` |
| `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `db`/`helpdesk`/`helpdesk`/`helpdesk` | `database.php` |
| `DB_TEST_DATABASE` | `helpdesk_test` | `app/config/integration/database.php` (integration suite only) |
| `MAIL_HOST`/`MAIL_PORT`/`MAIL_FROM` | `smtp-sink`/`1025`/`helpdesk@helpdesk.local` | `mail.php` |
| `LDAP_HOST`/`LDAP_PORT` | `ldap`/`389` | `ldap.php` |
| `LDAP_BASE_DN` | `dc=helpdesk,dc=local` | `ldap.php` |
| `LDAP_BIND_DN`/`LDAP_BIND_PASSWORD` | `cn=admin,dc=helpdesk,dc=local`/`admin` | service bind for search |
| `LDAP_USER_FILTER` | `(uid=%s)` | AD: `(sAMAccountName=%s)` |
| `LDAP_ROLE_MAP` | `helpdesk-admins:admin,helpdesk-agents:agent` | `RoleMapper` |

The LDAP implementation is chosen by the `ldap.driver` config key (`native` in `app/config/ldap.php`,
`fake` in `app/config/testing/ldap.php`); there is no separate on/off switch. Local database accounts
always work as the fallback (§2 feature 8).

`app/config/testing/*` overrides: sqlite `:memory:`, `mail.pretend = true`, `ldap.driver = fake`.
`app/config/integration/*` overrides: MySQL via `DB_*` but with `database => getenv('DB_TEST_DATABASE') ?: 'helpdesk_test'`,
`ldap.driver = native` (host `ldap`), `mail.pretend = false` (host `smtp-sink`).

Two MySQL databases on the one `db` server keep test data and demo data apart:

| Database | Used by | Lifecycle |
|---|---|---|
| `helpdesk` | `app`, `scheduler`, `make demo`, `make smoke` | `make demo` runs `migrate:refresh --seed` (local env → `DemoSeeder`), so it is reset on every demo run |
| `helpdesk_test` | integration suite only (`IntegrationTestCase`) | created by `docker/mysql/init/01-test-db.sql`; each test runs in a rolled-back transaction; the one committing test (`MigrateAndSeedTest`) leaves an empty migrated schema behind |

Environment of the compose `test` service (the only service that runs PHPUnit): `DB_HOST=db`, `DB_DATABASE=helpdesk`,
`DB_TEST_DATABASE=helpdesk_test`, `DB_USERNAME=helpdesk`, `DB_PASSWORD=helpdesk`, `APP_KEY` (same sample key as `.env.local.php`,
since no `.env.integration.php` is loaded), `LDAP_HOST=ldap`, `MAIL_HOST=smtp-sink`, `MAIL_PORT=1025`. Unit and functional tests ignore
the `DB_*` values (sqlite `:memory:` from `app/config/testing/database.php`).

Test environments: Laravel 4.2's stock `TestCase` forces `$app['env'] = 'testing'` whatever `APP_ENV` says,
and in the `testing` environment `RoutingServiceProvider` disables route filters. Therefore:
unit/functional tests extend `TestCase`, whose `setUp()` calls `Route::enableFilters()` and `Session::start()`;
integration tests extend `IntegrationTestCase`, whose `createApplication()` sets `$testEnvironment = 'integration'`
so the MySQL, slapd and smtp-sink configs are actually used.

### 5.7 LdapGateway interface

```php
interface LdapGateway {
    /** @return array|null ['dn'=>..,'username'=>..,'name'=>..,'email'=>..,'groups'=>[cn,..]] */
    public function findUser($username);
    /** @return bool true if a simple bind with $dn/$password succeeds */
    public function bind($dn, $password);
}
```

## 6. Stack & pinned versions

All Composer packages below were checked with `tools/check_period.py` (exit 0, 47 packages,
all released on or before 2014-08-31). A full `composer update` run against the pinned manifest
produced a 47-package lock with no package newer than 2014-08-31. Transitive dependencies are pinned
explicitly in `composer.json` because the loose constraints in Laravel's own manifest would otherwise
resolve to 2015–2022 releases.

| Component | Version | Released | Why it was the popular choice then |
|---|---|---|---|
| PHP | 5.6 (image ships 5.6.40) | 5.6.0: 2014-08-28 | Kept because the project concept names PHP 5.6. 5.6.0 was released three days before the window ends; PHP 5.5 (and 5.4) was the mainstream production line in Jun–Aug 2014. The image's 5.6.40 is a post-period (2019) patch build of the same minor line; the code uses nothing beyond the 5.6.0 feature set. |
| laravel/framework | v4.2.8 | 2014-08-05 | Laravel 4.2 (June 2014) was the fastest-growing PHP framework of 2014; Laravel 5 did not ship until Feb 2015. |
| MySQL | 5.6 (image ships 5.6.51) | 5.6 GA: 2013-02-05 | Default LAMP database in 2014; 5.6 added InnoDB full-text. 5.6.51 is a post-period (2021) patch build of the same minor line. |
| twbs/bootstrap | v3.2.0 | 2014-06-26 | Most-used CSS framework in 2014; 3.x is mobile-first. |
| jQuery (vendored file) | 1.11.1 | 2014-05-01 | Bootstrap 3 JS requires jQuery; the 1.x line kept IE8 support. |
| swiftmailer/swiftmailer | v5.2.1 | 2014-06-13 | Mailer bundled by Laravel 4.2 `Mail`. |
| monolog/monolog | 1.10.0 | 2014-06-04 | Laravel 4 logging. |
| nesbot/carbon | 1.11.0 | 2014-08-26 | Laravel 4 date handling; `Carbon::setTestNow` for SLA tests. |
| symfony/* (browser-kit, console, css-selector, debug, dom-crawler, event-dispatcher, filesystem, finder, http-foundation, http-kernel, process, routing, security-core, translation) | v2.5.3–v2.5.4 (filesystem v2.5.3, debug v2.5.4, …) | 2014-07-09 … 2014-08-31 | Required `2.5.*` by Laravel 4.2. |
| classpreloader 1.0.2, d11wtq/boris v1.0.7, filp/whoops 1.1.2, ircmaxell/password-compat 1.0.1, jeremeamia/superclosure 1.0.1, nikic/php-parser v0.9.5, patchwork/utf8 v1.1.25, phpseclib 0.3.7, predis v0.8.7, psr/log 1.0.0, stack/builder v1.0.2 | as listed | 2012-12-21 … 2014-08-05 | Laravel 4.2 runtime dependencies. |
| phpunit/phpunit | 4.2.4 | 2014-08-31 | Standard PHP test runner; Laravel 4.2 `TestCase` extends it. |
| phpunit support libs (php-code-coverage 2.0.11, php-file-iterator 1.3.4, php-text-template 1.2.0, php-timer 1.0.5, php-token-stream 1.3.0, phpunit-mock-objects 2.2.0, ocramius/instantiator 1.1.3, ocramius/lazy-map 1.0.0, sebastian/comparator 1.0.1, diff 1.2.0, environment 1.0.1, exporter 1.0.1, version 1.0.3, symfony/yaml v2.5.4) | as listed | 2013-08-02 … 2014-08-31 | PHPUnit 4.2 dependencies. |
| mockery/mockery | 0.9.1 | 2014-05-02 | Mocking library used by Laravel's own docs/tests. |
| fzaninotto/faker | v1.4.0 | 2014-06-04 | De-facto fake-data generator for seeds/tests. |
| OpenLDAP slapd (Debian wheezy package) | 2.4.31 | 2012-04-21 | Standard free LDAP server of the time; stands in for Active Directory. |
| Python (SMTP sink) | 2.7 | 2.7: 2010-07-03 | `smtpd` module in the stdlib; Python 2.7 was the default in 2014. |
| Composer (build tool only) | 2.2.24 LTS | 2024-06-10 | **Deviation:** Packagist has shut down the Composer 1 metadata API, so 2014-era Composer builds cannot install anymore. Composer 2.2 LTS is the last line supporting PHP 5.3+. It only installs the pinned 2014 packages; nothing from it ends up in the app. |

Required PHP extensions: `mcrypt` (Laravel 4.2 Encrypter), `pdo_mysql`, `pdo_sqlite` (tests), `ldap`.

The exact composer.json manifest that passed the gate is reproduced in `IMPLEMENTATION_PLAN.md` T1.

## 7. Docker images (tags verified on Docker Hub, HTTP 200)

| Service | Image | Notes |
|---|---|---|
| app, scheduler, test runner | `php:5.6-apache` | Debian stretch base; apt sources must point at `archive.debian.org` (see deviation below). Multi-arch. |
| db | `mysql:5.6` | amd64 only → `platform: linux/amd64` in compose (emulated on Apple Silicon). Env `MYSQL_DATABASE=helpdesk`, `MYSQL_USER`/`MYSQL_PASSWORD=helpdesk`; `docker/mysql/init/` mounted at `/docker-entrypoint-initdb.d`, whose `01-test-db.sql` creates `helpdesk_test` (utf8_unicode_ci) and `GRANT ALL ON helpdesk_test.* TO 'helpdesk'@'%'`. Init scripts run only when the data volume is first created (`make down -v` re-runs them). |
| ldap (simulator) | built `FROM debian:wheezy` | Debian 7, slapd 2.4.31 from `archive.debian.org`. No arm64 image (386, amd64, armv5, armv7 only) → `platform: linux/amd64` on the `ldap` service too. `osixia/openldap` has no 2014-era tag (oldest remaining is 1.2.4, OpenLDAP 2.4.47), so a period Debian base is used instead. |
| smtp-sink (fake) | built `FROM python:2.7` | Runs `docker/smtp-sink/sink.py`. |

**Deviation — unauthenticated archive apt sources.** The `debian-archive-keyring` inside `php:5.6-apache`
(stretch) and `debian:wheezy` only holds keys that have since expired, and archive.debian.org now also signs
with newer keys those images lack, so a normal `apt-get update` fails with "invalid signature / not signed".
Both Dockerfiles therefore mark the archive source as trusted and ignore the expired `Valid-Until`:

```
# app (stretch)
echo 'deb [trusted=yes] http://archive.debian.org/debian stretch main' > /etc/apt/sources.list
# ldap (wheezy, apt 0.9.7 also honours [trusted=yes])
echo 'deb [trusted=yes] http://archive.debian.org/debian wheezy main' > /etc/apt/sources.list
apt-get -o Acquire::Check-Valid-Until=false update
```

(fallback if `[trusted=yes]` is ignored: `-o Acquire::AllowInsecureRepositories=true` plus `--allow-unauthenticated`
on stretch, `--force-yes` on wheezy). Package integrity then rests on HTTP from archive.debian.org only; acceptable
for a local period reconstruction, not for production. `make build` in T1 (app) and T4 (ldap) proves it works.

Pinned-patch tags `php:5.6.0-apache` and `mysql:5.6.20` also exist but are very old images that Docker 29 may refuse to pull. Using them is optional; the floating `5.6` tags are the default.

## 8. Simulated / mocked integrations

| Real system | Replacement | Where |
|---|---|---|
| Active Directory / corporate LDAP | **OpenLDAP slapd simulator** (`docker/ldap/`), seeded from `docker/ldap/seed.ldif` with users `alice` (admin), `bob` (agent), `carol` (requester) and groups `helpdesk-admins`, `helpdesk-agents` | integration tests, demo |
| LDAP in unit tests | **`Helpdesk\Auth\FakeLdapGateway`** (in-memory directory) | `app/tests/unit` |
| Corporate SMTP relay | **smtp-sink fake SMTP server** (Python 2.7 `smtpd`, writes each message to `/var/mail-sink/*.eml`, shared volume) | integration tests, demo |
| Mail in unit/functional tests | Laravel `Mail::pretend(true)` + Mockery expectations on `TicketNotifier` | `app/tests` |
| External asset inventory | **CSV export file** fed to `assets:import` (`app/tests/fixtures/assets.csv`) | tests, demo |

The final README must state that the directory and mail server are simulated and the system has never been run against a real AD or mail relay.

## 9. Existing code inventory (`git ls-files`)

| File | Decision | Reason |
|---|---|---|
| `README.md` | refactor | Honest "not implemented" note today; regenerated from `tools/readme_template.md` in the final task once the code exists. |

There is no other tracked file. `docs/SPEC.md` and `docs/IMPLEMENTATION_PLAN.md` (this plan) are added by the planning step.

## 10. Test strategy

- **Runner:** PHPUnit 4.2.4 via `vendor/bin/phpunit` inside the `php:5.6-apache`-based image. `make test` =
  `make test-unit` (runs `--testsuite unit` then `--testsuite functional` as two PHPUnit calls, because PHPUnit 4.2
  accepts one suite name per call; sqlite `:memory:`, no network) then
  `make test-integration` (suite `integration`, brings up `db`, `ldap`, `smtp-sink` with docker compose).
  All suites run in the one compose service `test` (app image).
- **Base classes:** `app/tests/TestCase.php` (env `testing`, re-enables route filters and starts the session so
  auth/role/csrf filters are really exercised; POSTs send `_token => Session::token()`) and
  `app/tests/IntegrationTestCase.php` (env `integration`, database `helpdesk_test`, never the demo database `helpdesk`;
  runs `migrate` once per class and wraps each test in a transaction that is rolled back; tests that run DDL themselves
  opt out and must leave an empty migrated schema behind).
- **Test data isolation:** integration fixtures are created with `firstOrCreate` (categories, users, SLA policies,
  whose `priority` is UNIQUE), and every count assertion is scoped to rows the test itself created (by id or
  by a fixture-only date range), so results do not depend on PHPUnit's alphabetical file order or on leftovers.
- **Unit** (`app/tests/unit`): `StatusMachine` transitions, `TicketNumber` formatting, `SlaCalculator`
  (due dates per priority, warning/breach thresholds, time frozen with `Carbon::setTestNow`),
  `SlaMonitor` idempotence, `RoleMapper`, `LdapUserProvider` with `FakeLdapGateway`
  (bad password, unknown user, provisioning, role update, local fallback), `AssetCsvImporter`
  (create/update/skip bad rows), `ReportService` aggregates on fixture data, `CsvExporter`,
  `ArticleSearch`. `ReportService` fetches timestamps with portable queries and does all duration and
  compliance maths in PHP, so the sqlite unit results hold on MySQL (also checked by an integration test).
- **Functional** (`app/tests/functional`): Laravel `$this->call()` against routes with sqlite:
  login/logout, filters (requester cannot see others' tickets, requester cannot post internal
  comments), ticket create/assign/comment/transition, KB CRUD + search, asset CRUD and link,
  report page and CSV headers, responsive markup (viewport meta, `navbar-toggle`, `table-responsive`).
  Notifications asserted via Mockery on the mailer.
- **Integration** (`app/tests/integration`, `@group integration`): migrations + seed against MySQL 5.6
  (`SELECT VERSION()` starts with `5.6`); LDAP bind/search/group mapping against the slapd
  simulator; creating a ticket results in an `.eml` in the smtp-sink volume with the right
  recipient and subject; `sla:check` against MySQL; HTTP smoke with `curl` against the running
  apache container (`make smoke`).
- Not tested: visual rendering in real browsers, behaviour against a real AD or mail relay, load.

## 11. Known limitations that will remain

- Active Directory and SMTP are simulated; no real directory or mail relay has been used.
- No inbound e-mail (IMAP); tickets are created only via the web UI.
- SLA clocks are 24×7 wall-clock time and do not pause while a ticket is `pending`.
- `sla:check` runs from a sleep loop in a container, not a hardened cron/queue setup; Laravel 4.2 has no built-in scheduler.
- Notifications are sent synchronously (`sync` queue driver); an SMTP outage slows requests.
- Reports are tables and CSV only, no charts.
- No file attachments, no REST API, no password-reset flow for LDAP users (they reset in the directory).
- PHP 5.6, Laravel 4.2 and MySQL 5.6 are end-of-life and have known vulnerabilities. This is a period reconstruction and must not be exposed to the internet.
- Composer 2.2 LTS is used as the install tool (see §6); all installed packages are 2014 releases.
- `mysql:5.6` and the `debian:wheezy` LDAP simulator are amd64-only; on ARM hosts they run under emulation and are slower.
- Period Debian packages are installed from archive.debian.org with signature checks relaxed (§7 deviation).
