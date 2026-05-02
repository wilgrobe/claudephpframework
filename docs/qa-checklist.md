# QA checklist

This is the exhaustive verification list for an installation of claudephpframework.
Work through it once before going live, and again as a regression suite after any
substantial framework upgrade.

The list is organised so each section's setup is satisfied by the section before it
(don't moderate comments before there are comments to moderate). For a faster
sequenced walkthrough that hits the high-value paths in one sit-down, see
[`docs/qa-process.md`](qa-process.md).

> **Premium modules ship from a separate repository.** If your install includes
> [claudephpframeworkpremium](https://github.com/) modules (commerce, social,
> scheduling, etc.), use the parallel `docs/qa-checklist.md` in the premium repo
> for those checks. This document covers the open-source core only.

## Role legend

| Tag  | Role                                        | How to get it |
|------|---------------------------------------------|---------------|
| G    | Guest (not logged in)                       | Log out |
| U    | User (authenticated, no special perms)      | Register + log in |
| A    | Site admin                                  | Role with `admin.access` (gates `RequireAdmin` middleware) |
| SA   | Superadmin                                  | User with `is_superadmin` flag; can toggle superadmin mode |
| S-*  | Staff role with a specific `.manage` perm   | e.g. S-faq, S-pages, S-menus |
| API  | API client with a valid Bearer token        | Mint at `/account/api-keys` |

Many checks work for multiple roles. Where an action should produce a *different*
result for different roles, both are listed.

For setup, you'll want **three test accounts** — call them whatever you like in
your own DB:

- An **admin user** (call them whatever — referred to below as `admin`)
- A **regular user** (referred to as `user`)
- A **second regular user** (referred to as `user2`) — needed for cross-user tests

## Pre-flight

Before running through any module section:

- [ ] `.env` contains database credentials, `APP_URL`, `APP_KEY` (32+ chars), `MAIL_*` config.
- [ ] `php artisan migrate` succeeds with no errors.
- [ ] `composer install` has run, `vendor/` is populated.
- [ ] Web server is serving `public/` as the document root.
- [ ] `storage/` is writable by the web server user.
- [ ] At least one admin role with `admin.access` is seeded; one or two test users created.

---

## Core framework

### Registration + login

- [ ] **G** Visit `/register`, fill the form, submit → account created, redirected to dashboard/home.
- [ ] **G** Register with an existing email → validation error, no duplicate row created.
- [ ] **G** Register with a weak password → rejected with a clear message.
- [ ] **G** Log in with correct credentials → `session_regenerate_id(true)` fires; note the post-login cookie value, confirm the matching row in `sessions` has `user_id = <you>` and the pre-login guest row for the old cookie value is gone.
- [ ] **G** Log in with wrong password → rejected; the row in `sessions` for your current cookie stays in guest state (`user_id IS NULL`); rate-limit counter increments.
- [ ] **U** Log out → cannot access any authenticated page; redirected to `/login`.
- [ ] **U** Immediately after `/logout`, the session cookie is cleared (DevTools shows `Set-Cookie` with past expiry).
- [ ] **U** On the *next* page load after logout, a fresh guest session is created with a **different** session id than before. The previous authenticated session id never comes back — that's the verifiable invariant. (Deleting the cookie by hand and refreshing behaves the same way; that's expected PHP session behaviour, not a logout bug.)
- [ ] **A** Delete a user's row from the `sessions` table while they're logged in → their next request lands on `/login`.
- [ ] **A** With two open sessions for one user in different browsers, `DELETE FROM sessions WHERE user_id = <id>` kicks both → emergency sign-out works.

### Two-factor authentication

- [ ] **U** Enable 2FA in profile settings → TOTP secret generated, QR visible.
- [ ] **U** Scan QR, enter code → 2FA enabled.
- [ ] **U** Log out + log in → prompted for 2FA code after password.
- [ ] **U** Enter wrong 2FA code 5× → account temporarily rate-limited or locked.
- [ ] **U** Disable 2FA from settings → subsequent logins skip the 2FA step.

### Password reset

- [ ] **G** Request reset for existing email → reset email sent (check mail driver log if in dev).
- [ ] **G** Request reset for non-existent email → same success response (no email enumeration).
- [ ] **G** Use the reset link → can set a new password.
- [ ] **G** Use an expired/used reset link → rejected.

### OAuth login (if a provider is configured)

- [ ] **G** Log in via the configured provider → redirected through provider, account linked, session starts.
- [ ] **U** Unlink the OAuth provider from profile → can't log in via that provider afterward.

### Session + CSRF

- [ ] **U** Submit any POST form without `_token` → rejected with CSRF error.
- [ ] **U** Submit a POST form with a stale token → rejected.
- [ ] **U** Two browser tabs, log out in one → the other tab's next request redirects to `/login`.

### Registration & access (`/admin/settings/access`)

