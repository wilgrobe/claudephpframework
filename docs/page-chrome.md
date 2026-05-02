# Page chrome — wrapping module pages with admin-editable layouts

The page-chrome system lets admins decorate any module page with the same
composer that powers `pages` and the dashboard. A controller declares "this
view should be wrapped in the layout named `messages`"; the framework
fills in a header, runs the controller's view through a `primary` content
slot inside that layout, then closes with the footer. Admins customise
the layout from `/admin/system-layouts/{slug}` — drop a hero strip above,
a "Need help?" sidebar beside, a CTA below — without touching code.

This guide covers the developer surface: when to use which API, the
fragment view contract, the slug naming convention, and the migration
helper pattern. For background on why the system exists and how it
arrived at its current shape, read `docs/plans/page-chrome.md`.

## When to use chrome (and when not to)

**Reach for chrome when** a customer-facing page would benefit from
admins adding marketing/help/CTA blocks around the controller's content.
Profile, support, billing, the FAQ, the messaging inbox — basically every
public surface that isn't a focused workflow page like checkout.

**Skip chrome when:**

- The page is admin-internal (`/admin/*`). Admin surfaces are dense,
  functional, and benefit from being predictable. Consistent chrome on
  admin pages would just add visual noise.
- The page is a focused workflow where extra blocks would distract —
  `/checkout`, `/auth/2fa/challenge`, `/policies/accept`. The point of
  these is conversion or completion, not browsing.
- The route returns non-HTML (JSON, CSV, iCal, file downloads). The
  chrome wrapper skips these automatically, but there's no reason to
  declare a layout for them in the first place.
- The view has its own conditional layout-style mechanism that would
  conflict — the forms module's `/forms/{slug}` is the canonical
  example: each form's `layout_style` setting can already select
  default/wide/minimal, and the minimal style intentionally skips the
  global header for landing-page-style embeds. Adding chrome on top
  would conflict.

## The two APIs

### `Response::view(...)->withLayout($slug, $slot = 'primary')`

The common case: one controller, one view, wrap it in one layout.

```php
public function index(Request $request): Response
{
    if ($this->auth->guest()) return Response::redirect('/login');

    return Response::view('messaging::public.inbox', [
        'conversations' => $this->svc->inboxFor($this->auth->id()),
    ])->withLayout('messages');
}
```

The view becomes the `primary` slot's content. The layout's other cells
fill in around it — empty by default, decorated by the admin.

`withLayout()` records the slug + the source view name + the data array
on the response. At send-time, `Core\Http\ChromeWrapper` re-renders the
view via `View::renderFragment()` (which captures `$pageTitle` and other
head-relevant globals), looks up the layout via
`SystemLayoutService::get()`, and stitches `header.php` → composer (with
the slot interpolated) → `footer.php` into the final document.

### `Response::chrome($slug, $slots)`

The multi-slot variant: pre-render each slot's HTML and pass them in.
Useful when one page has distinct primary + sidebar regions backed by
different views.

```php
return Response::chrome('messaging.thread', [
    'primary' => View::render('messaging::public.thread_main',    $data),
    'sidebar' => View::render('messaging::public.thread_sidebar', $data),
]);
```

Slots passed but not referenced by the layout are silently dropped (the
admin removed that slot from the layout — that's their choice). Slots
referenced by the layout but missing from the array render as empty.

`chrome()` doesn't run capture-and-emit because each slot was rendered
independently — there's no single "inner view" whose `$pageTitle` is
authoritative. If you need a `<title>` on a multi-slot page, set it
in the layout's metadata or pass it via your own response header in
a future iteration.

### `new Response($body, $status, $headers)` — no chrome

Chrome is opt-in. Anything that doesn't chain `->withLayout()` (and isn't
built via `chrome()`) sends its body unchanged. This is the right call
for redirects, JSON, downloads, and any page that intentionally renders
its own complete HTML document (logo-only landing pages, embedded
widgets, etc.).

## Skip rules — when chrome is silently bypassed

