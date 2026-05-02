# Changelog

All notable changes to claudephpframework are documented here starting with 
the core release.

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