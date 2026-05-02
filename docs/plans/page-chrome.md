# Plan: page chrome — admin-editable layouts for module pages

**Status:** queued for a future session.
**Author:** drafted 2026-05-02.
**Approach:** B (per-page system layouts with content slots).

## Problem

The framework currently customises layouts at two levels:

1. **`pages` module pages** (`/terms`, `/about`, anything an admin creates at
   `/admin/pages`) are fully editable via the page composer — title, body,
   layout grid, blocks, SEO.
2. **`/dashboard`** is driven by named system layouts (`dashboard_stats` and
   `dashboard_main`). The view file is a 30-line shim that calls
   `SystemLayoutService->get(...)` twice and renders each through the
   page-composer partial.

Every other module-provided route is a direct controller→view path with
admin-customisable knobs limited to **theme tokens**, **module settings**, and
**module enable/disable**. Admins cannot edit titles, copy, or layout, and
cannot insert blocks above, beside, or below the controller's primary content
on `/messages`, `/feed`, `/shop`, `/events`, `/kb`, `/account/data`,
`/account/sessions`, `/profile/edit`, or any other module surface.

That is fine for an opinionated CMS but limiting for the web-app builder
direction the framework is heading toward — tenants of a hosted builder will
expect to brand and customise every visible surface, not just admin-created
pages.

## Goal

Make every customer-facing module page render through the same composer that
already drives `/dashboard` and `pages`, while letting the controller continue
to own its primary content. After this work ships:

- An admin can drop a "Featured products" block at the top of `/feed`.
- An admin can change `/messages` to a sidebar layout with a "Need help?"
  block on the right.
- An admin can replace the empty-state copy on a module page with a custom
  HTML or markdown block.
- An admin can give a module page a hero strip (full-bleed background,
  custom heading) above the controller's content.

Without forcing module authors to rewrite their views — and without breaking
backward compatibility for modules that don't opt in.

## Non-goals

- Editing the **structure** of the controller's primary content. The module
  still owns what its primary view renders. Admins compose **around** that
  content, not inside it.
- Customising `/admin/*` pages. Admin surfaces are dense, functional, and
  benefit from being predictable. Out of scope for this plan.
- Per-tenant custom view templates. That's a separate, heavier feature
  ("view overrides") and isn't needed to get the value above.

## Architecture

The dashboard already shows the shape. Generalise it.

A **module page** that opts in to chrome:

1. Has a named system layout (e.g. `messaging.thread_index`).
2. The system layout contains zero or more **block placements** (existing
   behaviour) PLUS at least one **content slot placement** (new).
3. Content slot placements are filled at request time by the controller's
   rendered view.
4. The layout renders top-to-bottom: each row owns its column grid, each
   cell stacks its placements (blocks + slots) by sort order.

For most modules a default layout has exactly one cell occupied by a
`primary` content slot — the page renders the same as today, but the admin
can now go to `/admin/system-layouts/messaging.thread_index` and add rows,
columns, and blocks around the controller's content.

### Why not just wrap with admin-editable header/footer rows?

Because modules want flexibility. A storefront admin might want a hero
strip ABOVE `/shop`'s content; a community admin might want a sidebar
ALONGSIDE `/feed`'s timeline; a SaaS admin might want a CTA banner BELOW
`/billing`. A wrapper-only model forces a fixed shape. Treating the
controller's content as one cell among many lets the admin compose freely.

## Schema changes

### `system_block_placements` — add placement type and slot name

```sql
ALTER TABLE system_block_placements
  ADD COLUMN placement_type ENUM('block','content_slot')
    NOT NULL DEFAULT 'block' AFTER block_key,
  ADD COLUMN slot_name VARCHAR(64) NULL AFTER placement_type;
```

Existing rows default to `placement_type='block'` so nothing breaks.

For a placement with `placement_type='content_slot'`:

- `block_key` is ignored (set to a sentinel like `__slot__` or empty
  string; pick one — see open question 3).
- `slot_name` defaults to `'primary'` if NULL. Implementations should
  treat NULL and `'primary'` as equivalent.
- `settings` and `style` columns still apply (admin can wrap the slot
  output with the same styling layer that wraps blocks).
- `visible_to` is **ignored** for content slots — the controller already
  enforces its own auth gating; we don't want the layout silently
  hiding the page's main content.

### `system_layouts` — discoverability columns