`ChromeWrapper::shouldWrap()` skips the wrapping (and sends the body
unchanged) when any of these hold:

- `Content-Type` isn't `text/html` (or absent — the wrapper accepts the
  HTML default).
- Status is outside the 2xx range. Redirects, 404, 500 all pass through
  unwrapped.
- The request is XHR (`X-Requested-With: XMLHttpRequest`) or HTMX
  (`HX-Request: true`). These expect raw fragments.
- The named layout doesn't exist or has no rows in `system_layouts`.

That last one is the most important: **broken or missing chrome must
never break the page**. A controller that calls
`->withLayout('messages')` against an install where the messages
migration hasn't run gets a perfectly usable unwrapped page back. Same
on a fresh install before any migrations have run, same when an admin
deletes the layout entirely. The graceful fallback is the whole point.

## Fragment view contract

A view that participates in chrome is a **fragment**. Fragments:

- DO set `$pageTitle = '...'` at the top — captured by
  `View::renderFragment()` and surfaced in the outer header's `<title>`.
- DO set `$pageStyles[]`, `$pageScripts[]`, `$pageMeta[]`, `$seoTitle`,
  `$seoDescription`, `$seoKeywords`, `$seoOgImage`, `$canonical`,
  `$bodyClass` — all captured the same way.
- DO emit only the inner content — typically a single `<div>` wrapper
  around your form/grid/etc.
- DO NOT include `BASE_PATH . '/app/Views/layout/header.php'`.
- DO NOT include `BASE_PATH . '/app/Views/layout/footer.php'`.
- DO NOT call `View::extend()`. The fragment renderer throws on that —
  it'd double-wrap the response.

Before:

```php
<?php $pageTitle = 'Email preferences'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:680px;margin:0 auto;padding:0 1rem">
    <!-- ... page content ... -->
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
```

After:

```php
<?php
// Page-chrome: fragment view. The `account.email-preferences` system
// layout (1×1, max-width 720px) provides the surrounding chrome.
?>
<?php $pageTitle = 'Email preferences'; ?>

<div style="max-width:680px;margin:0 auto;padding:0 1rem">
    <!-- ... page content ... -->
</div>
```

The body is byte-for-byte identical between the two versions — only the
header/footer includes go away.

## Slug naming convention

Layout slugs **mirror the URL of the page they chrome**, with slashes
replaced by dots and hyphens kept verbatim:

| URL                          | Slug                          |
|------------------------------|-------------------------------|
| `/account/data`              | `account.data`                |
| `/profile/edit`              | `profile.edit`                |
| `/account/email-preferences` | `account.email-preferences`   |
| `/cookie-consent`            | `cookie-consent`              |
| `/messages`                  | `messages`                    |
| `/`                          | (no canonical slug; use `home` if you chrome it)  |

