# Changelog

All notable changes to claudephpframework are documented here starting with 
the core release.

---

## [3.2.0] — 2026-05-02 — Bootstrap unification

The framework's bootstrap flow used to require running
`mysql < database/install.sql` BEFORE `php artisan migrate` — the README's
Quick start glossed over this and any flow that programmatically provisioned
databases (e.g. multi-tenant SaaS spinning up tenant DBs at signup) had to
shell out to mysql for every new database. This release folds the baseline
schema into a proper migration, so a fresh install is now exactly:
`composer install && php artisan migrate`.

### Added
- `database/migrations/2026_04_20_000000_create_baseline_tables.php` — the
  baseline schema as a PHP migration. Up creates the 29 framework-core tables
  (users, roles, permissions, groups, content_items, sessions, audit_log,
  settings, menus, pages, faqs, etc.) and seeds the initial roles, admin
  permissions, default site settings, default menus, default policy pages,
  and demo FAQ. Down drops them in reverse-FK order. Content traced verbatim
  from the 2026-04-19 install.sql (which itself merged schema.sql,
  2fa_migration.sql, security_fixes_migration.sql,
  performance_indexes_migration.sql).

### Changed
- `core/Database/Migrator.php::install()` no longer needs to scan
  `database/*.sql` to grandfather legacy filenames as `batch = 0` records,
  because the legacy SQL files are gone from the migration path entirely.
  The grandfathering loop is now a no-op (its `glob('database/*.sql')`
  matches nothing) — left in place for backward compat and clarity rather
  than cleaned up, since deleting it adds nothing.
- `docs/modules.md` — removed the "Database setup: migrations vs install.sql"
  section. All schema changes now go through PHP migrations; `install.sql`
  is no longer mentioned as an alternative pattern.

### Removed
- `database/install.sql`, `database/schema.sql`, and the seven supplementary
  `*_migration.sql` files (2fa, security_fixes, performance_indexes,
  message_retry, owner_removal_outcome, unescape_user_data, webhook_channel)
  moved out of the migration path. They live at `docs/legacy/sql/` for
  historical reference only — nothing scans that directory anymore.

### Upgrading existing installs

If your database was bootstrapped via the old `install.sql` flow, your
`schema_migrations` table contains 9 batch-0 records keyed to the now-removed
`.sql` files (`install`, `schema`, `2fa_migration`, etc.). After updating to
this version, `php artisan migrate` will see the new
`2026_04_20_000000_create_baseline_tables` migration as pending and try to
run it — which will fail because the tables already exist.

Two paths:

**Option A (recommended for dev / no production data yet):** drop the
database, recreate it empty, and run `php artisan migrate`. The new baseline
migration will create everything from scratch.

**Option B (production / preserve data):** mark the new migration as
already-applied and clean up the legacy entries with this one-time SQL:

```sql
INSERT INTO schema_migrations (migration, batch)
  VALUES ('2026_04_20_000000_create_baseline_tables', 0);

DELETE FROM schema_migrations WHERE migration IN (
  'install', 'schema',
  '2fa_migration', 'security_fixes_migration',
  'performance_indexes_migration', 'message_retry_migration',
  'owner_removal_outcome_migration', 'unescape_user_data_migration',
  'webhook_channel_migration'
);
```

Run that, then `php artisan migrate:status` should show no pending
migrations and no orphan legacy entries.

---

## [3.1.0] — 2026-05-02 — Core (open-source release)

This release prepares the framework as two paired repositories: an open-source
**core** (this repo) and a proprietary **premium** module set. It also lays 
the foundation for a future hosted web-app builder by introducing per-tenant 
module entitlement.

### Repository structure
- 26 core modules
- Core modules ship in this repository under `modules/`; premium modules live
  in a separate proprietary repo with the same layout.
- `MODULE_PREMIUM_PATH` env var (or default sibling-checkout convention) tells
  the framework where to find premium modules. Boots cleanly without it.

### Framework runtime
- New `ModuleProvider::tier(): string` returns `'core'` (default) or
  `'premium'`. Premium modules pass through an `EntitlementCheck` contract
  during dependency resolution.
- `ModuleRegistry::discoverMany(array $paths)` scans multiple module roots.
  Autoloader keys off folder basename (not `name()`) so modules with
  underscored names like `import_export` autoload correctly.
- New `disabled_unlicensed` state in `module_status` for premium modules whose
  entitlement was denied. `/admin/modules` surfaces it with a distinct badge.
- New `Core\Module\EntitlementCheck` interface + `AlwaysGrantEntitlement`
  default implementation (grants every premium module physically on disk —
  right for self-hosted installs).
- `config/modules.php` controls the discovery paths array.

### Documentation
- `docs/modules.md` — canonical "how to build a module" guide. Covers the
  three-form naming convention (folder vs `name()` vs URL), every
  `ModuleProvider` hook, `register()` vs `boot()`, blocks/linkSources/
  gdprHandlers/retentionRules/settingsKeys, the `requires()` dep model, the
  tier rule, the permission-seed pattern, and common pitfalls.
- `docs/qa-checklist.md` + `docs/qa-process.md` — exhaustive per-module checks
  and a 6-section sequenced walkthrough for end-user implementers QA-ing
  their own install. Premium-only sections live in the parallel docs in
  the premium repository.

### Default content (compliance boilerplate)
- Three default policy pages now seed automatically on a fresh install at
  `/terms`, `/privacy`, and `/cookie-policy`. Source markdown lives at
  `database/seeds/policies/` (terms-of-service.md, privacy-policy.md,
  cookie-policy.md); the seed migration converts each via `Core\Support\Markdown`
  and inserts as a published Page admins can edit through `/admin/pages`.
- Each policy ships with a prominent "TEMPLATE — REVIEW BEFORE PUBLISHING"
  banner and `[BRACKETED]` placeholders for organisation-specific values
  (org name, contact email, jurisdiction, EU/UK Art. 27 representatives,
  cookie inventory). Generic but substantive — covers GDPR / UK GDPR
  legal bases, CCPA/CPRA opt-out (linked to `/do-not-sell`), COPPA age
  gate, the framework's actual data-collection profile, and the
  cookieconsent module's four-category model.
- The seed migration's `down()` preserves admin edits: the inserted body's
  SHA-256 is stored in `seo_keywords` under a `cphpfw:policy-seed:` prefix,
  and rollback only deletes rows whose body still matches that hash.

### Tests
- New `tests/Unit/Module/CorePremiumIntegrityTest.php` — CI gate that fails if
  any core module's `requires()` references a known premium name. Also catches
  modules in the core repo that accidentally declare `tier()='premium'`. A
  sync test (skipped in core-only CI) keeps the known-premium constant honest
  when the premium repo is mounted as a sibling checkout.

### Migrations
- `database/migrations/2026_05_02_100000_module_status_add_unlicensed_state.php`
  extends the `module_status.state` ENUM with `'disabled_unlicensed'`. Safe to
  run on a populated table — MySQL ENUM widening is non-blocking.
- `database/migrations/2026_05_02_200000_seed_default_policy_pages.php`
  seeds the three default policy pages (Terms / Privacy / Cookies) from
  the markdown sources under `database/seeds/policies/`. Idempotent on
  re-run; rollback skips rows whose body has been edited by an admin.

---
