# IT Help Desk Ticketing System

A web application where staff report IT problems as tickets and an IT team works them through to resolution. Tickets carry a number, priority, category, requester, assignee and an optional linked asset; they move through a fixed status workflow with public and internal comments and a full event history. Each priority has response and resolution targets, and a scheduled command flags tickets that are close to or past them. Users sign in with their directory (LDAP) account, get e-mail as their tickets change, and can use every page from a phone. Agents also get a knowledge base, an asset register with CSV import, and a reporting dashboard with CSV export.

Personal project built on the 2014-era stack (PHP 5.6, Laravel 4.2, MySQL 5.6, Bootstrap 3.2, jQuery 1.11).

## Status

**Implemented**

- **Ticket management and tracking.** Create, list, filter, search and view tickets numbered `HD-000123`, status workflow `new → open → pending ↔ open → resolved → closed` with reopen, public and internal comments, an append-only event history, and requesters only seeing their own tickets. Code: `app/Helpdesk/Tickets/TicketService.php`, `app/Helpdesk/Tickets/StatusMachine.php`, `app/Helpdesk/Tickets/TicketNumber.php`, `app/controllers/TicketController.php`, `app/controllers/CommentController.php`. Tests: `app/tests/unit/Tickets/TicketServiceTest.php`, `app/tests/unit/Tickets/StatusMachineTest.php`, `app/tests/functional/TicketFlowTest.php`.
- **SLA monitoring and alerts.** Per-priority response and resolution targets, due times set on creation and recomputed when the priority changes, and `php artisan sla:check` marking tickets `warning` (80 % of the window used) or `breached`, recording an event and sending one alert per state change. The `scheduler` container runs it every minute. Code: `app/Helpdesk/Sla/SlaCalculator.php`, `app/Helpdesk/Sla/SlaMonitor.php`, `app/commands/SlaCheckCommand.php`. Tests: `app/tests/unit/Sla/SlaCalculatorTest.php`, `app/tests/unit/Sla/SlaMonitorTest.php`, `app/tests/functional/SlaCheckCommandTest.php`, `app/tests/integration/SlaCheckMysqlTest.php`.
- **Knowledge base.** Categories and articles with published/draft state; agents write and edit, everyone reads published articles; keyword search; agents link articles to tickets and the ticket page lists them. Code: `app/Helpdesk/Kb/ArticleSearch.php`, `app/controllers/KbArticleController.php`, `app/controllers/KbCategoryController.php`. Tests: `app/tests/unit/Kb/ArticleSearchTest.php`, `app/tests/unit/Kb/TicketArticleLinkTest.php`, `app/tests/functional/KnowledgeBaseTest.php`.
- **Asset tracking.** Asset register (tag, name, type, serial, location, status, assigned user), tickets linked to assets, each asset's ticket history, and `php artisan assets:import <file.csv>` to create or update assets from a CSV export. Code: `app/Helpdesk/Assets/AssetCsvImporter.php`, `app/commands/AssetsImportCommand.php`, `app/controllers/AssetController.php`. Tests: `app/tests/unit/Assets/AssetCsvImporterTest.php`, `app/tests/unit/Assets/TicketAssetLinkTest.php`, `app/tests/functional/AssetTest.php`, `app/tests/functional/AssetsImportCommandTest.php`.
- **E-mail notifications.** SMTP mail through Laravel `Mail` when a ticket is created, assigned, commented on publicly, changes status, or reaches an SLA warning or breach. Staff mail goes only to active agents and admins whose role is local or was confirmed by the directory in the last 30 days (`LDAP_ROLE_MAX_AGE_DAYS`); links in every message start with `APP_URL`, whatever `Host` the triggering request used. Code: `app/Helpdesk/Notifications/TicketNotifier.php`, templates in `app/views/emails`. Tests: `app/tests/unit/Notifications/TicketNotifierTest.php`, `app/tests/integration/MailDeliveryTest.php`.
- **Mobile-responsive interface.** Bootstrap 3.2 grid, collapsing navbar, `table-responsive` lists and a viewport meta tag on every page, laid out for screens down to 320 px wide. Code: `app/views/layouts/master.blade.php`, `public/css/app.css`. Tests: `app/tests/functional/ResponsiveMarkupTest.php`.
- **Reporting and analytics.** Dashboard for a date range: tickets by status, priority and category, created vs resolved, SLA compliance for response and resolution, mean time to first response and to resolution, per-agent counts, and a CSV export of the tickets in the range; exported cells that start with `=`, `+`, `-`, `@`, tab or carriage return get a leading `'` so spreadsheets do not run them as formulas. Code: `app/Helpdesk/Reports/ReportService.php`, `app/Helpdesk/Reports/CsvExporter.php`, `app/controllers/ReportController.php`. Tests: `app/tests/unit/Reports/ReportServiceTest.php`, `app/tests/unit/Reports/CsvExporterTest.php`, `app/tests/functional/ReportTest.php`, `app/tests/integration/ReportServiceMysqlTest.php`.
- **LDAP authentication.** Simple-bind sign-in with a configurable user filter (`(uid=%s)` for OpenLDAP, `(sAMAccountName=%s)` for Active Directory), user provisioning on first login, and local bcrypt accounts as a fallback. Roles come from full group DNs: `LDAP_ROLE_MAP` is `dn:role;dn:role` (e.g. `CN=Helpdesk Admins,OU=Helpdesk Groups,DC=corp,DC=example:admin`), compared case-insensitively, and only groups below `LDAP_GROUP_BASE_DN` (default `ou=groups,<base DN>`) count, so a group with the same name elsewhere grants nothing. A user whose directory entry is gone, ambiguous or disabled is deactivated at their next sign-in attempt, and `php artisan users:sync-roles` (hourly in the `scheduler` container) demotes or deactivates agents and admins the directory no longer backs; a directory outage changes nothing. Code: `app/Helpdesk/Auth/LdapUserProvider.php`, `app/Helpdesk/Auth/NativeLdapGateway.php`, `app/Helpdesk/Auth/RoleMapper.php`, `app/Helpdesk/Auth/RoleSync.php`, `app/commands/UsersSyncRolesCommand.php`, `app/config/ldap.php`. Tests: `app/tests/unit/Auth/LdapUserProviderTest.php`, `app/tests/unit/Auth/RoleMapperTest.php`, `app/tests/unit/Auth/NativeLdapGatewayTest.php`, `app/tests/functional/LdapLoginTest.php`, `app/tests/functional/UsersSyncRolesCommandTest.php`, `app/tests/integration/LdapDirectoryTest.php`.
- **Request and secret handling.** Every POST, PUT, PATCH and DELETE needs the session's CSRF token (strict, constant-time comparison); sign-out is a POST; requests for a `Host` other than `APP_URL`'s host or `TRUSTED_HOSTS` get 400; no `APP_KEY` is stored in the repository (the app container generates one per install) and the app refuses to start without one; error pages are generic unless `APP_DEBUG=true`; logged exceptions carry no call arguments and configured passwords are masked. Code: `app/filters.php`, `app/Helpdesk/Security/CsrfToken.php`, `app/Helpdesk/Http/HostGuard.php`, `app/Helpdesk/Security/AppKey.php`, `docker/app/entrypoint.sh`, `app/Helpdesk/Support/ExceptionLog.php`. Tests: `app/tests/functional/CsrfTest.php`, `app/tests/unit/Security/CsrfTokenTest.php`, `app/tests/functional/HostHeaderTest.php`, `app/tests/unit/Security/SecretsAndExposureTest.php`, `app/tests/functional/SecretRedactionTest.php`.
- **Verified image packages.** The Debian packages of the app and directory images are downloaded over HTTPS from snapshot.debian.org and checked (Release signature with `gpgv` against the Debian archive keyrings, then index and package SHA256) before `dpkg` installs them; apt is not used in the period images. Code: `docker/debs/fetch_verified_debs.sh`, `docker/debs/app.list`, `docker/debs/ldap.list`, `Dockerfile`, `docker/ldap/Dockerfile`. Tests: `app/tests/unit/Security/BuildSourcesTest.php`.

