# Building modules for claudephpframework

This document is the canonical reference for adding a new module to the framework.
Read it once before you build your first module; refer back to the section on whichever
hook you're wiring up next. Each section explains both what to write and *why* the
framework expects it that way — knowing the why lets you make good calls in the cases
this guide doesn't anticipate.

The framework currently ships with 47 modules across two repos:

- **claudephpframework** (open source) — 26 core modules. Compliance, security, basic
  UI, site management. Things any web app benefits from.
- **claudephpframeworkpremium** (proprietary) — 21 premium modules. Commerce, social,
  scheduling, specialised feature surfaces. Loaded only when both files are on disk
  AND the `EntitlementCheck` contract grants them.

Module structure is identical across the two tiers — the only difference is one line
in the provider (see [Tier](#tier-core-vs-premium)).

## Quick start: minimal module

A module is a folder under `modules/` containing at minimum a `module.php` that returns
a `ModuleProvider` instance. The shortest possible example:

```
modules/
└── greeter/
    └── module.php
```

```php
<?php
// modules/greeter/module.php
use Core\Module\ModuleProvider;
use Core\Router\Router;
use Core\Container\Container;

return new class extends ModuleProvider {
    public function name(): string { return 'greeter'; }

    public function boot(Router $router, Container $c): void {
        $router->get('/hello', function () {
            return \Core\Response::text('Hello from the greeter module.');
        });
    }
};
```

Drop those four lines into `modules/greeter/module.php`, hit `/hello`, and the framework
runs your route. No registration step — the registry's filesystem scan finds you on the
next request.

## Folder structure

A typical module looks like this:

```
modules/<folder>/
├── module.php              ← required: returns a ModuleProvider
├── routes.php              ← optional: HTTP routes
├── Controllers/            ← optional: controller classes
├── Services/               ← optional: domain logic / repositories
├── LinkSources/            ← optional: menu-builder palette contributions
├── Views/                  ← optional: PHP view templates
│   ├── admin/
│   └── public/
└── migrations/             ← optional: DB migrations
```

Nothing in this layout is enforced beyond `module.php`. The other folders are conventions
the existing modules follow so the next person reading your code can predict where things
live.

## Naming conventions (three distinct forms)

This is the trickiest part of building a module. **Three different names describe the
same module, and getting one wrong costs a bootstrap failure or autoload miss.**

| Form              | Where it lives                            | Allowed chars       | Example                  |
|-------------------|-------------------------------------------|---------------------|--------------------------|
| Folder name       | `modules/<folder>/`                       | flat lowercase, no underscores ideally | `importexport`           |
| Module ID — `name()` | What the provider returns from `name()` | regex `[a-zA-Z0-9_]+` (used as view namespace) | `import_export`          |
| URL path          | Routes you register                       | hyphens are fine    | `/admin/import-export`   |

**Folder name** is the autoloader anchor. `Modules\ImportExport\Services\Foo` resolves
to `modules/importexport/Services/Foo.php` — PHP namespace segments can't contain
underscores, so the folder shouldn't either if you want classes inside it.

**Module ID** — what `name()` returns — is what other code uses. It's the view namespace
(`pages::show` resolves to `modules/pages/Views/show.php`), the key in `requires()`
declarations, and the row key in `module_status`. Underscores are fine here.

**URL paths** are independent — use whatever reads well. Routes are registered with the
router; nothing parses them back to module identity.

When folder and `name()` differ (e.g. folder `importexport`, name `import_export`), every
internal lookup the framework does is correct — but writing the same name two ways trips
people up. Pick one form and use it consistently when you can. If you do diverge, leave a
comment in `module.php` explaining why.

## ModuleProvider hooks

The base class is `core/Module/ModuleProvider.php`. Every method has a sensible default;
override only what you need.

### `name(): string` — required

Unique, lowercase identifier. Used as the view namespace, the key in `requires()`,
and the database key in `module_status`. Stable across releases — admin overrides
key on it.

### `tier(): string` — core vs premium

Returns `'core'` (default) or `'premium'`. Premium modules go through the
`EntitlementCheck` contract during dependency resolution; a `false` return removes
them from the active set as cleanly as an admin-disable.

The framework's strictest invariant about tiers:

> Core modules MUST NOT depend on premium modules.

That's enforced socially, not technically — there's no compile-time check, but a violation
will silently break the open-source install the moment the premium repo isn't present.
If you find yourself reaching for a premium module from a core module, the right fix is
almost always to move the *bridge* into the premium repo (e.g. premium adds a block to
its own provider that integrates with the core module, rather than the core module
calling into premium).

### `routesFile(): ?string`

Absolute path to a routes file (typically `__DIR__ . '/routes.php'`). The file is
required with `$router` and `$container` in scope, so you write:

```php
<?php
// modules/greeter/routes.php
$router->get('/hello', [\Modules\Greeter\Controllers\HelloController::class, 'index']);
$router->post('/admin/greeter/save', [\Modules\Greeter\Controllers\Admin::class, 'save'])
       ->middleware(['auth', 'admin']);
```

Module routes load BEFORE `routes/web.php`. On collision, web.php wins (last writer wins
in the router's ordered list). Use that to your advantage when you need to override a
module route for a specific tenant.

### `viewsPath(): ?string`

Absolute path to a `Views/` directory. The registry calls
`View::addNamespace($name, $path)` so `view('greeter::admin.dashboard')` resolves to
`<viewsPath>/admin/dashboard.php`.

### `migrationsPath(): ?string`

Absolute path to a `migrations/` directory. The migrator picks it up alongside the
top-level `database/migrations/`. Filename convention:
`YYYY_MM_DD_HHMMSS_short_description.php` returning a `Migration` instance with `up()`
and `down()` methods.

### `register(Container $c): void`

Bind module-specific services into the container. Called during discovery, BEFORE
routes load and BEFORE the dependency check runs. Keep this side-effect-free and DB-free
— we may build a registry instance during a migration when the schema doesn't exist yet.

### `boot(Router $router, Container $c): void`

Runtime wiring that needs the router or resolved dependencies. Called after every
module has been registered AND the routes file has been pulled in. Good place for event
listeners, view composers, or programmatic route additions that depend on another
module being available.

### `commands(): array`

Artisan commands this module contributes. Return either class names or instances:

```php
public function commands(): array {
    return [
        \Modules\Blog\Console\ImportFeedCommand::class,
        new \Modules\Blog\Console\RebuildIndexCommand(),
    ];
}
```

### `requires(): array`

Hard dependencies, by module *ID* (what other modules return from `name()`).

```php
public function requires(): array {
    return ['taxonomy', 'comments'];
}
```

The dependency check is non-fatal. Missing deps disable this module (state =
`disabled_dependency` in `module_status`); the rest of the app keeps running.
Cascading: if module A requires B and B is disabled, A also disables.

### `blocks(): array`

Content blocks contributed to the page composer. Each block has a globally-unique key,
a label, a render closure, and optional schema for typed settings. Browse
`modules/pages/module.php` for examples ranging from minimal to elaborate.

```php
public function blocks(): array {
    return [
        new \Core\Module\BlockDescriptor(
            key:         'greeter.hello_tile',
            label:       'Hello Tile',
            description: 'Friendly greeting card for the dashboard.',
            category:    'Greetings',
            defaultSize: 'small',
            audience:    'auth',
            render:      fn(array $ctx, array $settings) => '<div>Hello, ' . htmlspecialchars($ctx['user']['name'] ?? 'guest') . '!</div>',
        ),
    ];
}
```

Block keys are global. Prefix with your module ID (`greeter.hello_tile`, not just
`hello_tile`) so two modules can't collide.

### `linkSources(): array`

Fully-qualified class names of `\Core\Services\LinkSource` implementations the module
contributes. Used by the menu builder's "Internal" palette so admins pick from a
grouped catalogue instead of typing URLs by hand.

```php
public function linkSources(): array {
    return [\Modules\Greeter\LinkSources\GreeterLinkSource::class];
}
```

### `gdprHandlers(): array`

DSAR handlers — declare every user-bearing table in your module so the GdprRegistry
can drive both data export and the user-erasure pipeline. Modules that hold no PII
return `[]`; modules that hold legally-protected data (invoices, audit logs) declare
`action: 'anonymize'` with a `legal_hold_reason`.

```php
public function gdprHandlers(): array {
    if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

    return [
        new \Modules\Gdpr\Services\GdprHandler(
            module:      'greeter',
            description: 'Greetings you sent.',
            tables: [
                ['table' => 'greetings', 'user_column' => 'sender_id', 'action' => 'erase'],
            ]
        ),
    ];
}
```

The `class_exists()` guard matters: if the GDPR module is itself disabled (or absent
from a stripped install), reaching into its namespace would crash the registry.
Always guard cross-module references.

### `retentionRules(): array`

Time-bounded data — declare a default purge/anonymize rule per table. The retention
module discovers your declarations on first sync; admins can adjust days, action, or
disable the rule from `/admin/retention`.

The `key` MUST be stable across releases (`{module}.{table}.{purpose}`) — admins'
edits are keyed by it, so renaming the key resets their override.

### `settingsKeys(): array`

Site-scope setting keys this module owns. Listed keys are HIDDEN from the generic
`/admin/settings?scope=site` grid because they're meant to be edited from your
module's dedicated admin page. Otherwise the grid would silently clobber the
setting's `type` column on save.

## Database setup

All schema changes go through **PHP migrations** under
`database/migrations/` (framework-level) or `modules/<name>/migrations/`
(per-module). The framework's baseline schema lives in the migration
`database/migrations/2026_04_20_000000_create_baseline_tables.php`,
created when the legacy `install.sql` flow was retired (see CHANGELOG).

```
modules/greeter/migrations/2026_05_02_120000_create_greetings_table.php
```

```php
<?php
use Core\Database\Migration;

return new class extends Migration {
    public function up(): void {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS greetings (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sender_id   BIGINT UNSIGNED NOT NULL,
                message     TEXT NOT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_greetings_sender (sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void {
        $this->db->query("DROP TABLE IF EXISTS greetings");
    }
};
```

Run with `php artisan migrate`. The migrator scans `database/migrations/` plus every
module's `migrationsPath()`.

### Permission seed (when your module exposes admin routes)

A non-obvious gotcha: the baseline migration only grants the permissions that
existed at the time it was written. If you add a permission later via a new
migration, no roles get it automatically — admins lose access to the new
admin route until the permission is granted manually.

Pattern: every `seed_*_permission.php` migration MUST grant the admin role inline.

```php
<?php
use Core\Database\Migration;

return new class extends Migration {
    public function up(): void {
        // Insert the permission
        $this->db->query("
            INSERT IGNORE INTO permissions (name, description)
            VALUES ('greeter.manage', 'Manage greetings')
        ");
        $permId = $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE name = 'greeter.manage' LIMIT 1"
        );

        // Grant to the admin role inline — DO NOT skip this step.
        // The baseline migration only grants the permissions that existed
        // when it was written; skipping the inline grant for a new
        // permission means existing admins can't reach the new admin page
        // until someone runs the right SQL by hand.
        $this->db->query("
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, ? FROM roles r WHERE r.slug = 'admin'
        ", [$permId]);
    }

    public function down(): void {
        $this->db->query("
            DELETE FROM permissions WHERE name = 'greeter.manage'
        ");
    }
};
```

## register() vs boot() vs routes.php

People mix these up. Quick rules:

- **`register()`** — Bind services. No DB. No router. Called during discovery.
- **`routes.php`** — HTTP route definitions. The router is in scope.
- **`boot()`** — Anything else that needs the router OR a fully-built container.
  Event listeners, view composers, programmatic late wiring.

If you're tempted to call the DB from `register()`, you have a bug. The migrations
command builds a registry instance before the schema exists; your `register()` must
not assume any tables are reachable.

## Cross-module integration patterns

**Don't import another module's classes at file load time.** Always check
`class_exists()` first, OR resolve via the container if you've registered an interface.

```php
// BAD — loads at parse time, breaks if Gdpr is disabled
use Modules\Gdpr\Services\GdprHandler;
public function gdprHandlers(): array {
    return [new GdprHandler(...)];
}

// GOOD — guarded
public function gdprHandlers(): array {
    if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];
    return [new \Modules\Gdpr\Services\GdprHandler(...)];
}
```

**Use `requires()` for hard dependencies.** If your module truly cannot function
without another (e.g. `messaging` needs `block` for user-blocking), declare the
dependency. The dep checker disables your module cleanly when the dep is missing.

**Use `class_exists()` guards for soft dependencies.** If your module is *better* with
another but works without (e.g. you contribute a GDPR handler when GDPR is around),
guard the integration without declaring a hard dependency.

## Tier (core vs premium)

Add this one line to mark a premium module:

```php
public function tier(): string { return 'premium'; }
```

That's it. Everything else about the module is identical to a core module. The
registry routes premium modules through the `EntitlementCheck` contract during
dependency resolution; on a `false` return, the module ends up in
`module_status.state = 'disabled_unlicensed'` and `/admin/modules` surfaces it with
a distinct badge.

For local dev you don't need to do anything — the default `AlwaysGrantEntitlement`
grants every premium module that's on disk.

For the hosted web-app builder (a future session), bind a custom EntitlementCheck:

```php
// config/services.php
$container->singleton(
    \Core\Module\EntitlementCheck::class,
    fn() => new \App\Services\TenantEntitlement($currentTenantId)
);
```

## Common pitfalls (worth memorising)

These all cost real time when you don't know about them:

**`$_POST` silently converts dots and spaces to underscores.** A field named
`settings.foo` arrives as `$_POST['settings_foo']`. Read namespaced keys via
`$request->post()` (which the framework normalises) or translate on read.

**`htmlspecialchars()` inside `<style>` breaks CSS silently.** HTML entities aren't
decoded in style blocks; the browser sees `&quot;` literally. For CSS-context output,
strip only `<`, `>`, `}` and trust upstream validators.

**View namespace is regex-restricted.** `View::addNamespace($name, $path)` requires
`name()` to match `/^[a-zA-Z0-9_]+$/`. Hyphens, dots, and slashes break it. Use
underscores in `name()` if you need a separator.

**Module folder name leaks into the autoloader.** `Modules\Foo\Bar` becomes
`modules/foo/Bar.php` — a folder name with underscores can't be reached because PHP
namespaces can't contain underscores. Use flat-lowercase folder names.

**Don't shadow PHP built-ins with your helper functions.** `header()`,
`session_start()`, `header_remove()` etc. are global — accidentally declaring a
function with the same name in `helpers.php` fatals the request.

## Testing locally

Unit tests run under PHPUnit (`vendor/bin/phpunit`) from the core repo. Tests live
under `tests/Unit/` (fast, no DB) and `tests/Feature/` (boots the container).

For a manual smoke test of the full module discovery pipeline:

```bash
bin/php storage/tmp/split-smoke-test.php
```

That script loads both module roots, exercises the entitlement gate, and verifies the
core/premium tier integrity. It's a good sanity check after any change to the registry
or to a module's `tier()`/`name()`.

## Common module recipes

### Module ships with seed data

Add a separate migration named `*_seed_*` that runs after the table-creating migration.
Use `INSERT IGNORE` so re-running the migrator doesn't fail on existing rows.

### Module needs a cron-like recurring job

Implement a `\Core\Scheduling\ScheduledTask` and wire it via `boot()`. The framework's
`schedule:run` master command picks it up. See `core/Scheduling/Scheduler.php` for the
contract.

### Module needs a queueable background job

Implement `\Core\Queue\Job`. Dispatch with the `DatabaseQueue` service. Worker process
handles execution; failures land in `failed_jobs` for retry.

### Module needs an admin page

Add a controller under `Controllers/Admin/`, a route in `routes.php` guarded by an
`admin` middleware, and a view under `Views/admin/`. Add a permission seed migration
following the pattern above.

### Module needs to integrate with the page composer

Implement `blocks(): array` in your provider. Each block gets a unique key, a label,
and a render closure. Admins can drop your block onto any page layout from
`/admin/pages` — no extra wiring needed.

### Module page wants admin-editable chrome around it

Customer-facing module pages can opt into the **page-chrome** system: a controller
declares "this view should render inside the layout named `your.slug`" and the
framework wraps it in admin-editable chrome at request time. Admins drop a
hero strip above, a help sidebar beside, a CTA below — no extra controller
logic, no view fork.

Three steps per page:

1. **Migration** in your module's `migrations/` dir that calls
   `SystemLayoutService::seedLayout($slug, [...metadata...])` then
   `seedSlot($slug, 'primary', 0, 0)`. Slug mirrors the URL with `/` →
   `.` (e.g. `/account/data` → `account.data`).
2. **Controller** chains `->withLayout('your.slug')` after `Response::view(...)`.
3. **View** drops the `include header.php` / `include footer.php` lines —
   it's now a fragment. `$pageTitle` and other head globals propagate
   automatically via capture-and-emit.

If the layout is missing (fresh install before migrations have run, or
admin-deleted), the response sends unwrapped — broken chrome must never
break the page.

The full developer guide — when to chrome and when not to, the multi-slot
`Response::chrome()` API, the slug naming convention, the migration
template, and troubleshooting — lives at [`docs/page-chrome.md`](page-chrome.md).
Existing conversions to copy from: `modules/profile/migrations/*_seed_profile_chrome.php`,
`modules/faq/Controllers/FaqController.php` (the `publicIndex` action),
`modules/policies/Views/account/index.php` (the fragment view).

## Where to look for examples

The 47 existing modules are the best reference. A few starting points by complexity:

- **Trivial provider, single feature**: `modules/faq/`, `modules/policies/`
- **Provider with blocks + GDPR + retention**: `modules/pages/module.php`
- **Provider with admin UI + permissions**: `modules/auditlogviewer/`
- **Heavy-weight feature module**: `modules/store/` (premium repo) — products,
  cart, checkout, blocks, settings, GDPR, retention. A complete tour of every hook.

When you're stuck on "how does X work in this framework", read the existing module
that does the closest thing first. The conventions are remarkably consistent.
