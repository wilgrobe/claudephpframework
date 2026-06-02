# Tooltip (core)

Managed, reusable help bubbles. Author tooltip content once in an admin manager
and drop it onto pages as composer blocks or fetch it over a JSON API. A vanilla,
dependency-free widget renders the bubbles with hover / click / focus triggers
and smart auto-placement.

## What ships

- **Admin manager** at `/admin/tooltips` — list (search + category filter),
  create/edit form with a preview, active toggle, categories, and (with the
  analytics submodule) a usage rollup.
- **Two composer blocks**:
  - `tooltip.inline` — a trigger word in prose that reveals a tooltip.
  - `tooltip.help_button` — a standalone `?` / `ℹ` / `💡` button, handy beside a
    form-field label.
- **Public JSON API**: `GET /api/tooltips/{slug}` returns the rendered, sanitised
  content (XSSI-prefixed via `Response::json()`); `POST /api/tooltips/{slug}/track`
  records a view (analytics submodule, rate-limited).
- **Vanilla JS widget** — auto-wires on load, one shared listener per event type
  (`window.__ttWired` guard), `placement=auto` measures viewport clearance, and a
  `window.Tooltip.open(slug)` / `.close(slug)` API for `trigger=manual` callers.

## Content + safety

Content is stored **raw** (per `content_format`: plain / markdown / html) and
rendered + sanitised **at read time**, so changing the format re-renders without a
migration. Markdown goes through `Core\Support\Markdown`; HTML and rendered
markdown go through `Core\Validation\Validator::sanitizeHtml` (DOMDocument
allowlist — no `<script>`, no `<img>`, `href` http/https only).

## Submodules

| key | effect when **off** |
|---|---|
| `rich-content` | format locked to plain; all tags stripped to escaped text |
| `per-page-overrides` | override admin routes 404; `get()` skips override lookup |
| `analytics` | no `view_count` increment; `/track` 404; analytics tab hidden |
| `a11y-keyboard` | JS skips focus/blur/Esc wiring (hover + click still work) |
| `translations` | declared only — lands with the i18n module |

## Schema

`tooltips` (content + display config), `tooltip_categories` (organisation),
`tooltip_overrides` (per page/route/role variants). `trigger` is a MySQL reserved
word — always backticked in raw SQL.

## Notes

- This is a **core** module (free, `tier()` defaults to `core`), so it loads on
  every install and its tables migrate with `php artisan migrate` /
  `tenant:migrate`.
- No `pages()` declaration — `PageDescriptor` is a builder-only construct; the
  manager is reached via its route + the admin nav.