```sql
ALTER TABLE system_layouts
  ADD COLUMN friendly_name VARCHAR(255) NULL AFTER name,
  ADD COLUMN module VARCHAR(64) NULL AFTER friendly_name,
  ADD COLUMN category VARCHAR(64) NULL AFTER module,
  ADD COLUMN description TEXT NULL AFTER category;
```

These power a better `/admin/system-layouts` index — admins see
"Messaging — Inbox view" instead of `messaging.thread_index`, and they
can filter or group by module. None of these are required to render
the layout.

### `page_block_placements` — same change for pages module pages

Same `placement_type` + `slot_name` columns added to the parallel
table. Pages don't *currently* have a controller-driven slot, but the
schema parity keeps the rendering partial single-source-of-truth and
opens the door to future use (e.g. a "current entity body" slot).

This is a small migration; consider whether to ship it in batch A or
defer.

## API: opting a controller into chrome

### Single-slot (the common case)

```php
// Inside a controller method
return Response::view('messaging::public.inbox', [
    'conversations' => $this->svc->inboxFor($userId),
])->withLayout('messaging.thread_index');
```

`Response::withLayout(string $layoutName, string $slot = 'primary')`:

- Sets the response's chrome configuration; doesn't modify the body yet.
- The actual wrapping happens on `send()` (or a hook the kernel runs
  after controller dispatch). This lets `withFlash()` and other
  builders chain in any order.
- If the named layout doesn't exist (e.g. fresh install, migration not
  yet run, layout admin-deleted), the response is sent UNWRAPPED. This
  is the graceful fallback — broken chrome must never break the page.

### Multi-slot (e.g. primary + sidebar)

```php
return Response::chrome('messaging.thread', [
    'primary' => View::render('messaging::public.thread_main', $data),
    'sidebar' => View::render('messaging::public.thread_sidebar', $data),
]);
```

`Response::chrome(string $layoutName, array $slots)`:

- Pre-renders each slot's HTML.
- The body of the response is empty until wrapping; the body becomes the
  layout's rendered output with each slot interpolated into its
  matching content-slot placement.
- A slot named in `$slots` with no matching placement in the layout is
  silently dropped (admins removed that slot from the layout — that's
  their choice). A slot referenced by the layout but missing from
  `$slots` renders as empty.

### Skip rules

The wrapper is a no-op when:

- The response's `Content-Type` isn't `text/html`. JSON, XML, plain text,
  binary downloads pass through.
- The status is not in the 2xx range. 3xx redirects, 4xx, 5xx pass
  through (preserves error pages).
- The request is detected as an XHR/HTMX partial (the existing pattern
  for those is to return fragments without the page chrome).
- The layout doesn't exist or is admin-disabled.

## Rendering changes

### Page composer partial

`app/Views/partials/page_composer.php` already iterates placements and
renders blocks. New branch: when `placement_type === 'content_slot'`,
emit the slot HTML from `$slots[slot_name] ?? ''` instead of calling
`BlockRegistry::render()`. Placement style wrapper still applies if
present.

```php
// Inside the placement loop
if (($p['placement_type'] ?? 'block') === 'content_slot') {
    $slotKey = $p['slot_name'] ?: 'primary';
    $html = $slots[$slotKey] ?? '';
    // visible_to ignored for slots — controller owns auth gating
} else {
    // existing block rendering path
    $html = $registry->render($p['block_key'], $ctx, $p['settings']);
}
```

### Wrapping kernel hook

A new hook between controller dispatch and `Response::send()` checks
`$response->chrome` and wraps if set. Likely lives in `core/bootstrap.php`
or a small `Core\Http\ChromeMiddleware` class invoked from
`public/index.php` after route dispatch.

The wrap function:

1. Loads the layout via `SystemLayoutService->get($layoutName)`. Cached
   per-request by layout name.
2. If null → no wrap (fallback).
3. Renders the layout via the composer partial, passing `$slots` and
   `$composerContext`.
4. Substitutes the wrapped HTML for the original body and returns.

The header/footer partials still wrap the result the same way they wrap
any view — the chrome is layout-only, not template-level. This keeps
`<html>`, `<head>`, theme stylesheet, and global header consistent.

### Inner view → outer template variable hand-off