- [ ] **SA** Visit `/admin/settings/access` → page renders three toggles (`allow_registration`, `require_email_verify`, `maintenance_mode`). Each is a slider, not a checkbox.
- [ ] **SA** Toggle `allow_registration` **off** + save → visiting `/register` as guest shows "Registration is closed"; POSTing directly to `/register` with valid-looking payload also returns the same page (bypass-resistant).
- [ ] **SA** Toggle `allow_registration` back **on** → signup works end-to-end.
- [ ] **SA** Toggle `require_email_verify` **on** + save. Create a new user whose `email_verified_at IS NULL` → that user cannot log in; login submits, credentials validate, but they're signed right back out with the "Please verify your email" error.
- [ ] **SA** Superadmins are exempt: a superadmin account with `email_verified_at IS NULL` can still log in when the flag is on (so an admin who toggles it on first doesn't lock themselves out).
- [ ] **SA** On `/admin/users/{id}` for the blocked user, click the "Mark verified" button (superadmin-only) → confirm dialog → success flash, the row now shows the verification date, and `audit_log` has an `auth.verification_marked_by_admin` row with the issuing admin's id. The user's next login succeeds.
- [ ] **SA** Verify any outstanding token in `email_verifications` for that user has `used_at` stamped after Mark verified — so a stray click on the old link in their inbox can't redeem.
- [ ] **A** (non-SA admin) The "Mark verified" button does not render. Direct POST to `/admin/users/{id}/mark-verified` with a valid CSRF as a non-SA admin → bounced by `RequireSuperadmin`.
- [ ] **SA** Toggle `maintenance_mode` **on** + save. Log out / open an incognito window → every route returns 503 with the maintenance page **except** the login surface: `/login`, `/logout`, `/auth/2fa/*`, `/auth/oauth/*`. Site name on the page matches `site_name` setting.
- [ ] **SA** Superadmins are exempt from maintenance — in your main browser you can still reach `/admin/settings/access` and toggle it back off.
- [ ] **SA** Verify the 503 response includes a `Retry-After: 3600` header.

### Active sessions — admin surface (`/admin/sessions`)

- [ ] **A** Visit `/admin/sessions` → lists every row in `sessions`, joined to users, newest `last_activity` first. Guest rows show "(no user)".
- [ ] **A** Sidebar "Top users by session count" shows sensible numbers.
- [ ] **A** Use `?user_id={id}` query → list narrows to that user only.
- [ ] **A** Click "Terminate" on a single row → row deleted, audit_log gets a `session.terminate` entry with the truncated session id, that user's next request lands on `/login`.
- [ ] **A** Click "Sign out all devices" on a user → every row for them deleted in one transaction, audit_log gets `session.terminate_all` with `terminated_count`. Confirm the user is kicked from every open browser.
- [ ] **U** Non-admin visiting `/admin/sessions` → forbidden/redirect (RequireAdmin middleware).
- [ ] **A** POST to `/admin/sessions/{id}/terminate` without CSRF → rejected.

### Active sessions — user surface (`/account/sessions`)

- [ ] **SA** In `/admin/settings/security`, the toggle "Users can review + terminate their own active sessions" is a slider.
- [ ] **SA** With the setting **ON**: user-menu dropdown shows "Active Sessions"; `/account/sessions` renders the user's own session list.
- [ ] **SA** With the setting **OFF**: dropdown link disappears; `/account/sessions` returns 404.
- [ ] **U** With two browsers signed in, visit `/account/sessions` in browser A → current browser is tagged "This device"; browser B is listed without the tag.
- [ ] **U** Click "Sign out" on browser B's row → row gone; browser B's next request lands on `/login`; browser A's session is untouched.
- [ ] **U** Click "Sign out" on the "This device" row → confirm dialog with distinct copy; after confirm, redirected to `/login`.
- [ ] **U** A user attempting to terminate a session that doesn't belong to them (by editing the form action URL) is silently no-op'd. Verify with `SELECT id FROM sessions WHERE id = '<forged-id>'` after the attempt — the targeted row still exists.

### New-device login email

- [ ] **SA** In `/admin/settings/security`, the toggle "Email users when they sign in from an unrecognized device" is a slider.
- [ ] **SA** With the setting **OFF**: log out a test user, clear their UA in `sessions` (`DELETE FROM sessions WHERE user_id = <id>`), log back in → no email sent.
- [ ] **SA** With the setting **ON** and `users.last_login_at IS NULL` (brand-new account): first login triggers **no** email (suppress welcome-spam).
- [ ] **SA** With the setting **ON** and at least one prior session row: sign in again from the same browser (same User-Agent) → **no** email (known device).
- [ ] **SA** With the setting **ON**, sign in from a second browser (different User-Agent) while a prior session exists → email dispatched via configured mail driver; body contains When / IP / Device rows and a link back to `/account/sessions`.
- [ ] **SA** After the email send, `audit_log` has an `auth.new_device_email_sent` row.
- [ ] **SA** Simulate a mail driver failure (e.g. bogus SMTP credentials) → login still completes; failure is logged to `storage/logs/php_error.log`, not raised to the user.

### Roles + permissions

- [ ] **A** Visit `/admin/roles`, create a custom role, attach a permission, assign to a user.
- [ ] **U** After role assignment, the user can access the permission's surface. Remove the role → access revoked.
- [ ] **A** Attempt to modify a system role → allowed only if your policy permits it; otherwise blocked with a clear message.

### Superadmin mode

- [ ] **SA** Toggle superadmin mode on → admin surfaces expand. Audit log records `superadmin.mode_toggle`.
- [ ] **SA** Toggle off → surfaces revert.
- [ ] **A** (non-SA) visiting an SA-only surface → forbidden/404.

### Emulation

- [ ] **SA** Start emulating a regular user → UI reflects that user's permissions, top banner indicates emulation, audit log records it.
- [ ] **SA (emulating)** Cannot perform irreversibly destructive actions that SA logic blocks; emulation passes through normal permission checks for everything else.
- [ ] **SA** Stop emulating → back to own identity.

### System layout admin (`/admin/system-layouts`)

- [ ] **SA** `/admin/system-layouts` lists rows for `dashboard_stats` and `dashboard_main` with rows/cols/gap/max-width/placement-count + an Edit button.
- [ ] **SA** `/admin/system-layouts/dashboard_main` opens the same editor shape the per-page composer uses. Block dropdown shows every active module's blocks grouped by category.
- [ ] **SA** Reorder a placement (swap content area and sidebar columns) → save → `/dashboard` reflects the new order.
- [ ] **SA** Add a placement → it appears on the dashboard at the configured position.
- [ ] **SA** Mark a placement's Remove checkbox + save → row deleted from `system_block_placements`; dashboard reflects the removal.
- [ ] **SA** Save the layout with malformed settings JSON → settings stored as NULL; the block falls back to its default settings.
- [ ] **A** (non-SA admin) hits `/admin/system-layouts` → bounced via RequireSuperadmin.
- [ ] **SA** Visit `/admin/system-layouts/some_invalid_name!!` → redirected with "Invalid system layout name" error flash. Names are restricted to `[a-zA-Z0-9_]+`.

### Disabled-by-admin lifecycle (`/admin/modules`)

- [ ] **SA** On `/admin/modules`, an active module's row shows a "Disable" button. A disabled-by-admin module shows "Enable". A disabled-by-dependency module shows "—" (admins fix the dep instead).
- [ ] **SA** Click Disable on a leaf module → confirm dialog → success flash, page reloads, the module shows "disabled by admin" badge. `module_status.state = 'disabled_admin'`. `audit_log` has a `module.disabled_by_admin` row.
- [ ] **SA** Visit a route owned by the disabled module → 404 (the route is no longer registered).
- [ ] **SA** Click Enable → confirm → flash; module returns to `active`; routes work again. `audit_log` records `module.enabled_by_admin`.
- [ ] **SA** Disable a module that's a dependency for another → both modules' status reflects the cascade on the next request: the parent shows `disabled_admin`, the dependent shows `disabled_dependency` with `missing` listing the parent.
- [ ] **SA** Re-enable the parent → next request, the dependent returns to active too.
- [ ] **SA** Disabling a module fires NO SA notification (you're the admin doing it). But the cascading dependency-disable on dependent modules DOES fire a notification — admins should hear about side effects.
- [ ] **A** (non-SA admin) crafts a POST to `/admin/modules/{name}/disable` with a valid CSRF → bounced by `RequireSuperadmin`.

### Dashboard composer

- [ ] **U** `/dashboard` renders a stats strip on top, then the main grid below. Layouts are driven by the `system_block_placements` table — no hardcoded paths.
- [ ] **U** Personal content section + per-group content sections render with sortable column headers; clicking a header toggles asc/desc.
- [ ] **A** Total Users + Total Groups cards appear for admins; for non-admins those cells render empty (the blocks return '' for non-admins).
- [ ] **SA** Disable a module that the dashboard depends on → next request, blocks owned by that module become orphaned. Logged-in admins see yellow placeholders; end-users see nothing. The rest of the dashboard keeps working.
- [ ] **SA** Re-enable the module → placeholders disappear.
- [ ] **U** Resize the browser below 720px → composers collapse to single columns.

### Page composer

- [ ] **A** On `/admin/pages/{id}/edit` for any existing page, click the "⊞ Layout & blocks" button → lands on `/admin/pages/{id}/layout`. The header reads "No layout — page renders body content" the first time; the form is pre-filled with sensible defaults (rows=2, cols=2).
- [ ] **A** Save the layout with no placements → `page_layouts` row appears; the page's public URL still renders normally (empty grid, but body fallback no longer applies once a layout exists).
- [ ] **A** Click "+ Add placement" → a new row appears with row/col/order numeric inputs, a block dropdown grouped by category, a visible-to dropdown, and a settings textarea.
- [ ] **A** Pick a block, set row=0/col=0, save → public page renders the block in the top-left cell.
- [ ] **A** Add a second placement at row=0/col=1 with the SAME block but a different settings JSON → both renders honour their own settings.
- [ ] **A** Add two placements in the same cell with different sort_orders → both render stacked top-to-bottom in the cell.
- [ ] **A** Set a placement's "Visible to" to "Logged in" → block disappears for guests. "Guests only" — opposite. "Anyone" — both.
- [ ] **A** Mark a placement's "Remove" checkbox + save → that placement is deleted; the rest persist.
- [ ] **A** Edit the layout to change rows/cols → public page renders the new grid.
- [ ] **A** Click "Remove layout" → confirm dialog, layout + placements wiped, page reverts to body-only rendering.
- [ ] **G/U** Resize the browser below 720px → grid collapses to a single column with placements stacked top-to-bottom.
- [ ] **A** If a placement points at a block whose module has been disabled, reload the page while logged in as admin → cell shows a yellow placeholder. Guests see no placeholder (silent).
- [ ] **A** Bad settings JSON saved on a placement → the row is stored with `settings IS NULL`; render falls back to the block's `defaultSettings`. No 500.

### Modules + dependencies (`/admin/modules`)

- [ ] **SA** `/admin/modules` renders a roster of every discovered module with state badges (active / disabled — missing deps / disabled by admin) and a "requires" column.
- [ ] **SA** Add `public function requires(): array { return ['nonexistent_module']; }` to a module's `module.php` → next request renders that module as `disabled — missing deps` with `nonexistent_module` listed as missing.
- [ ] **SA** `module_status` table has a row with `state='disabled_dependency'` and `missing_deps=["nonexistent_module"]`.
- [ ] **SA** A new in-app notification appears in the bell (and an email is delivered when the SA email setting is on) the FIRST time a module transitions to disabled. Refreshing the page does NOT generate a second notification — dedup ensures one event per state change.
- [ ] **SA** Remove the synthetic `requires()` line → module reactivates on the next request, status flips back to `active`, no extra notification fires.
- [ ] **SA** "Email superadmins when a module is auto-disabled" toggle in `/admin/settings/security`: turn OFF, then trigger a transition → in-app notification fires, email does not. Turn ON again → next transition includes email.
- [ ] **A** (non-SA admin) visits `/admin/modules` → bounced via `RequireSuperadmin`.

---

## Foundation modules (core)

### profile

- [ ] **U** Visit `/profile`, update name/bio/avatar → changes persist.
- [ ] **U** Upload an oversize avatar → rejected with clear error.
- [ ] **U** Upload a non-image file as avatar → rejected (MIME check).
- [ ] **U** Visit another user's public profile → see only public fields.

### settings

**Dedicated admin pages** — Appearance, Footer, Security & Privacy, Registration & Access.

- [ ] **SA** All dedicated pages share the same layout: a centered 720px column with a small "← All settings" back link above a bold heading.
- [ ] **SA** Every boolean setting on a dedicated page renders as a sliding toggle — no plain checkboxes anywhere in `/admin/settings/*`.
- [ ] **SA** Changing a setting reflects immediately on the next page render (e.g. flip `footer_enabled` off → footer disappears; flip `account_sessions_enabled` off → "Active Sessions" link vanishes from the user menu and the URL 404s).
- [ ] **SA** Each dedicated page's save writes an `audit_log` entry (`settings.<page>.save`).

**Generic grid (`/admin/settings?scope=site`)**

- [ ] **SA** At `scope=site`, managed keys (the ones owned by dedicated pages) do NOT appear in the grid.
- [ ] **SA** A blue info banner at the top of the site-scope grid explains that keys managed on dedicated pages are hidden.
- [ ] **SA** If only managed keys exist at `scope=site`, the empty-state message reads "No ad-hoc settings yet" — not the generic "No settings yet."
- [ ] **SA** Switch to `scope=page` / `scope=function` / `scope=group` tabs → all keys for those scopes show.
- [ ] **SA** Type-appropriate widgets render per row: boolean → sliding toggle; integer → `<input type="number">`; text/json → `<textarea>`; string → text input.
- [ ] **SA** Toggle a boolean off, press Save All → DB row's `value` flips, `type` stays `boolean`.
- [ ] **SA** Add a new custom setting via the "Add Setting" card → row appears in the grid on refresh and is editable.

**Managed-key guardrails**

- [ ] **SA** The delete (×) button does not render for any managed key.
- [ ] **SA** Craft a delete POST for a managed key against `/admin/settings/delete` → request refused with "managed on a dedicated settings page and can't be deleted here."
- [ ] **SA** Craft a save POST that includes a managed key → silently skipped; DB untouched.
- [ ] **SA** Attempt to "Add Setting" with a managed key name → silently ignored.

**Permission checks**

- [ ] **A** (non-SA) visits `/admin/settings` or any dedicated sub-page → redirected with "Superadmin access required" flash.
- [ ] **U** Non-admin visits any `/admin/settings/*` URL → bounced before the controller runs.

### menus

- [ ] **A** `/admin/menus` — create a menu, add items, reorder, save.
- [ ] **G/U** Visit a page rendering that menu → items appear in the right order.
- [ ] **A** Delete a menu → references on pages gracefully degrade (empty menu, not error).

### notifications

- [ ] **U** Perform an action that generates a notification → bell-badge count increments.
- [ ] **U** Open `/notifications` → list shown, marked read on view or via button.
- [ ] **U** Configure per-channel preferences (if surfaced) → respected on next dispatch.
- [ ] **A** Disable a notification type site-wide → no dispatch occurs for that type.

---

## Content modules (core)

### pages

- [ ] **A** `/admin/pages` — create a page with title, slug, body; publish.
- [ ] **G** Visit `/{slug}` → page renders.
- [ ] **A** Unpublish page → 404 for guests; still visible in admin list.
- [ ] **A** Delete page → removed everywhere.

### faq

- [ ] **A** Create FAQ entries grouped by category.
- [ ] **G** `/faq` renders with collapsible entries; deep-link to a specific entry works.

### hierarchies

- [ ] **S-hierarchies** `/admin/hierarchies/create` — new tree.
- [ ] **S-hierarchies** Add root node, then child node, then grandchild.
- [ ] **S-hierarchies** Delete a mid-level node → descendants cascade-delete.
- [ ] **Developer** In a view, call `hierarchy_tree('slug')` → nested array returned; `render_hierarchy_nav('slug')` outputs `<ul>`.

### taxonomy

- [ ] **A** Create a vocabulary/set and a few terms.
- [ ] **A** Attach a term to a content item via `attach_term(...)` (or the admin UI if wired for that type).
- [ ] **G/U** Browse by term → items filtered correctly.
- [ ] **A** Delete a term → `taxonomy_entity_terms` rows cascade.

---

## Utility modules (core)

### integrations

- [ ] **A** Configure an integration (Slack/Discord/etc.).
- [ ] **A** Send a test message → delivered to target channel.
- [ ] **A** Disable integration → no further dispatches.

### audit-log-viewer

- [ ] **A** Log in, log out → two rows in `audit_log`: `auth.login`, `auth.logout`.
- [ ] **A** `/admin/audit-log?action=auth.*` — prefix match filters to the two rows.
- [ ] **A** Click into a row → detail view shows old/new JSON side-by-side.
- [ ] **A** Filter by date range → results bounded.
- [ ] **SA** Toggle superadmin mode → new row with `superadmin=1` visible in viewer.

### api-keys

- [ ] **U** `/account/api-keys` — empty list.
- [ ] **U** Create key with scopes `read:store` → plaintext token shown ONCE; copy it.
- [ ] **U** Reload the page → token is gone; only prefix + last 4 remain.
- [ ] **API** `curl -H "Authorization: Bearer {token}" {app_url}/api/me` → 200 with user_id + scopes.
- [ ] **API** Same curl without the header → 401 `missing_bearer_token`.
- [ ] **API** With a garbage token → 401 `invalid_token`.
- [ ] **U** Revoke the key → subsequent API call returns 401.
- [ ] **U** Create a key with a 1-day expiry → after the date passes, API calls return 401.
- [ ] **U** Create a key with empty scopes list → API calls to scoped endpoints return 403 `missing_scope`; unscoped endpoints (`/api/me`) still work.

### import-export

- [ ] **A** `/admin/import` — upload a user CSV with a few rows.
- [ ] **A** `/admin/import/{id}` — map columns → Dry run shows row counts without writing.
- [ ] **A** Click Run → rows inserted/updated; stats match the file.
- [ ] **A** Upload CSV with invalid email rows → dry run surfaces per-row errors; Run processes valid ones and records errors in `errors_json`.
- [ ] **A** `/admin/export/users.csv` — download → file opens cleanly in a spreadsheet app.
- [ ] **Developer** Register a new handler for a custom entity type in a module's `register()` → it appears in the dropdown; upload + import round-trips.

### feature-flags

- [ ] **A** `/admin/feature-flags/create` — new flag, enabled, rollout=100.
- [ ] **Developer** Add `<?= feature('flag_key') ? 'NEW' : 'OLD' ?>` in a view.
- [ ] **U** Page renders "NEW".
- [ ] **A** Flip global enabled off → page renders "OLD" (cache invalidated).
- [ ] **A** Re-enable; set rollout=0 → all users see "OLD".
- [ ] **A** Set rollout=50 → roughly half see "NEW"; same user sees the same branch on every reload (deterministic hash).
- [ ] **A** Create per-user override (user_id=N, enabled=1) while flag is disabled → that user sees "NEW", everyone else "OLD".
- [ ] **A** Add a group to the flag; rollout=0 → group members see "NEW".
- [ ] **A** Clear override → user reverts to the general rule.
- [ ] **A** Delete flag → override rows cascade; `feature(...)` returns false thereafter.

---

## Compliance modules (core)

### Cookie consent (`cookieconsent`)

- [ ] **G** Visit `/` in a fresh browser profile → bottom-banner appears with **Accept all** / **Customize** / **Reject all** all equally prominent.
- [ ] **G** Click **Customize** → modal opens with 4 categories (necessary always-locked-on, preferences, analytics, marketing) + per-category toggles + descriptions.
- [ ] **G** Click **Reject all** → banner dismisses, `cookie_consent` cookie set; row in `cookie_consents` with `action='reject_all'`, IP packed as VARBINARY, truncated UA.
- [ ] **G** Click **Accept all** → same flow with `action='accept_all'`.
- [ ] **U** While signed in, accept → row has `user_id` populated; `audit_log` entry `cookieconsent.save`.
- [ ] **SA** `/admin/cookie-consent` → 30-day stats strip + recent 50 events with action badges. Click "Bump version" → `policy_version` increments; every browser sees the banner again on next page view.
- [ ] **A** Drop `cookieconsent.reopen_link` block onto a page → renders a link that wipes the consent cookie + reloads.
- [ ] **Developer** `consent_allowed('analytics')` returns false until accepted, true after accept. `consent_allowed('necessary')` is always true.

### GDPR / DSAR (`gdpr`)

- [ ] **U** Visit `/account/data` → dashboard renders with three cards: export, restrict, delete. Empty state: "No exports yet."
- [ ] **U** Click **Build export** → row in `data_exports` with `status=ready`, `download_token`, file under `storage/gdpr/exports/`. Inline "Download" link.
- [ ] **U** Open the downloaded zip → README.txt + account.json (no password / 2FA secret leaked) + per-module folders for every module that holds your data.
- [ ] **U** Build a second export within the rate window → blocked with "you already have a recent export."
- [ ] **U** Click **Delete my account** → must type "delete my account" verbatim. Confirm → `users.deletion_grace_until` set 30 days out; `deletion_token` populated; DSAR row created (`kind=erasure`).
- [ ] **U** Visit `/account/data` again → red "deletion scheduled" card with "Cancel deletion now" link.
- [ ] **U** Click the cancel link → grace columns NULLed; back to normal.
- [ ] **U** Click **Apply restriction** → `users.processing_restricted_at` stamped; audit row.
- [ ] **A** `/admin/gdpr` → DSAR queue with stats strip + pending erasures table showing hours-remaining countdowns. Filter by status works.
- [ ] **A** `/admin/gdpr/dsar/{id}` for an erasure DSAR → typing "erase" and submitting fires `DataPurger`; user erased in one transaction; `audit_log` row `gdpr.user_erased` with stats; user's email becomes `erased-{id}@invalid.local`, password NULL, etc.
- [ ] **A** `/admin/gdpr/handlers` → registry inspection. Each legal-hold table shows its `legal_hold_reason` text.
- [ ] After erasure, spot-check that affected modules' rows now show `[erased user #N]` where they were the user, OR are gone entirely if the handler said `erase`.

### Policy versioning (`policies`)

- [ ] **A** Create a CMS page at `/page/terms` with some body content.
- [ ] **A** `/admin/policies/1` (ToS kind) → assign source page = the page you just created. Click **Bump version 1.0** → snapshot stored; `current_version_id` flips.
- [ ] **U** On the next page request, blocking modal appears with "Please review our updated terms" + checkbox per required policy + link to view full text. Cannot dismiss without accepting.
- [ ] **U** Try to navigate to `/dashboard` → redirected back to `/policies/accept`. Logout link still works.
- [ ] **U** Check + submit → redirected to wherever you originally tried to go (intended_url stash); `policy_acceptances` row written with IP + UA; audit log entry.
- [ ] **G** Visit `/register` → checkboxes appear for every required policy with version label; submitting without checking → rejected.
- [ ] **U** `/account/policies` → history of every accept; pending banner if anything's been bumped you haven't accepted.
- [ ] **A** Edit the source page → bump version 2.0 → users see the modal again on next request.
- [ ] **SA** `/admin/policies/{id}/v/{vid}` → snapshot body + acceptance ratio + most recent 100 acceptances with IP + UA truncated.

### Data retention (`retention`)

- [ ] **A** `/admin/retention` → "Re-sync from modules" button — clicks pull all module-declared rules into the table. Initial sync should land ~10-15 rules across core + active modules.
- [ ] **A** Per-rule detail page → adjust days-keep, action, enabled → save → row updates with `source='admin_custom'`.
- [ ] **A** Click **Preview** on a rule → "N rows would be affected" flash without writing.
- [ ] **A** Click **Run now** → rows actually purged/anonymized; `last_run_*` columns updated; new `retention_runs` row.
- [ ] **A** `/admin/retention` index — recent runs table populated.
- [ ] **Developer** Manually insert some old `audit_log` rows. Run the audit-log retention rule. Confirm `ip_address` + `user_agent` columns NULLed on rows older than configured (anonymise rather than delete for legal-hold tables).

### Email compliance (`email`)

- [ ] **U** Trigger a marketing-category email. Inbox shows the email with a footer "Unsubscribe" link + "Manage preferences" link. Source: `List-Unsubscribe` + `List-Unsubscribe-Post` headers present.
- [ ] **G** Click the footer Unsubscribe link → `/unsubscribe/{token}` lands → click "Confirm" → row in `mail_suppressions` with `reason='user_unsubscribe'`. "Unsubscribed" page shown.
- [ ] **U** Trigger another marketing-category email after the unsubscribe → not sent; row appears in `mail_suppression_blocks`.
- [ ] **U** `/account/email-preferences` → toggle a non-transactional category off + save → suppression added; toggle back on → removed. Transactional categories render disabled-checked with "always on" badge.
- [ ] **A** `/admin/email-suppressions` → list with stats strip + manual add form + search by email. Reason badges color-coded.
- [ ] **A** `/admin/email-suppressions/blocks` → log of skipped sends.
- [ ] **A** `/admin/email-suppressions/bounces` → webhook event log (empty until provider webhooks fire).

### Audit chain (`auditchain`)

- [ ] **A** `/admin/audit-chain` → health overview with stats. After audit-generating actions, the chain has rows.
- [ ] **A** Click **Verify range** with default dates → run completes cleanly (0 breaks); `retention_chain_runs` row created.
- [ ] **A** Manually edit an `audit_log` row's `notes` column via DB tool. Re-run verify → break detected, `audit_chain_breaks` row with `reason='hash_mismatch'`. Superadmins get an in-app notification + email.
- [ ] **A** `/admin/audit-chain/breaks` → break listed in red. Type a note + click **Ack** → row marked acknowledged. Re-run verify — break still in log but not flagged unack.

### Security: HIBP password breach (`security`)

- [ ] **G** Try to register with a famously-breached password like `Password123!` → rejected with "This password has been seen in a known data breach."
- [ ] **G** Try a strong unique password → succeeds.
- [ ] **U** Try password reset with a breached password → same rejection.
- [ ] **A** `/admin/users/{id}/edit` → set password to a breached one → same rejection.
- [ ] **SA** `/admin/settings/security` → toggle off **Block on confirmed breach** → behavior switches to warn-only; breached passwords go through but the user sees a warning.
- [ ] **SA** Toggle off **Check passwords against HIBP corpus** → no check at all.
- [ ] DB → `password_breach_cache` table populated after a few attempts; rows have `expires_at` set 24h out.

### Security: sliding session timeout

- [ ] **SA** `/admin/settings/security` → set "Session inactivity timeout" to 1 minute, save.
- [ ] **U** Sign in, wait 90 seconds without doing anything, then click any link → redirected to `/login` with "after 1 minute of inactivity" flash; audit row `auth.session_idle_timeout`.
- [ ] **U** Sign in, do an action every 30 seconds for 3 minutes → session stays alive (sliding window resets on each request).
- [ ] **SA** Set the timeout back to 0 (disabled) → behavior reverts.

### Security: admin IP allowlist

- [ ] **SA** `/admin/settings/security` → enter your current IP in the allowlist textarea (it's auto-detected and shown next to the field). Toggle on, save → save succeeds (anti-lockout check passes).
- [ ] **SA** Try to save with the allowlist toggle on but a CIDR list that doesn't include your IP → save **refused** with "you would lock yourself out" error. Setting not persisted.
- [ ] **U** From a different network/IP → visit `/admin` → 403 page with your IP shown.
- [ ] **SA** Toggle off → admin access restored.

### Security: PII access logging

- [ ] **SA** `/admin/settings/security` → toggle on "Log admin reads of personal data" (default on).
- [ ] **A** Visit `/admin/users/1` → audit_log row `pii.viewed` with your actor_user_id, path in new_values.
- [ ] **A** Refresh the same page within 30 seconds → no duplicate audit row (in-process throttle).
- [ ] **A** Visit `/admin/sessions` and `/admin/audit-log` → each generates its own pii.viewed row (different paths).

### Accessibility (`accessibility`)

- [ ] **G** First Tab key on any page → "Skip to main content" link becomes visible at top-left. Pressing Enter jumps focus past the header.
- [ ] **G** Tab through any page → focus indicators visible on every interactive element. No bare `outline:none` without replacement.
- [ ] **A** `/admin/a11y` → lint scan runs sub-second for typical sites. Stats strip + by-rule table + per-file findings list.
- [ ] **A** Click **Re-scan** → button fires; audit row written.
- [ ] **Developer** Terminal: `php artisan a11y:lint` runs the same scan with CLI output. `--errors-only` exits non-zero on any error finding (suitable as a CI gate). `--json` emits machine-readable output.

### CCPA "Do Not Sell" (`ccpa`)

- [ ] **G** Site footer shows "Do Not Sell or Share My Personal Information" link (when `ccpa_enabled` is on, default).
- [ ] **G** Click it → `/do-not-sell` form. Without an account, must provide email. Submit → cookie + `ccpa_opt_outs` row created; "you've been opted out" confirmation card on next visit.
- [ ] **U** While signed in, visit `/do-not-sell` → form pre-fills with account email; submit → row tied to user_id; audit row `ccpa.opt_out_recorded`.
- [ ] **U** Send a request with `Sec-GPC: 1` header (DevTools → Network → Modify request headers). Visit `/do-not-sell` → green "Global Privacy Control detected" banner.
- [ ] **Developer** `ccpa_opted_out()` returns true after the user opts out OR when the GPC header is present on the current request.
- [ ] **A** `/admin/ccpa` → stats by source (self_service / GPC / admin / withdrawn) + recent 100 opt-outs.

### Login anomaly detection (`loginanomaly`)

- [ ] **SA** `/admin/settings/security` → toggle on "Detect impossible-travel sign-ins". Save.
- [ ] **U** Sign in once from your normal browser. Wait a few minutes, then sign in from a VPN exit point in another country → email arrives subject "[WARN] Suspicious sign-in" with detected vs prior locations + computed km/h.
- [ ] **A** `/admin/security/anomalies` → row appears with severity badge (warn) + rule (impossible_travel). Country flags + city pair shown.
- [ ] **A** Click **Ack** on the row → marked acknowledged.
- [ ] DB → `login_geo_cache` populated for both IPs with country, city, lat, lon. `expires_at` 30 days out.
- [ ] **SA** `/admin/security/anomalies` after a quiet day → "0 anomalies" green banner.

### COPPA age gate (`coppa`)

- [ ] **SA** `/admin/settings/access` → toggle on "Require date-of-birth + age gate at registration". Set minimum age (default 13). Save.
- [ ] **G** `/register` → date-of-birth field appears with helper text.
- [ ] **G** Submit with a DOB making you under the threshold → rejected with the configured message; `audit_log` row `coppa.registration_blocked` with IP, UA, minimum age, and a SHA-256 prefix of the email (no DOB stored).
- [ ] **G** Submit with a DOB making you over the threshold → registration succeeds; `users.date_of_birth` populated.
- [ ] **G** Submit without a DOB → "Please provide your date of birth."
- [ ] **A** `/admin/coppa` → recent rejections table with IP + email hash + min-age-at-time. Yellow callout explains "no DOB stored on rejection."
- [ ] **SA** Toggle off → DOB field disappears from registration form.

---

## Cross-cutting final sweep

Once every section is individually green, walk this list:

- [ ] **A** PHP error log is empty of unexpected warnings/notices across a full browse session.
- [ ] **A** `audit_log` row count is growing with sensible entries; no obvious PII leaks (passwords, card numbers, etc.).
- [ ] **A** `jobs` table has no permanently-stuck rows.
- [ ] **A** Every `scheduled_tasks` row has a recent `last_run_at`.
- [ ] **A** `php artisan schedule:run` runs cleanly end-to-end.
- [ ] **A** Database migrations run cleanly on a *fresh* database (`drop database … ; create database … ; artisan migrate`).
- [ ] **A** CSRF is required on every state-changing POST.
- [ ] **A** No route returns a 500 under a default-shaped request.
- [ ] **SA** Pull up `/admin/audit-log?action=auth.*` after a representative day's traffic — logins/logouts look reasonable, no unexpected emulation events.
- [ ] **Developer** `php -l` (or `bin/php -l`) across every file in `core/` + `modules/` — zero errors.
- [ ] **Developer** `composer install --no-dev --optimize-autoloader` on a clean checkout → autoloads without warnings.
- [ ] **SA** Walk every `/admin/settings/*` dedicated page once more: layout is centered, back link is present, every boolean is a slider (no plain checkboxes), and the generic grid at `scope=site` shows no managed keys.

---

## Notes on execution

Going **bottom-up** (core framework → foundation modules → consumers) is usually fastest because later modules depend on earlier ones; a bug in auth masquerades as a bug elsewhere otherwise.

When something fails, the **audit log viewer** is usually the fastest way to understand what actually happened — most auth/permission-related failures leave audit trails before you reach the shell.

If your install includes premium modules, run the parallel checklist in the
`claudephpframeworkpremium` repo's `docs/qa-checklist.md` after this one is green.
