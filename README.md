# claudephpframework

A modular PHP MVC framework built around an explicit module system, opinionated
defaults for security and compliance, and a runtime designed to make adding
features feel like editing a folder rather than rewiring an application.

This repository is the **open-source core**. A separate, paid repository ships 
additional modules (commerce, social, scheduling, etc.) that can be loaded 
alongside core when present. Core is fully self-contained — every install boots 
cleanly without the premium repo.

## What ships in core

26 modules covering the surfaces every web application eventually needs:

- **Compliance** — GDPR DSARs, CCPA opt-outs, COPPA age-gates, cookie consent,
  versioned Terms/Privacy with required acceptance, time-based retention sweeps,
  email suppressions and unsubscribe handling, WCAG 2.1 AA static linting.
- **Security** — HMAC-sealed audit log with read-only viewer, login anomaly
  detection (IP geolocation), HIBP password breach checking, scoped API keys.
- **Site management** — administrable Pages with SEO metadata, drag-and-drop
  Menus over hierarchical link sources, FAQ, taxonomies (multi-vocabulary
  classification with adjacency + closure-table hybrid), feature flags, runtime
  settings, third-party integration credential vault, CSV/JSON import-export,
  user profiles.
- **UI primitives** — Site Blocks (HTML, hero, image, video, CTA, login,
  register, newsletter forms) and Core Blocks (admin observability tiles) for
  the page composer.
- **Notifications** — per-user inbox with bell endpoint, email + in-app
  channels.

Plus the framework runtime: container, router, migrations, queue, scheduler,
cache, mail, SMS, file storage, search, payments-gateway interface, sessions
(file or DB), themes (40 tokens × 10 groups, dark mode), CSRF, RBAC, 2FA
(email / SMS / TOTP), OAuth (Google / Microsoft / Apple / Facebook / LinkedIn).

## Requirements

| Dependency       | Minimum                      |
|------------------|------------------------------|
| PHP              | 8.1+                         |
| MySQL / MariaDB  | 8.0+ / 10.6+                 |
| Composer         | 2.x                          |
| Web server       | Apache or Nginx              |
| PHP extensions   | `dom` `json` `mbstring` `openssl` `pdo` `pdo_mysql` `sodium` `gd` |

## Quick start

```bash
git clone https://github.com/your-org/claudephpframework.git
cd claudephpframework

cp .env.example .env
# Edit .env — at minimum set DB_*, APP_URL, APP_KEY (32+ random chars)

composer install
php artisan migrate

# Point your web server's document root at /public/
# A working nginx vhost is in nginx.conf.example
```

The first migration creates a default superadmin you'll be prompted to claim
on first login. Change the password immediately.

## Architecture

```
claudephpframework/
├── app/                 controllers + views + middleware that ship with core
├── bin/                 artisan binary, sandbox tooling
├── config/              app, database, modules, payments, services, storage
├── core/                framework runtime — container, router, auth, queue,
│                        migrator, module registry, services, cache, mail
├── database/migrations/ schema migrations + seed migrations
├── docs/                developer documentation (start with modules.md)
├── modules/             26 core modules — each a self-contained provider
├── public/              web root (index.php, .htaccess, assets/)
├── routes/              web.php + api.php
├── storage/             logs, cache, uploads (gitignored)
└── tests/               PHPUnit Unit + Feature suites
```

The single most-important file to read before extending the framework is
[`docs/modules.md`](docs/modules.md). It documents the `ModuleProvider` hooks,
the three-form naming convention (folder vs `name()` vs URL), how
routes/views/migrations/blocks/permissions wire together, and the rules around
the core/premium tier split.

## Module system

Every feature in the framework is a module under `modules/<name>/`. A module
contributes a `module.php` file that returns a `Core\Module\ModuleProvider`
instance. The provider declares routes, views, migrations, blocks for the page
composer, link sources for the menu builder, GDPR handlers for the DSAR
pipeline, retention rules for time-based purging, and any other framework
hook the module needs.

Adding a new module is the same as creating a folder. The registry's filesystem
scan picks it up on the next request — no config edits, no service location.

## Core and premium

Modules declare their distribution tier:

```php
public function tier(): string { return 'core'; }     // default
public function tier(): string { return 'premium'; }  // proprietary repo
```

Premium modules pass through an `EntitlementCheck` contract during dependency
resolution. The default `AlwaysGrantEntitlement` grants everything that's
physically on disk — right for self-hosted installs. The hosted [Claude PHP
Builder](https://github.com/) (in development) swaps in a tenant-aware
implementation that gates premium modules per subscription.

The framework enforces one strict invariant: **core modules MUST NOT depend on
premium modules**. A unit test in `tests/Unit/Module/CorePremiumIntegrityTest.php`
fails CI if any core module's `requires()` references a known premium name —
catching the violation before it can break the open-source install.

## Running the test suite

```bash
vendor/bin/phpunit                              # everything
vendor/bin/phpunit --testsuite Unit             # fast unit tests only
vendor/bin/phpunit --filter CorePremium         # the tier-integrity gate
```

A working sandbox PHP wrapper lives at `bin/php` for environments without a
system PHP install. See `bin/setup-php.sh` for one-shot bootstrap.

## Built-in artisan commands

```bash
php artisan migrate              # run pending migrations
php artisan migrate:status       # show applied vs pending
php artisan module:cache         # write storage/cache/modules.php manifest
php artisan module:clear         # remove the manifest (forces filesystem scan)
php artisan schedule:run         # master cycle for scheduled tasks
php artisan queue:work           # consume the database-backed job queue
php artisan cleanup              # purge stale sessions / tokens / nonces
```

## Pre-launch checklist

- `APP_KEY` is 32+ random characters
- `APP_ENV=production`, `APP_DEBUG=false`
- HTTPS configured; HSTS uncommented in `.htaccess`
- `TRUSTED_PROXY=` set only if behind a load balancer
- All migrations applied (`php artisan migrate:status` shows nothing pending)
- Cron job: `*/15 * * * * php /path/to/artisan cleanup`
- Cron job: `* * * * * php /path/to/artisan schedule:run`
- Queue worker running under supervisord or systemd
- At least one email driver configured in `.env`
- `public/.well-known/security.txt` updated with your contact email

## Documentation

- [`docs/modules.md`](docs/modules.md) — How to build a module
- [`docs/payments-adding-a-gateway.md`](docs/payments-adding-a-gateway.md) — Payment gateway driver pattern
- [`SECURITY.md`](SECURITY.md) — Security policy and vulnerability reporting
- [`CHANGELOG.md`](CHANGELOG.md) — Release history

## Contributing

PRs welcome for bugs and core-tier features. New modules with a generic
audience also welcome — see `docs/modules.md` for conventions. Domain-specific
or commercial features tend to fit better in the premium repo.

If you're not sure whether a contribution belongs in core or premium, open an
issue first.

## License

[MIT](LICENSE) — copyright © 2026 Will Groberg.

The premium repository is proprietary and licensed separately. Nothing in this
core repository depends on the premium modules; running, modifying, and
redistributing this code under MIT terms does not require any premium licence.