This is the trickiest detail. The inner view sets globals like
`$pageTitle`, `$pageStyles`, `$pageScripts`, `$pageMeta` that the outer
header partial consumes. Today the inner view is rendered AFTER
`<?php include header.php; ?>` and BEFORE `<?php include footer.php; ?>`,
so the variables propagate naturally.

Under chrome, the slot content is rendered first (by `View::render` in
the controller or by `Response::chrome`), and only THEN the layout is
wrapped around it. The header has already emitted by the time the
inner view runs.

Two options:

**(a) Capture-and-emit pattern.** The inner view sets `$pageTitle` etc.,
the chrome wrapper captures those globals via output buffering, and the
header partial (which now runs LAST) reads them when emitting `<head>`.
This requires reorganising the header/footer pattern slightly.

**(b) Set explicitly on Response.** The controller calls
`->withTitle()` / `->withMeta()` / etc. on the response. Header reads
from the response object instead of view globals. More explicit, less
magic, but every controller has to migrate.

I lean **(a)** for backward compatibility. The migration cost of (b)
is real and most existing views set `$pageTitle` at the top of the
file expecting it to work.

The implementation: render the inner view into a string buffer, capture
any `$pageTitle`/`$pageStyles`/`$pageScripts` globals set during render,
then build the full document with those globals applied. Existing
non-chromed views work unchanged because they continue to render in
the existing inline pattern.

## Default-layout migration pattern

Each module that opts in to chrome ships a migration that seeds its
default layouts. Pattern:

```php
// modules/<name>/migrations/YYYY_MM_DD_seed_default_chrome.php
return new class extends Migration {
    public function up(): void {
        $this->seedLayout('messaging.thread_index', [
            'friendly_name' => 'Messaging — Inbox',
            'module'        => 'messaging',
            'category'      => 'Messaging',
            'description'   => 'Layout for the /messages inbox page.',
            'rows' => 1, 'cols' => 1,
            'col_widths' => [100], 'row_heights' => [100],
        ]);
        $this->seedSlot('messaging.thread_index', 'primary', 0, 0);
    }
    public function down(): void { /* hash-checked rollback */ }
};
```

Two helpers (provided by the framework, called from the migration):

- `$this->seedLayout($name, $opts)` — INSERT IGNORE on layout name; sets
  friendly_name/module/category/etc.
- `$this->seedSlot($layoutName, $slotName, $row, $col, $sortOrder = 0)` —
  INSERT IGNORE a `placement_type='content_slot'` row at the given cell.

`down()` should respect admin edits: stamp the seed's hash into a sentinel
and only delete rows that still match (same protective pattern used in
the policy seed migration shipped 2026-05-02).

## Admin UI changes

`/admin/system-layouts` (existing):

- New columns: friendly name, module, content-slot count.
- Group by `module`, sort by `category` then `friendly_name`.
- Filter: "show only layouts with content slots" so admins know which
  layouts are wrapping module pages vs which are pure-block (like the
  dashboard).

`/admin/system-layouts/{name}` editor (existing):

- New placement-type dropdown ("Block" vs "Page content").
- When "Page content" is selected, hide the block-key dropdown and show
  a slot-name input (defaulting to `primary`).
- Inline help: "Page content slots are filled by the route's controller
  at request time. The default slot is `primary`."
- Visual marker on existing rows: a "Page content" badge (vs "Block" badge)
  so the admin can tell at a glance which placements are which.

## Phasing

This is large enough to want to phase. Suggested batches:

### Batch A — Foundation (single session)

Ships the rendering infrastructure but converts no module pages.

- Migration: `placement_type` + `slot_name` columns on
  `system_block_placements` (and parallel on `page_block_placements`).
- Migration: `friendly_name` + `module` + `category` + `description` on
  `system_layouts`.
- `Response::withLayout(string $name, string $slot = 'primary'): self`.
- `Response::chrome(string $name, array $slots): self`.
- Page-composer partial branch for content-slot placements.
- Kernel/dispatch hook that wraps chrome-tagged responses.
- `SystemLayoutService::seedLayout()` + `seedSlot()` helpers.
- Migration helper trait `Core\Database\SeedHelpers` if it doesn't exist.
- Inner-view variable capture (capture-and-emit pattern, option (a) above).
- Unit tests:
  - `withLayout()` against an existing layout produces wrapped HTML with
    the slot interpolated.
  - `withLayout()` against a missing layout passes through unwrapped.
  - JSON / redirect / 4xx responses pass through unwrapped.
  - `chrome()` with multiple slots interpolates correctly; missing slots
    render empty; extra slots are ignored.
  - `placement_type='block'` rows still render via BlockRegistry.
  - `visible_to` is honoured for blocks but ignored for slots.
