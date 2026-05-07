# Claude — context for working on `claudephpframework`

This file orients Claude (Code or otherwise) to the framework's conventions and the things that aren't obvious from reading the code. Read this first; everything else is in the repo.

## What this is

A modular PHP MVC framework. Core distribution is MIT, ~26 modules covering compliance/security/site management/UI/notifications. A paired proprietary repo `claudephpframeworkpremium` (sibling checkout under `C:\www\`) ships ~21 premium modules. The framework's `MODULE_PREMIUM_PATH` resolution finds the premium sibling automatically, so an open-source-only install boots cleanly without it.

The `Core\Module\EntitlementCheck` contract gates premium modules. Default impl `AlwaysGrantEntitlement` lets every premium module that's on disk load. Hosted SaaS implementations (see `claudephpbuilder` sibling repo) bind a tenant-aware version.

## Project structure

```
app/                  Controllers + Views + Middleware + Models that ship as part of "the app" (not a module)
bin/                  artisan binary, sandbox tooling
config/               app.php, database.php, modules.php, payments.php, services.php, storage.php
core/                 framework runtime — container, router, auth, queue, migrator, module registry
database/migrations/  PHP migrations (baseline + incrementals)
database/central_migrations/  (NOT in framework — used by claudephpbuilder fork)
docs/                 modules.md is the single most important doc — read it before extending the framework
modules/              26 core modules — each a self-contained provider
public/               web root — index.php, .htaccess, assets/
routes/               web.php + api.php
storage/              logs, cache, uploads (gitignored)
tests/                PHPUnit Unit + Feature suites
```

## Conventions you'll get wrong if you don't know them

### Controllers
- **No constructor arguments.** Controllers are instantiated by the Router via `new $className()` (no DI). Pull dependencies inside the constructor via singletons: `Database::getInstance()`, `Auth::getInstance()`, `new SettingsService()`, `Container::global()->get(...)`. Examples: `app/Controllers/AuthController.php`.
- Methods take `Request $request` and return `Response`. URL parameters are NOT method args — read them via `$request->param(0)`, `$request->param(1)`, etc.
- Route registration uses the **string form** `'Modules\Foo\Controllers\BarController@method'`, NOT the array `[BarController::class, 'method']`. The router's `is_callable` branch fails on non-static methods of uninstantiated classes; the string-form branch instantiates correctly.

### Modules
- Folder naming: lowercase, no underscores (the autoloader maps `modules/<folder>/` → `Modules\<Folder>\`). Module-name returned by `name()` CAN have underscores; that's a separate identifier (used as view namespace + key in `module_status`).
- Modules contribute commands via `ModuleProvider::commands(): array`. The CommandRegistry instantiates them via `Container::make()` — so commands CAN take `Container $container` in their constructor.
- Module routes load BEFORE `routes/web.php` and BEFORE other modules in alphabetical filesystem order. **First match wins** in the Router — there's no fallthrough. To override another module's route, register the same path in a module that discovers earlier alphabetically.
- Tier rule: core modules MUST NOT depend on premium modules. Enforced by `tests/Unit/Module/CorePremiumIntegrityTest.php`.

### Database
- `Core\Database\Database` is a singleton. `Database::getInstance()` picks credentials from `$_ENV['DB_*']` at construction time.
- `Database::resetInstance()` (added 2026-05-02) discards the cached singleton so a fresh connection picks up newly mutated `$_ENV`. Used by multi-tenant hosts to swap connections mid-process.
- `core/bootstrap.php` calls `App\Tenancy\TenantResolver::resolve()` BEFORE Database is constructed, IF the class exists. This is the framework's hook for multi-tenant deployments — bare framework installs without the resolver class load exactly as before.
- Migrations use `$this->db->query("...")` for raw DDL. The Migrator base class and pattern are in `core/Database/Migrator.php` + `core/Database/Migration.php`.
- Baseline schema (29 framework tables + seed data) is in `database/migrations/2026_04_20_000000_create_baseline_tables.php` (folded in 2026-05-02, replaces the legacy `install.sql` flow).

### Settings
- Three scopes: `site`, `group`, `user`. `SettingsService::set($key, $value, $scope, $scopeId, $type)`.
- **PHP converts dots in $_POST keys to underscores** (register_globals legacy). Form input `theme.color.bg.page` lands at `$_POST['theme_color_bg_page']`. Account for this in any settings-form handler.
- Theme tokens are settings rows scoped `site`. ThemeService handles defaults + overrides + light/dark. `ThemeService::resolveTokens('light')` returns tokens keyed by CSS alias name (`bg-page`, `color-primary`), NOT framework key.

### Theme rendering on public views
- `app/Views/public/page.php` (the `/{slug}` renderer) emits `(new ThemeService(new SettingsService()))->renderOverrideStyle()` in `<head>`, AND uses `var(--*)` references throughout the inline shell CSS. Patched 2026-05-04 — without this the entire theme system was admin-chrome-only.
- A hook calls `App\Theme\BrandingRenderer::renderHead()` (provided by `claudephpbuilder`) for `@font-face` + tenant custom CSS injection. The `class_exists` check makes it passive on framework-only installs.

### CSRF
- `App\Middleware\CsrfMiddleware` checks `_token` form field FIRST, then `X-CSRF-Token` header (added 2026-05-02). For AJAX POSTs sending JSON, prefer the header. For multipart POSTs, append `_token` to FormData — the header path is flaky through some Apache configs.
- Returns 419 (plain text "CSRF token mismatch...") on failure. Watch for this when fetch() responses fail to JSON-parse — the body is HTML/text not JSON.

### Auth
- `Auth::getInstance()->attempt($email, $password)` is the credentialed login.
- `Auth::loginAs(int $userId, string $auditAction)` is the framework-blessed "trust me, log this user in" primitive (added 2026-05-02). For claim flows, magic links, OAuth bridges. Refuses `is_active=0` users.
- `Auth::devLoginAs($userId)` is the dev-only quick-login (gated to non-production).

### Asset URLs
- ONE `asset()` helper in `core/helpers.php` (deduped 2026-05-02 — there were two definitions colliding). Reads `$_ENV['ASSET_URL']` if set, else returns relative paths. Don't reintroduce a second helper; if you need to change behavior, edit the existing one.

### Router
- First match wins (`foreach ($this->routes)`). No fallthrough. Routes register in module-discovery order (alphabetical), then `routes/api.php`, then `routes/web.php`.
- URL parameters: `{id}` in route → `$request->param(0)` in handler.

### Validator
- `new Validator($request->post())` then `$v->validate(['key' => 'rule|rule:arg'])`.
- Rules include `required|email|alpha|alphanumeric|regex:/pattern/|same:field|in:a,b,c|nullable`.
- No `addError()` method. To add custom errors: `$errors = $v->errors(); $errors['field'][] = 'message';` then flash that combined map.

## Recent framework changes (2026-05-02 → 2026-05-04)

These all landed during construction of `claudephpbuilder`. Each was the smallest possible additive change that the builder needed:

| Change | File | Why |
|---|---|---|
| `Database::resetInstance()` | `core/Database/Database.php` | Multi-tenant DB swap |
| TenantResolver hook | `core/bootstrap.php` | Pre-Database tenant resolution |
| Baseline schema migration | `database/migrations/2026_04_20_000000_*.php` | Replace legacy install.sql |
| Removed legacy `.sql` grandfathering | `core/Database/Migrator.php` | Simplify migration flow |
| Auth::loginAs | `core/Auth/Auth.php` | Out-of-band login (claim flow) |
| X-CSRF-Token header support | `app/Middleware/CsrfMiddleware.php` | AJAX/JSON POSTs |
| Deduped asset() | `core/helpers.php` | Use ASSET_URL or relative |
| Public shell uses CSS vars | `app/Views/public/page.php` | Theme system applies to guest views |
| Tenant favicon link | `app/Views/public/page.php` + `app/Views/layout/header.php` | Per-tenant favicons |
| BrandingRenderer hook | `app/Views/public/page.php` + `app/Views/layout/header.php` | Per-tenant @font-face + custom CSS |
| `FileUploadService::uploadFile()` | `core/Services/FileUploadService.php` | Non-image uploads (fonts) |
| `pages.rendered_html` column | new migration | Section-based page composer |
| Pages admin index "Layout" link | `modules/pages/Views/admin/index.php` | Reach the layout editor |

If you make framework changes, sync them carefully — there's a sibling `claudephpbuilder` fork that mirrors every framework file and merges from `framework` remote.

## Pre-launch checklist

Standard. See README.md "Pre-launch checklist" section.

## Tests

```bash
vendor/bin/phpunit                              # everything
vendor/bin/phpunit --testsuite Unit             # fast unit tests only
vendor/bin/phpunit --filter CorePremium         # the tier-integrity gate
```

`bin/php` is a sandboxed PHP wrapper for environments without a system PHP. `bin/setup-php.sh` is the one-shot bootstrap.