**Not implemented / known limitations**

- Active Directory is simulated with an OpenLDAP slapd container (`docker/ldap`); the SMTP relay is simulated by a Python smtpd sink (`docker/smtp-sink`); neither has been run against a real directory or mail server.
- No inbound e-mail (IMAP): tickets are created through the web form only.
- No live link to an external asset or inventory product; the CSV import command is the integration point.
- SLA clocks run on 24x7 wall-clock time, with no business-hours or holiday calendar, and they do not pause while a ticket is `pending`.
- `sla:check` runs from a sleep loop in the `scheduler` container, not from cron or a queue worker.
- Notifications are sent synchronously, so a slow or unavailable SMTP server slows down the request that triggered them.
- Reports are tables, progress bars and CSV; there are no charts.
- No file attachments, no REST API, and no password reset for directory users (they change passwords in the directory).
- Configuration uses environment variables (and optionally Laravel 4.2's `.env.<environment>.php` arrays, see `.env.local.php.example`), not a .env file.
- The compose stack uses lab default credentials (MySQL `helpdesk`/`helpdesk` and `root`/`root`, LDAP `cn=admin`/`admin`, the local `admin`/`admin` account, directory users with `password`), serves plain HTTP without TLS, and publishes the app on 127.0.0.1 only.
- Roles change at sign-in and at the hourly `users:sync-roles` run; in between, a user removed from a directory group keeps the role in the app (staff mail stops once the role is older than 30 days).
- PHP 5.6, Laravel 4.2 and MySQL 5.6 are end-of-life and have known vulnerabilities. This must not be exposed to the internet.
- Composer 2.2 LTS installs the dependencies, because Composer 1 can no longer read Packagist; every installed package is a 2014 release.
- The `mysql:5.6` image and the Debian wheezy LDAP image are amd64-only and run under emulation on ARM hosts.
- The Debian packages in the images are verified downloads but old and unpatched releases (stretch 9.13, wheezy 7.11).

## Built with

- **PHP 5.6** (`php:5.6-apache` image) — laravel/framework v4.2.8, swiftmailer/swiftmailer v5.2.1, monolog/monolog 1.10.0, nesbot/carbon 1.11.0, symfony components v2.5.3/v2.5.4, filp/whoops 1.1.2, predis/predis v0.8.7, phpseclib/phpseclib 0.3.7, ircmaxell/password-compat 1.0.1 (all pinned in `composer.json`, locked in `composer.lock`)
- **Front end** — twbs/bootstrap v3.2.0, jQuery 1.11.1 (`public/js/jquery-1.11.1.min.js`)
- **Database** — MySQL 5.6 (`mysql:5.6` image); sqlite in memory for unit and functional tests
- **Directory simulator** — OpenLDAP slapd 2.4.31 on Debian wheezy (`docker/ldap/Dockerfile`)
- **Mail sink** — Python 2.7 `smtpd` (`docker/smtp-sink/sink.py`)
- **Tests** — phpunit/phpunit 4.2.4, mockery/mockery 0.9.1, fzaninotto/faker v1.4.0

## Running it

Everything runs in Docker images from the project's era, so nothing needs installing locally beyond Docker.

```bash
docker compose up        # start the stack
make demo && make smoke  # load the demo data, then sign in over HTTP and open tickets and reports
```

The app listens on http://localhost:20680 (loopback only). On first start the app container writes a random `APP_KEY` to the `app-secrets` volume; set `APP_URL` (and `TRUSTED_HOSTS` for other host names) when the app is reached under another address, and `APP_DEBUG=true` only for a development session. The demo directory users are `alice` (admin), `bob` (agent) and `carol` (requester), all with the password `password`; the local fallback account is `admin` / `admin`. Captured e-mail lands as .eml files in the `mail-sink` volume.

## Tests

```bash
make test                # runs the suite inside the period image
```

Unit and functional tests cover the ticket workflow, SLA maths, LDAP provisioning against an in-memory directory, notifications, the knowledge base, assets, reports and the responsive markup on sqlite; the integration suite runs against MySQL 5.6, the slapd simulator and the SMTP sink, followed by the demo load and HTTP smoke test. Rendering in real browsers and behaviour against a real directory or mail relay are not tested.

## Layout

The tree below is `git ls-files` rendered by `docker/layout-tree.php`; `app/tests/unit/ReadmeTest.php` checks that it is current.

```
.
├── .dockerignore
├── .env.local.php.example
├── .gitignore
├── Dockerfile
├── Makefile
├── README.md
├── app
│   ├── Helpdesk
│   │   ├── Assets
│   │   │   ├── AssetCsvImporter.php
│   │   │   └── CsvFormatException.php
│   │   ├── Auth
│   │   │   ├── FakeLdapGateway.php
│   │   │   ├── LdapGateway.php
│   │   │   ├── LdapServiceProvider.php
│   │   │   ├── LdapUnavailableException.php
│   │   │   ├── LdapUserProvider.php
│   │   │   ├── NativeLdapGateway.php
│   │   │   ├── RoleMapper.php
│   │   │   └── RoleSync.php
│   │   ├── Http
│   │   │   └── HostGuard.php
│   │   ├── Kb
│   │   │   └── ArticleSearch.php
│   │   ├── Notifications
│   │   │   └── TicketNotifier.php
│   │   ├── Reports
│   │   │   ├── CsvExporter.php
│   │   │   └── ReportService.php
│   │   ├── Security
│   │   │   ├── AppKey.php
│   │   │   └── CsrfToken.php
│   │   ├── Sla
│   │   │   ├── SlaCalculator.php
│   │   │   └── SlaMonitor.php
│   │   ├── Support
│   │   │   └── ExceptionLog.php
│   │   └── Tickets
│   │       ├── InvalidTransitionException.php
│   │       ├── StatusMachine.php
│   │       ├── TicketNumber.php
│   │       └── TicketService.php
│   ├── commands
│   │   ├── .gitkeep
│   │   ├── AssetsImportCommand.php
│   │   ├── SlaCheckCommand.php
│   │   └── UsersSyncRolesCommand.php
│   ├── config
│   │   ├── app.php
│   │   ├── auth.php
│   │   ├── cache.php
│   │   ├── compile.php
│   │   ├── database.php
│   │   ├── integration
│   │   │   ├── app.php
│   │   │   ├── database.php
│   │   │   ├── ldap.php
│   │   │   └── mail.php
│   │   ├── ldap.php
│   │   ├── local
│   │   │   └── app.php
│   │   ├── mail.php
│   │   ├── queue.php
│   │   ├── remote.php
│   │   ├── services.php
│   │   ├── session.php
│   │   ├── testing
│   │   │   ├── app.php
│   │   │   ├── cache.php
│   │   │   ├── database.php
│   │   │   ├── ldap.php
│   │   │   ├── mail.php
│   │   │   └── session.php
│   │   ├── view.php
│   │   └── workbench.php
│   ├── controllers
│   │   ├── .gitkeep
│   │   ├── AssetController.php
│   │   ├── AuthController.php
│   │   ├── BaseController.php
│   │   ├── CommentController.php
│   │   ├── HomeController.php
│   │   ├── KbArticleController.php
│   │   ├── KbCategoryController.php
│   │   ├── ReportController.php
│   │   └── TicketController.php
│   ├── database
│   │   ├── migrations
│   │   │   ├── .gitkeep
│   │   │   ├── 2014_06_02_000000_create_users_table.php
│   │   │   ├── 2014_06_09_000000_create_categories_table.php
│   │   │   ├── 2014_06_09_000100_create_sla_policies_table.php
│   │   │   ├── 2014_06_09_000200_create_tickets_table.php
│   │   │   ├── 2014_06_09_000300_create_ticket_comments_table.php
│   │   │   ├── 2014_06_09_000400_create_ticket_events_table.php
│   │   │   ├── 2014_06_23_000000_create_kb_categories_table.php
│   │   │   ├── 2014_06_23_000100_create_kb_articles_table.php
│   │   │   ├── 2014_06_23_000200_create_ticket_kb_article_table.php
│   │   │   ├── 2014_06_30_000000_create_assets_table.php
│   │   │   ├── 2014_06_30_000100_add_asset_id_to_tickets_table.php
│   │   │   └── 2014_07_07_000000_add_account_state_to_users_table.php
│   │   └── seeds
│   │       ├── .gitkeep
│   │       ├── CategoryTableSeeder.php
│   │       ├── DatabaseSeeder.php
│   │       ├── DemoSeeder.php
│   │       ├── KbTableSeeder.php
│   │       ├── SlaPolicyTableSeeder.php
│   │       └── UserTableSeeder.php
│   ├── filters.php
│   ├── lang
│   │   └── en
│   │       ├── pagination.php
│   │       ├── reminders.php
│   │       └── validation.php
│   ├── models
│   │   ├── Asset.php
│   │   ├── Category.php
│   │   ├── KbArticle.php
│   │   ├── KbCategory.php
│   │   ├── SlaPolicy.php
│   │   ├── Ticket.php
│   │   ├── TicketComment.php
│   │   ├── TicketEvent.php
│   │   └── User.php
│   ├── routes.php
│   ├── start
│   │   ├── artisan.php
│   │   ├── global.php
│   │   └── local.php
│   ├── storage
│   │   ├── .gitignore
│   │   ├── cache
│   │   │   └── .gitignore
│   │   ├── logs
│   │   │   └── .gitignore
│   │   ├── meta
│   │   │   └── .gitignore
│   │   ├── sessions
│   │   │   └── .gitignore
│   │   └── views
│   │       └── .gitignore
│   ├── tests
│   │   ├── IntegrationTestCase.php
│   │   ├── TestCase.php
│   │   ├── fixtures
│   │   │   ├── ReportFixture.php
│   │   │   └── assets.csv
│   │   ├── functional
│   │   │   ├── .gitkeep
│   │   │   ├── AssetTest.php
│   │   │   ├── AssetsImportCommandTest.php
│   │   │   ├── AuthTest.php
│   │   │   ├── CsrfTest.php
│   │   │   ├── HostHeaderTest.php
│   │   │   ├── KnowledgeBaseTest.php
│   │   │   ├── LdapLoginTest.php
│   │   │   ├── PriorityChangeTest.php
│   │   │   ├── ReportTest.php
│   │   │   ├── ResponsiveMarkupTest.php
│   │   │   ├── SecretRedactionTest.php
│   │   │   ├── SlaCheckCommandTest.php
│   │   │   ├── TicketFlowTest.php
│   │   │   └── UsersSyncRolesCommandTest.php
│   │   ├── integration
│   │   │   ├── LdapDirectoryTest.php
│   │   │   ├── MailDeliveryTest.php
│   │   │   ├── MigrateAndSeedTest.php
│   │   │   ├── MysqlConnectionTest.php
│   │   │   ├── ReportServiceMysqlTest.php
│   │   │   └── SlaCheckMysqlTest.php
│   │   └── unit
│   │       ├── Assets
│   │       │   ├── AssetCsvImporterTest.php
│   │       │   └── TicketAssetLinkTest.php
│   │       ├── Auth
│   │       │   ├── LdapUserProviderTest.php
│   │       │   ├── NativeLdapGatewayTest.php
│   │       │   └── RoleMapperTest.php
│   │       ├── EnvironmentTest.php
│   │       ├── Kb
│   │       │   ├── ArticleSearchTest.php
│   │       │   ├── KbTableSeederTest.php
│   │       │   └── TicketArticleLinkTest.php
│   │       ├── Notifications
│   │       │   └── TicketNotifierTest.php
│   │       ├── ReadmeTest.php
│   │       ├── Reports
│   │       │   ├── CsvExporterTest.php
│   │       │   └── ReportServiceTest.php
│   │       ├── Security
│   │       │   ├── BuildSourcesTest.php
│   │       │   ├── CsrfTokenTest.php
│   │       │   └── SecretsAndExposureTest.php
│   │       ├── Sla
│   │       │   ├── SlaCalculatorTest.php
│   │       │   ├── SlaMonitorTest.php
│   │       │   ├── SlaPolicyTableSeederTest.php
│   │       │   └── TicketServiceSlaTest.php
│   │       ├── Tickets
│   │       │   ├── CategoryTableSeederTest.php
│   │       │   ├── StatusMachineTest.php
│   │       │   ├── TicketNumberTest.php
│   │       │   └── TicketServiceTest.php
│   │       └── UserTest.php
│   └── views
│       ├── assets
│       │   ├── _status_label.blade.php
│       │   ├── form.blade.php
│       │   ├── index.blade.php
│       │   └── show.blade.php
│       ├── auth
│       │   └── login.blade.php
│       ├── emails
│       │   ├── _footer.blade.php
│       │   ├── _header.blade.php
│       │   ├── auth
│       │   │   └── reminder.blade.php
│       │   ├── ticket_assigned.blade.php
│       │   ├── ticket_commented.blade.php
│       │   ├── ticket_created.blade.php
│       │   ├── ticket_sla.blade.php
│       │   └── ticket_status.blade.php
│       ├── home
│       │   └── index.blade.php
│       ├── kb
│       │   ├── _errors.blade.php
│       │   ├── categories.blade.php
│       │   ├── form.blade.php
│       │   ├── index.blade.php
│       │   └── show.blade.php
│       ├── layouts
│       │   └── master.blade.php
│       ├── reports
│       │   ├── _breakdown.blade.php
│       │   ├── _compliance.blade.php
│       │   └── index.blade.php
│       └── tickets
│           ├── _articles.blade.php
│           ├── _asset.blade.php
│           ├── _sla_badge.blade.php
│           ├── _status_label.blade.php
│           ├── _table.blade.php
│           ├── create.blade.php
│           ├── index.blade.php
│           └── show.blade.php
├── artisan
├── bootstrap
│   ├── autoload.php
│   ├── paths.php
│   └── start.php
├── composer.json
├── composer.lock
├── docker-compose.yml
├── docker
│   ├── apache
│   │   └── 000-default.conf
│   ├── app
│   │   └── entrypoint.sh
│   ├── debs
│   │   ├── app.list
│   │   ├── fetch_verified_debs.sh
│   │   └── ldap.list
│   ├── layout-tree.php
│   ├── ldap
│   │   ├── Dockerfile
│   │   ├── entrypoint.sh
│   │   └── seed.ldif
│   ├── mysql
│   │   └── init
│   │       └── 01-test-db.sql
│   ├── php
│   │   └── php.ini
│   ├── smoke.sh
│   └── smtp-sink
│       ├── Dockerfile
│       └── sink.py
├── docs
│   ├── IMPLEMENTATION_PLAN.md
│   └── SPEC.md
├── phpunit.xml
├── public
│   ├── .htaccess
│   ├── css
│   │   └── app.css
│   ├── favicon.ico
│   ├── index.php
│   ├── js
│   │   └── jquery-1.11.1.min.js
│   └── robots.txt
└── server.php
```