- Admin UI: editor handles `placement_type` selector, slot_name input,
  badges; index page surfaces the new columns.

**Acceptance:**
- An admin can create a system layout, add a `content_slot` placement,
  and a controller calling `Response::view(...)->withLayout(...)`
  renders inside that layout.
- Removing the layout (or removing the slot placement) gracefully falls
  back to unwrapped rendering with no 500.
- Existing layouts (`dashboard_main`, `dashboard_stats`) render
  unchanged.
- Existing controllers that don't call `withLayout()` render unchanged.

### Batch B — First conversion: a small core page (single session)

Convert one core module page to use chrome as the smoke test for the
foundation. Recommended target: `/account/data` (the GDPR dashboard).
It's small, low-traffic, in core, and benefits from chrome (admins
might want to add a "We respect your privacy" hero strip above it).

- New migration in `modules/gdpr/migrations/` seeding
  `gdpr.account_data` layout + a `primary` content slot.
- `UserGdprController::index` adds `->withLayout('gdpr.account_data')`.
- Manual verification: `/account/data` renders identically to before.
- Add a block to the layout from `/admin/system-layouts/gdpr.account_data`
  (e.g. drop `siteblocks.markdown` above the slot) → it renders.

**Acceptance:**
- Page renders identically to before by default.
- Admin can add blocks; they render in the configured cells.
- Removing the layout falls back to unwrapped rendering.

### Batch C — Convert remaining customer-facing core pages

Sweep remaining core module pages that would benefit from chrome:

- `modules/profile/` — `/profile`, `/profile/edit`
- `modules/faq/` — `/faq`
- `modules/pages/` — public page renderer (already partly chromed; align
  with the new content-slot pattern)
- `modules/settings/` — public settings (footer signup forms, etc.) only;
  admin pages stay unchromed
- Account-area surfaces from compliance modules:
  `/account/email-preferences`, `/account/policies`, `/cookie-consent`
- Public-facing module surfaces in core that aren't already chromed:
  `/search` results, custom 404 if implemented

Each conversion = one migration + one or two `withLayout()` calls.

**Acceptance:**
- All converted pages render identically to before by default.
- Admin can drop blocks around any of them.

### Batch D — Premium repo conversions (separate session against premium repo)

Same conversion pattern applied to premium pages: `/messages`, `/feed`,
`/shop`, `/events`, `/kb`, `/forms/{slug}`, `/billing`, `/orders`,
`/support`, `/groups`, `/polls`, etc.

Premium pages benefit most from chrome — they're the customer-facing
surfaces tenants will most want to brand. This batch is where the
feature pays back its cost.

### Batch E — Polish

- `/admin/system-layouts` grouping/filtering UI.
- Public docs at `docs/page-chrome.md` (developer-facing) explaining
  when to use `withLayout()` vs `chrome()` vs neither.
- Update `docs/modules.md` with a section on chroming module pages.
- Update `docs/qa-checklist.md` with checks for chrome rendering paths
  (renders identically by default; admin block additions surface).

## Risks and open questions

### 1. `$pageTitle` propagation

Resolved approach: capture-and-emit (option (a) above). Inner view sets
`$pageTitle`, output buffer captures, outer header consumes after
inner render completes. Worth prototyping in Batch A's first PR
to confirm the pattern works against existing views without
modification.

### 2. CSS / JS asset injection from the inner view

Same problem class. Existing views may push assets via `$pageStyles[]`
and `$pageScripts[]` globals. Capture-and-emit handles these the same
way as `$pageTitle`. Confirm in Batch A.

### 3. `block_key` for content-slot rows

When `placement_type='content_slot'`, what goes in the NOT NULL
`block_key` column? Three options:

- **Empty string:** acceptable but possibly surprising to anyone
  inspecting the DB.
- **Sentinel value like `__slot__`:** explicit, greppable.
- **Make `block_key` nullable in the schema migration:** cleanest from
  a data-modelling perspective but requires touching the existing
  CHECK constraint.

Lean toward sentinel (`__slot__`) — minimal schema churn, clear intent
on inspection.

### 4. Performance