The convention exists so admins editing layouts at
`/admin/system-layouts/{slug}` can pattern-match the slug to the page.
It also gives a stable answer to "what page does this layout chrome?"
without needing the `chromed_url` column to be populated (though
populating it lights up the admin index's "View ↗" button — see below).

Module-internal prefixes (`gdpr.account_data`) were the convention
during Batch B, then discarded in Batch C in favour of the URL-mirroring
form. The `2026_05_02_500000_rename_gdpr_account_data_layout.php`
migration renames the legacy slug if you have one in your install.

`SystemLayoutAdminController::canonicalName()` validates slugs against
`/^[a-zA-Z0-9_.-]+$/` with no leading/trailing dot/hyphen and no
consecutive separators. Anything else returns a 422-style flash on the
admin route.

## Module migration: seedLayout + seedSlot

Each module that opts a page into chrome ships a migration that calls
`SystemLayoutService::seedLayout()` then `seedSlot()`:

```php
<?php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

return new class extends Migration {
    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout('account.email-preferences', [
            'friendly_name' => 'Account — Email preferences',
            'module'        => 'email',
            'category'      => 'Account',
            'description'   => 'Wraps /account/email-preferences (the per-user unsubscribe + category preferences page).',
            'chromed_url'   => '/account/email-preferences',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 720,
        ]);
        $svc->seedSlot('account.email-preferences', 'primary', 0, 0);
    }

    public function down(): void
    {
        // Shape-protected rollback — only delete if the layout still
        // matches exactly the seeded shape (one content_slot in cell
        // 0,0). Admin-added blocks make the layout untouchable.
        $count = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM system_block_placements WHERE system_name = ?",
            ['account.email-preferences']
        )['c'] ?? 0);
        if ($count !== 1) return;

        $slot = $this->db->fetchOne(
            "SELECT placement_type, slot_name, row_index, col_index
               FROM system_block_placements WHERE system_name = ?",
            ['account.email-preferences']
        );
        if (!$slot
            || ($slot['placement_type'] ?? null) !== 'content_slot'
            || (int) $slot['row_index'] !== 0
            || (int) $slot['col_index'] !== 0
            || (($slot['slot_name'] ?? null) ?: 'primary') !== 'primary') {
            return;
        }

        $this->db->query("DELETE FROM system_layouts WHERE name = ?", ['account.email-preferences']);
    }
};
```

Notes on the metadata fields:

- `friendly_name` — what shows up first on the admin index. Without it
  the layout shows up as its raw slug.
- `module` — used to group the index by owner.
- `category` — sub-grouping within a module. Useful when one module
  chromes several pages (e.g. profile has both "read view" and "edit
  form").
- `description` — one-sentence summary that hints at useful admin
  customisations ("Drop a privacy-hero block above the slot…").
- `chromed_url` — what URL this layout chromes. Powers the admin
  index's "View ↗" button. Optional; NULL means "this layout isn't a
  standalone page" (dashboard partials, etc).
- `max_width_px` — match the un-chromed page's existing visible
  container width so the chromed default renders identically.

`seedLayout()` is idempotent (INSERT IGNORE on the primary key);
re-running the migration after an admin has customised the layout
won't clobber anything. `seedSlot()` is also idempotent — uniqueness
keyed on (`system_name`, `row_index`, `col_index`, `slot_name`).

## End-to-end conversion checklist

For one new module page:

1. **Migration** in your module's `migrations/` dir. Use the template
   above. Slug = URL with slashes replaced by dots.
2. **Controller** — change `Response::view(...)` to
   `Response::view(...)->withLayout('your.slug')` on the action you're
   chroming. Other actions on the same controller (POST handlers,
   redirects, downloads) stay as-is.
3. **View** — strip the `include header.php` and `include footer.php`
   lines. Add a header comment noting it's a fragment.
4. **Test** — add a row to your module's chrome regression test, or
   add the test if you don't have one yet. The shape:

```php
public function test_my_page_is_wired_for_chrome(): void
{
    $migPath  = BASE_PATH . '/modules/foo/migrations/2026_..._seed_foo_chrome.php';
    $ctlPath  = BASE_PATH . '/modules/foo/Controllers/FooController.php';
    $viewPath = BASE_PATH . '/modules/foo/Views/public/index.php';

    $this->assertFileExists($migPath);
    $this->assertStringContainsString("'foo'", file_get_contents($migPath));
    $this->assertStringContainsString("->withLayout('foo')", file_get_contents($ctlPath));
    $this->assertFalse(str_contains(file_get_contents($viewPath), "/app/Views/layout/header.php"));
    $this->assertFalse(str_contains(file_get_contents($viewPath), "/app/Views/layout/footer.php"));
}
```

The aggregate Batch C/D regression tests (`tests/Unit/Http/PageChromeBatchCTest.php`,
`tests/Unit/Http/PageChromeBatchDTest.php`) are the data-driven version of
this — extend their `surfaces()` array if you're adding to the existing
sweep, or write your own if your conversion stands alone.

## Capture-and-emit: how `$pageTitle` reaches the outer header

The fragment view's `$pageTitle` is set as a local variable inside
`View::renderFragment()`'s include scope, then snapshotted out with the
other head-relevant globals before the function returns:

```php
$captured = [
    'pageTitle'      => isset($pageTitle)      ? (string) $pageTitle      : null,
    'pageStyles'     => isset($pageStyles)     && is_array($pageStyles)  ? $pageStyles  : null,
    'pageScripts'    => isset($pageScripts)    && is_array($pageScripts) ? $pageScripts : null,
    'pageMeta'       => isset($pageMeta)       && is_array($pageMeta)    ? $pageMeta    : null,
    'seoTitle'       => isset($seoTitle)       ? (string) $seoTitle       : null,
    'seoDescription' => isset($seoDescription) ? (string) $seoDescription : null,
    'seoKeywords'    => isset($seoKeywords)    ? (string) $seoKeywords    : null,
    'seoOgImage'     => isset($seoOgImage)     ? (string) $seoOgImage     : null,
    'canonical'      => isset($canonical)      ? (string) $canonical      : null,
    'bodyClass'      => isset($bodyClass)      ? (string) $bodyClass      : null,
];
```

`ChromeWrapper::renderChromeDocument()` then `extract()`s those into the
include scope of `header.php`, where the existing `<title>` /
`SeoManager::metaTags()` / `$pageStyles` / `$pageScripts` machinery
consumes them exactly as it did when the view was self-wrapping.

Net effect: a fragment view doesn't need to know anything new. Set
`$pageTitle` like before; it surfaces in the chrome-wrapped document's
`<head>` like before.

## What's available in the layout's blocks

A block dropped into a chromed layout's grid runs with the same
`$composerContext` array the page composer always passes:

```php
$composerContext = ['viewer' => Auth::getInstance()->user()];
```

So a block can branch on `$context['viewer']` to render differently for
guests vs members. There's currently no first-class way for a block to
read the controller's data ($form, $tickets, etc.) — blocks should be
self-sufficient (fetch their own data via injected services). If a
block needs to know which page it's on, it can introspect the URL via
`$_SERVER['REQUEST_URI']`, but that's a smell — prefer per-page slots
in the layout when the content is page-specific.

## Admin slug rules + the View ↗ button

`/admin/system-layouts` lists every layout grouped by module. Each row
gets:

- An **Edit** button → `/admin/system-layouts/{slug}` (the composer
  editor with grid + placement controls).
- A **View ↗** button (when `chromed_url` is set) → opens the page in a
  new tab. Hidden for layouts where `chromed_url` is NULL — typically
  `dashboard_main` and `dashboard_stats`, which are partials inside
  `/dashboard` rather than standalone pages.

A filter bar above the list takes a free-text search (matches against
friendly name, slug, category, description, URL) and an optional
"with content slots" toggle that hides pure-block layouts so admins
looking for chrome-wrapping pages can find them in one glance.

## Troubleshooting

**The page renders without chrome — what gives?**

The wrapper bailed via a skip rule. Most likely:

- The layout's row in `system_layouts` doesn't exist. Check the migration
  ran: `SELECT name FROM system_layouts WHERE name = 'your.slug';`.
- The response status isn't 2xx. Redirects, 404s, 500s pass through.
- The request is XHR/HTMX. Look at request headers.
- The Content-Type isn't `text/html`. JSON / file responses pass
  through.

**The chromed page double-wraps (two headers, two footers).**

The fragment view still includes `layout/header.php` or
`layout/footer.php`. Strip those.

**`$pageTitle` shows the wrong thing in the chromed page.**

The fragment's `$pageTitle` is captured at fragment-render time. If the
title is dynamic (e.g. `"Search: $q"`), make sure the dynamic value is
in scope at the top of the fragment view, not later. Set it on the
first line of the view, before any other PHP work.

**The admin index doesn't show a View ↗ button for my layout.**

The migration's `chromed_url` field is empty / NULL. Add it to the
`seedLayout()` opts, then either (a) re-run the migration on a fresh
install, or (b) ship a small follow-up migration that runs
`UPDATE system_layouts SET chromed_url = '/your/url' WHERE name = 'your.slug' AND chromed_url IS NULL`.