Each chromed page now does an extra DB round-trip for the layout. Memo
per-request via a `SystemLayoutService` instance bound singleton in
the container. For very high-traffic deployments, consider eager-loading
all layouts in one query at boot.

### 5. Layout default for routes that haven't shipped a migration

If a controller calls `withLayout('messaging.thread_index')` but no
migration has seeded that layout (admin nuked it, fresh install before
migrate, etc.), the page renders unwrapped. Documented behaviour, not
a bug.

Optional: ship a generic fallback layout `chrome.default` with a single
full-width content slot. Controllers that opt in but don't ship their
own migration use this. Reduces boilerplate per module. Decide in
Batch A.

### 6. Versioning of admin-edited layouts

When a module ships an updated default layout (e.g. shifts from 1×1
to 2×1 with a built-in sidebar block), what happens to admin edits?

The hash-protected `down()` pattern from the policy seed migration
handles it correctly: only seed-authored rows get touched on rollback,
admin-edited rows are preserved. For `up()` migrations that ship NEW
layout iterations, the migration should seed only if the layout name
doesn't yet exist; subsequent updates are admin-driven.

Module authors who want to push a new layout shape to existing
installs can either (a) ship a one-shot migration that explicitly
overrides the existing layout (with a flag the admin can opt out of),
or (b) accept that the admin's existing customisation wins.

### 7. AJAX / HTMX detection

Detect via `X-Requested-With: XMLHttpRequest` header AND `HX-Request: true`
header (HTMX). If either is set, skip wrapping. Document the detection
so module authors know the contract.

### 8. CSP and inline content

The composer already emits a `<style>` block. Page chrome inherits the
same constraints — if your CSP forbids inline styles, you'll need a
nonce-passing system already (separate concern). Flag in docs but not
blocking for this work.

### 9. Layout grid limits

The current `system_layouts` schema caps rows at 6 and cols at 4. Some
module pages (a /shop home with hero + featured + categories + grid)
might want more. Loosening the cap is a one-line CHECK change but
worth a deliberate decision in Batch A.

### 10. `pages` module parity

The plan adds parallel `placement_type`/`slot_name` columns to
`page_block_placements`. Pages don't currently consume content slots
(every page IS the content), but parity keeps the renderer single-
source-of-truth and opens the door to features like "content-aware
blocks" or "page sections."

A pragmatic decision: ship the schema parity but not the rendering
parity in Batch A. Pages keep rendering body-as-cell as today. Future
batches can introduce a "page body slot" if useful.

## Out of scope for this plan

These are real future capabilities but explicitly NOT part of this work:

- **Per-tenant view overrides** (a tenant uploads a custom thread.php
  that replaces the module's bundled view). Heavier; requires
  multi-tenant storage.
- **Drag-and-drop block reordering** in the admin layout editor. The
  current editor uses numeric row/col/sort_order inputs. UX upgrade
  is desirable but separate.
- **A/B testing layouts.** Out of scope.
- **Per-route caching of chromed output.** Optional optimisation.
- **Localised layouts** (different layout per locale). Localisable
  block content already works via i18n; layout-level localisation is
  a future concern.
- **Builder-tier layout entitlement.** The hosted web-app builder may
  want to limit how many custom layouts a tenant can have. That's a
  builder-side concern, not framework-side.

## Estimating cost

Rough estimates for a session with full focus:

- Batch A: 1 long session (4-6 hours of focused work) — schema, API,
  composer changes, admin UI, tests, docs.
- Batch B: 0.5 session — single page conversion.
- Batch C: 1 session — sweep through remaining core pages.
- Batch D: 1-2 sessions in the premium repo.
- Batch E: 0.5 session.

Total: 4-5 focused sessions. Batch A is the only one with non-trivial
risk; B-E are conversions following an established pattern.

## What "done" looks like for the whole feature

After all five batches, when an admin lands on
`/admin/system-layouts`:

- They see a categorised list of every module's pages with
  friendly names, grouped by module.
- Each entry can be edited — they can add blocks (header strip,
  sidebar, footer CTA, custom HTML, marketing primitives), change
  the grid (rows, cols, gap, max-width, full-bleed rows), and the
  controller's primary content keeps rendering as a placement among
  the others.
- Removing or breaking a layout never breaks the page; the controller's
  view always renders, with or without chrome.
- The same composer the framework already ships handles all of it —
  no new admin concept to learn.

That's a hosted web-app builder's foundation for site customisation.
