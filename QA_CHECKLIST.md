# QA checklist — core + all modules

Systematic verification list for the claudephpframework monorepo. Work
through it bottom-up (core first, then foundation modules, then
consumers). Each check is tagged with the user role that should be
signed in to perform it.

## Role legend

| Tag  | Role                                        | How to get it |
|------|---------------------------------------------|---------------|
| G    | Guest (not logged in)                       | Log out |
| U    | User (authenticated, no special perms)      | Register + log in |
| GM   | Group member                                | Join a group |
| GA   | Group admin                                 | User with `group_admin` base_role in a group |
| GO   | Group owner                                 | User with `group_owner` base_role in a group |
| M    | Site moderator                              | Role with `comments.moderate` or `reviews.moderate` |
| A    | Site admin                                  | Role with `admin.access` (gates `RequireAdmin` middleware) |
| SA   | Superadmin                                  | User with is_superadmin flag; can toggle superadmin mode |
| S-*  | Staff role with a specific `.manage` perm   | e.g. S-helpdesk, S-store, S-events |
| API  | API client with a valid Bearer token        | Mint at `/account/api-keys` |

Many checks work for multiple roles. Where an action should produce a
*different* result for different roles, both are listed.

## Pre-flight

Before running through the module sections:

- [x ] `.env` contains database credentials, `APP_URL`, `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET` (if testing commerce), `MAIL_*` config.
- [x ] `php artisan migrate` succeeds with no errors.
- [x ] `composer install` has run, `vendor/` is populated.
- [x ] Web server is serving `public/` as the document root.
- [x ] `storage/` is writable by the web server user.
- [x ] Seed at least these roles + permissions: an admin role with `admin.access`, a Moderator role (already seeded), and one or two groups.
- [x ] Create three test users: `qa_admin` (admin role), `qa_user` (no special perms), `qa_user2` (for cross-user tests).

---

## Core framework

### Registration + login

- [x ] **G** Visit `/register`, fill the form, submit → account created, redirected to dashboard/home.
- [x ] **G** Register with an existing email → validation error, no duplicate row created.
- [x ] **G** Register with a weak password → rejected with a clear message.
- [x ] **G** Log in with correct credentials → `session_regenerate_id(true)` fires; note the post-login cookie value, confirm the matching row in `sessions` has `user_id = <you>` and the pre-login guest row for the old cookie value is gone.
- [x ] **G** Log in with wrong password → rejected; the row in `sessions` for your current cookie stays in guest state (`user_id IS NULL`); rate-limit counter increments.
- [x ] **U** Log out → cannot access any authenticated page; redirected to `/login`.
- [x ] **U** Immediately after `/logout`, note the cookie value from before logout — that row is gone from `sessions`, and DevTools shows `cphpfw_session` cleared (Set-Cookie with past expiry).
- [x ] **U** On the *next* page load after logout, a fresh guest session is created: `cphpfw_session` reappears with a **different** session id than before, and the matching new `sessions` row has `user_id IS NULL`. Deleting the cookie by hand then refreshing behaves the same way — that's expected PHP session behaviour, not a logout bug. The verifiable invariant is that the authenticated pre-logout session id never comes back.
- [x ] **A** Delete `qa_user`'s row from the `sessions` table while they're logged in → their next request lands on `/login`.
- [x ] **A** With two open sessions for `qa_user` in different browsers, `DELETE FROM sessions WHERE user_id = <qa_user_id>` kicks both → emergency sign-out works.

### 2FA

- [x ] **U** Enable 2FA in profile settings → TOTP secret generated, QR visible.
- [x ] **U** Scan QR, enter code → 2FA enabled.
- [X ] **U** Log out + log in → prompted for 2FA code after password.
- [x ] **U** Enter wrong 2FA code 5× → account temporarily locked or rate-limited.
- [X ] **U** Disable 2FA from settings → subsequent logins skip the 2FA step.

### Password reset

- [x ] **G** Request reset for existing email → reset email sent (check mail driver log if in dev).
- [x ] **G** Request reset for non-existent email → same success response (no email enumeration).
- [x ] **G** Use the reset link → can set a new password.
- [x ] **G** Use an expired/used reset link → rejected.

### OAuth login (if configured)

- [ ] **G** Log in via Google/GitHub → redirected through provider, account linked, session starts.
- [ ] **U** Unlink the OAuth provider from profile → can't log in via that provider afterward.

### Session + CSRF

- [x ] **U** Submit any POST form without `_token` → rejected with CSRF error.
- [x ] **U** Submit a POST form with a stale token → rejected.
- [x ] **U** Two browser tabs, log out in one → other tab's next request redirects to `/login`.

### Registration & access (`/admin/settings/access`)

- [x ] **SA** Visit `/admin/settings/access` → page renders three toggles (allow_registration, require_email_verify, maintenance_mode). Each is a slider (matches the header superadmin toggle), not a checkbox.
- [x ] **SA** Toggle `allow_registration` **off** + save → visiting `/register` as guest shows "Registration is closed"; POSTing directly to `/register` with valid-looking payload also returns the same page (bypass fix).
- [x ] **SA** Toggle `allow_registration` back **on** → signup works end-to-end.
- [x ] **SA** Toggle `require_email_verify` **on** + save. Create a new user whose `email_verified_at IS NULL` → that user cannot log in; login submits, credentials validate, but they're signed right back out with the "Please verify your email" error.
- [x ] **SA** Superadmins are exempt: a superadmin account with `email_verified_at IS NULL` can still log in when the flag is on (so an admin who toggles it on first doesn't lock themselves out).
- [ ] **SA** On `/admin/users/{id}` for the blocked user, click the "Mark verified" button (superadmin-only, sits next to "Resend link" when the user is unverified) → confirm dialog → success flash, the row now reads "✅ today's date", and `audit_log` has an `auth.verification_marked_by_admin` row with the issuing admin's id in `new_values`. Retry the user's login → succeeds.
- [ ] **SA** Verify any outstanding verification token in `email_verifications` for that user has `used_at` stamped after Mark verified — so a stray click on the old link in their inbox can't redeem.
- [ ] **A** (non-SA admin) The "Mark verified" button does not render for them. POSTing to `/admin/users/{id}/mark-verified` with a valid CSRF as a non-SA admin → bounced by `RequireSuperadmin` middleware.
- [x ] **SA** Toggle `maintenance_mode` **on** + save. Log out / open an incognito window → every route returns 503 with the maintenance page **except** the login surface: `/login`, `/logout`, `/dev/login-as`, `/auth/2fa/*` (challenge, resend, recovery), and `/auth/oauth/*`. Site name on the page matches `site_name` setting.
- [x ] **SA** In a dev environment, the "Admin sign-in" link on the maintenance page works end-to-end: clicking through to `/login`, entering SA credentials, passing any 2FA prompt, and clicking the dev shortcut all pass the maintenance gate without a 503.
- [x ] **SA** Superadmins are exempt from maintenance too: in your main browser you can still reach `/admin/settings/access` and toggle it back off.
- [ ] **SA** Verify the 503 response includes `Retry-After: 3600` header.

### Active sessions — admin surface (`/admin/sessions`)

- [ ] **A** Visit `/admin/sessions` → lists every row in `sessions`, joined to users, newest `last_activity` first. Guest rows show "(no user)" or similar.
- [ ] **A** Sidebar "Top users by session count" shows sensible numbers (e.g. you → 1+ after your own login).
- [ ] **A** Use `?user_id=<qa_user_id>` query → list narrows to that user only.
- [ ] **A** Click "Terminate" on a single qa_user row → row is deleted, audit_log gets a `session.terminate` entry with the truncated session id, qa_user's next request lands on `/login`.
- [ ] **A** Click "Sign out all devices" on qa_user → every qa_user row deleted in one transaction, audit_log gets `session.terminate_all` with `terminated_count`. Confirm qa_user is kicked from every open browser.
- [ ] **U** Non-admin visiting `/admin/sessions` → forbidden/redirect (RequireAdmin middleware).
- [ ] **A** POST to `/admin/sessions/{id}/terminate` without CSRF → rejected (CsrfMiddleware on route).

### Active sessions — user surface (`/account/sessions`)

- [ ] **SA** In `/admin/settings/security`, verify the toggle `Users can review + terminate their own active sessions` renders as a slider (not a checkbox) and matches the superadmin header toggle style visually.
- [ ] **SA** With the setting **ON**: user-menu dropdown shows "📱 Active Sessions"; `/account/sessions` renders the user's own session list.
- [ ] **SA** With the setting **OFF**: dropdown link disappears; `/account/sessions` returns 404.
- [ ] **U** With two browsers signed in, visit `/account/sessions` in browser A → current browser is tagged "This device"; browser B is listed without the tag.
- [ ] **U** Click "Sign out" on browser B's row → row gone; browser B's next request lands on `/login`; browser A's session is untouched.
- [ ] **U** Click "Sign out" on the "This device" row → confirm dialog fires with distinct copy; after confirm, redirected to `/login`.
- [ ] **SA** Have qa_user visit `/account/sessions` with an `id` that belongs to another user (e.g. edit the form action URL). POST is scoped by `user_id` — should not delete someone else's session.

### New-device login email

- [ ] **SA** In `/admin/settings/security`, verify the toggle `Email users when they sign in from an unrecognized device` renders as a slider.
- [ ] **SA** With the setting **OFF**: log out qa_user, clear their UA in `sessions` (`DELETE FROM sessions WHERE user_id = <qa_user_id>`), log back in → no email sent; mail log is clean.
- [ ] **SA** With the setting **ON** and `qa_user.last_login_at IS NULL` (brand-new account): first login triggers **no** email (suppress welcome-spam).
- [ ] **SA** With the setting **ON** and at least one prior `sessions` row for qa_user: sign in again from the same browser (same `User-Agent`) → **no** email (known device).
- [ ] **SA** With the setting **ON**, sign in from a second browser (different `User-Agent`) while a prior session exists for qa_user → email dispatched via configured mail driver; body contains When / IP / Device rows and a link back to `/account/sessions`.
- [ ] **SA** After the email send, `audit_log` has an `auth.new_device_email_sent` row with `model_id = qa_user.id`.
- [ ] **SA** Simulate a mail driver failure (e.g. `MAIL_DRIVER=log` in prod is blocked — use a bogus SMTP) → login completes normally; failure is logged to `storage/logs/php_error.log`, not raised.

### Roles + permissions

- [ ] **A** Visit `/admin/roles`, create a custom role, attach a permission, assign to a user.
- [ ] **U** After role assignment, user can access the permission's surface. Remove role → can't anymore.
- [ ] **A** Attempt to modify a system role (e.g. Moderator) → allowed only if policy allows it; otherwise blocked.

### Superadmin mode

- [ ] **SA** Toggle superadmin mode on → admin surfaces expand (whatever SA-only views exist). Audit log records `superadmin.mode_toggle`.
- [ ] **SA** Toggle off → surfaces revert.
- [ ] **A** (non-SA) visiting SA-only surface → forbidden/404.

### Emulation

- [ ] **SA** Start emulating `qa_user` → UI reflects qa_user's permissions, top banner indicates emulation, audit log records it.
- [ ] **SA (emulating)** Cannot perform irreversible destructive actions that SA logic blocks; emulation passes through normal permission checks for everything else.
- [ ] **SA** Stop emulating → back to own identity.

### System layout admin (Batch 5)

- [ ] **SA** `/admin/system-layouts` lists rows for `dashboard_stats` and `dashboard_main` with rows/cols/gap/max-width/placement-count + an Edit button.
- [ ] **SA** `/admin/system-layouts/dashboard_main` opens the same editor shape the per-page composer uses: grid form on top, placements table below with the same column order (Row / Col / Order / Block / Visible to / Settings / Remove). Block dropdown shows every active module's blocks grouped by category.
- [ ] **SA** Reorder a placement: change `dashboard_main` content_feed col_index from 0 to 1 (and the sidebar to col 0) → save → `/dashboard` now renders the sidebar on the left and content on the right.
- [ ] **SA** Add a placement: drop `polls.active` into `dashboard_main` row 0 col 0 sort_order=99 → it appears below content_feed in the left column on the dashboard.
- [ ] **SA** Mark a placement's Remove checkbox + save → row deleted from `system_block_placements`; dashboard reflects the removal.
- [ ] **SA** Save the layout with malformed settings JSON → settings stored as NULL (not the literal bad string); the block falls back to its default settings.
- [ ] **A** (non-SA admin) hits `/admin/system-layouts` → bounced via RequireSuperadmin middleware.
- [ ] **SA** Visit `/admin/system-layouts/some_invalid_name!!` → redirected to the index with an "Invalid system layout name" error flash. The regex restricts names to `[a-zA-Z0-9_]+`.

### Disabled-by-admin lifecycle (Batch 5)

- [ ] **SA** On `/admin/modules`, an active module's row shows a "Disable" button. A disabled-by-admin module shows "Enable". A disabled-by-dependency module shows "—" (no action — admins fix the dep instead).
- [ ] **SA** Click Disable on `events` (or any module without dependents) → confirm dialog → success flash, page reloads, `events` shows "disabled by admin" badge. `module_status.state = 'disabled_admin'`. `audit_log` has a `module.disabled_by_admin` row with the admin id in `new_values`.
- [ ] **SA** Visit `/events` while disabled → 404 (the route is no longer registered).
- [ ] **SA** Click Enable → confirm → flash; module returns to `active`; `/events` works again. `audit_log` records `module.enabled_by_admin`.
- [ ] **SA** Disable a module that's a dependency for another (e.g. add `requires(): ['groups']` to `social/module.php`, then disable `groups` from `/admin/modules`) → after save, both `groups` shows `disabled_admin` AND `social` shows `disabled_dependency` with `missing=['groups']` on the next request. The cascade fires automatically.
- [ ] **SA** Re-enable `groups` → next request, `social` returns to active too. Remove the synthetic `requires()` you added to social.
- [ ] **SA** Disabling a module fires NO SA notification (you're the admin doing it). But the cascading dependency-disable on dependent modules DOES fire one — admins should hear about side effects.
- [ ] **A** (non-SA admin) crafts a POST to `/admin/modules/groups/disable` with a valid CSRF token → request bounced by `RequireSuperadmin`.

### Block sweep across remaining modules (Batch 4)

- [ ] **A** `/admin/pages/{any-id}/layout` block dropdown shows all of these new blocks under their categories: store.recent_products (Commerce), social.my_feed (Social), polls.active (Engagement), events.upcoming (Events), knowledge_base.recent_articles (Knowledge Base), helpdesk.my_open_tickets (Helpdesk), reviews.recent + comments.recent (Moderation).
- [ ] **A** Drop store.recent_products into a page → renders product names + prices linking to /shop/{slug}; "View store →" link goes to /shop. Empty state when no products.
- [ ] **U** Drop social.my_feed → for guests, the cell renders empty (block returns ''); for auth users, shows feed posts with username, timestamp, and 140-char body excerpt; empty state pushes user to /feed/discover.
- [ ] **A** Drop polls.active → shows recently-published polls with the type badge (single/multiple/ranked); link to each poll works.
- [ ] **A** Drop events.upcoming → shows next N upcoming events with start date + time; ordered by starts_at ASC. Past events don't appear.
- [ ] **A** Drop knowledge_base.recent_articles → shows latest published KB articles with publish date.
- [ ] **U** Drop helpdesk.my_open_tickets → for guests, empty cell; for auth users, only their own open/pending/awaiting_customer tickets render (closed/resolved are filtered out). Ticket reference + status badge visible.
- [ ] **SA** Drop reviews.recent → renders for admins only; shows star rating, optional title, body excerpt. Returns '' for non-admins (cell stays empty).
- [ ] **SA** Drop comments.recent → renders for admins only; shows commenter, target type, body excerpt. Link to /admin/comments visible.
- [ ] **SA** Disable any one of these modules (add a fake `requires()`) → that module's block disappears from the picker AND any existing placement of it shows a yellow placeholder for admins, silent for guests. Other modules' blocks keep working.

### Dashboard composer migration (Batch 3)

- [ ] **U** `/dashboard` renders identically to before Batch 3: a 4-card stats strip on top (My Groups, Unread Notifications, plus Total Users + Total Groups when admin), then a 2-column main grid with the content feed on the left and the My Groups + Recent Notifications sidebar on the right.
- [ ] **U** Pending transfer-approval card and outgoing transfer card appear and behave exactly as they did pre-composer (approve/reject buttons for `group_admin` flow; accept/decline for `recipient` flow; cancel button on outgoing).
- [ ] **U** Personal content section + per-group content sections render with sortable column headers; clicking a header toggles asc/desc; clicking a different header resets to that column ascending. Sort works independently per table on the page.
- [ ] **A** Total Users + Total Groups cards appear for admins; for non-admins those cells render empty (the blocks return '' for non-admins). Visual matches pre-composer behaviour.
- [ ] **SA** `system_layouts` has rows `dashboard_stats` (1×4) and `dashboard_main` (1×2) post-migrate. `system_block_placements` has 4 rows for stats and 3 rows for main (content_feed at col 0, my_groups_list and recent_list at col 1 with sort_orders 0 and 1).
- [ ] **SA** Disable a module that the dashboard depends on (e.g. add `requires(): ['nonexistent']` to `notifications/module.php`) → next request, the two notification blocks (`unread_count` + `recent_list`) become orphaned. Logged-in admins see yellow placeholders in those cells; end-users see nothing. The rest of the dashboard keeps working.
- [ ] **SA** Re-enable the module → the placeholders disappear and the tiles return on the next request.
- [ ] **U** Resize the browser below 720px on `/dashboard` → both composers (stats strip + main grid) collapse to single columns. Stats stack 4 high, then content feed, then sidebar items below.
- [ ] **A** Edit `system_block_placements` directly to swap two stat-card placements' col_index → next request shows the cards in the swapped order. (Confirms the system layout is genuinely driving the render and not falling back to a hardcoded path.)
- [ ] Dev `/dashboard` controller (`app/Controllers/DashboardController.php`) is reduced to just the auth lookup + view call — no more queries, no more cache fetches. All data fetching has moved into the block render closures and `Modules\Content\Services\DashboardFeedData`.

### Page composer (Batch 2)

- [ ] **A** On `/admin/pages/{id}/edit` for any existing page, click the "⊞ Layout & blocks" button → lands on `/admin/pages/{id}/layout`. The header reads "No layout — page renders body content" the first time; the form is pre-filled with the defaults (rows=2, cols=2, col_widths=65,32, row_heights=32,65, gap=3, max_width=1280).
- [ ] **A** Save the layout with no placements → `page_layouts` row appears in the DB; the page's public URL still renders normally (empty grid, but body fallback no longer applies once a layout exists).
- [ ] **A** Click "+ Add placement" → a new row appears in the placements table with row/col/order numeric inputs, a block dropdown grouped by category, a visible-to dropdown, and a settings textarea. The block dropdown lists every block from active modules (e.g. `groups.my_groups_tile`).
- [ ] **A** Pick a block, set row=0/col=0, save → public page renders the block in the top-left cell of the grid.
- [ ] **A** Add a second placement at row=0/col=1 with the SAME block but a different settings JSON (e.g. `{"limit": 3}`) → both renders honour their own settings.
- [ ] **A** Add two placements in the same cell with different sort_orders → both render stacked top-to-bottom in the cell, ordered by sort_order ascending.
- [ ] **A** Set a placement's "Visible to" to "Logged in" → the block disappears for guests and appears for authenticated viewers. Set it to "Guests only" — opposite. "Anyone" renders for both.
- [ ] **A** Mark a placement's "Remove" checkbox + save → that placement is deleted; the rest persist.
- [ ] **A** Edit the layout to rows=3, cols=3, col_widths=33,33,33, gap=2 → public page renders the new grid; placements with row≥3 or col≥3 fall outside the rendered cells (correct — admins should clean up before shrinking).
- [ ] **A** Click "Remove layout" → confirm dialog, layout + placements wiped, page reverts to body-only rendering, the link reappears as "No layout — page renders body content".
- [ ] **G/U** Resize the browser below 720px → the grid collapses to a single column with placements stacked top-to-bottom in row-then-col order; side padding shrinks toward 8px.
- [ ] **A** If a placement points at a block whose module has been disabled (rename `requires()` to break it), reload the page while logged in as admin → the cell shows a yellow placeholder "Block <code>x.y</code> is unavailable. Edit this page to remove or replace." Guests see no placeholder (the cell is silent).
- [ ] **A** Bad settings JSON (e.g. `{not json`) saved on a placement → the row is stored with `settings IS NULL`; render falls back to the block's `defaultSettings`. No 500.
- [ ] **A** A POST to `/admin/pages/{id}/layout` with `cols=99` or `rows=999` is silently clamped to the schema CHECK bounds (1–4 cols / 1–6 rows) at the service layer; the form validates the same range up front but the controller is the last line of defense.

### Modules + dependencies (`/admin/modules`)

- [ ] **SA** `/admin/modules` renders a roster of every discovered module under `modules/` with state badges (active / disabled — missing deps / disabled by admin) and a "requires" column.
- [ ] **SA** Edit one module's `module.php` to add `public function requires(): array { return ['nonexistent_module']; }` → next request renders that module as `disabled — missing deps` on `/admin/modules` with `nonexistent_module` listed as missing. The module's routes and views stop responding (404 / view-not-found, depending on what's hit).
- [ ] **SA** `module_status` table has a row for the disabled module with `state='disabled_dependency'` and `missing_deps=["nonexistent_module"]`.
- [ ] **SA** A new in-app notification appears in the bell (and an email is delivered when the SA email setting is on) the FIRST time a module transitions to disabled. Refreshing the page does NOT generate a second notification — the dedup check ensures one event per state change.
- [ ] **SA** Remove the `requires()` line → module reactivates on the next request, `/admin/modules` flips back to `active`, the `module_status` row's state is updated, and no extra notification fires.
- [ ] **SA** A module with a transitive missing dep (A requires B, B requires C, C is missing) shows BOTH A and B as disabled. A's "missing" column lists B (the direct dep that disappeared), not C.
- [ ] **SA** `/admin/settings/security` "Email superadmins when a module is auto-disabled" toggle: turn OFF, then trigger another transition → in-app notification fires, but no email is delivered. Turn ON again → next transition includes email.
- [ ] **A** (non-SA admin) visits `/admin/modules` → bounced via `RequireSuperadmin` middleware.
- [ ] Dev BlockRegistry sanity check: in `php artisan tinker` (or a quick dev script), `app(\Core\Module\BlockRegistry::class)->all()` includes `groups.my_groups_tile` and excludes any blocks declared by a currently-disabled module.

---

## Foundation modules

### groups

- [ ] **U** `/groups/create` — create a new group; you become owner.
- [ ] **GO** Invite `qa_user2` via `/groups/{id}/invite` → email/in-app invite sent.
- [ ] **U** Accept invite at `/join/{token}` → user becomes member.
- [ ] **GM** Attempt to invite others → only allowed if member-invites policy is on; else forbidden.
- [ ] **GO** Promote a member to `group_admin` → they can now access admin-only group surfaces.
- [ ] **GA** Attempt to remove the last owner → blocked. Only via the owner-removal request flow.
- [ ] **GO** Initiate owner-removal request on another owner → target sees the pending request, approves/denies; outcome recorded.
- [ ] **GM** Leave a group → membership row gone, own any group content reassigned per policy.
- [ ] **A** Delete a group with members → cascade clears `user_groups`; no FK errors.

### profile

- [ ] **U** Visit `/profile`, update name/bio/avatar → changes persist.
- [ ] **U** Upload an oversize avatar → rejected with clear error.
- [ ] **U** Delete account (if wired) → account flagged or removed per policy.
- [ ] **U** Visit another user's public profile → see only public fields.

### settings

**Dedicated admin pages** — Appearance, Footer, Group Policy, Security & Privacy, Registration & Access.

- [ ] **SA** All five dedicated pages (`/admin/settings/appearance`, `/footer`, `/groups`, `/security`, `/access`) share the same layout: a centered 720px column with a small "← All settings" back link above a bold page heading.
- [ ] **SA** Every boolean setting on a dedicated page renders as the same sliding toggle the header superadmin switch uses — no plain checkboxes anywhere in `/admin/settings/*`.
- [ ] **SA** Changing a setting on a dedicated page reflects immediately on the next page render (e.g. flip `footer_enabled` off → footer disappears; flip `account_sessions_enabled` off → "Active Sessions" link vanishes from the user menu and the URL 404s).
- [ ] **SA** Each dedicated page's save writes an `audit_log` entry (`settings.footer.save`, `settings.groups.save`, `settings.security.save`, `settings.access.save`, `settings.appearance.save`).

**Generic grid (`/admin/settings?scope=site`)** — this is the "ad-hoc / custom keys" surface.

- [ ] **SA** At `scope=site`, managed keys (the ones owned by the dedicated pages: `footer_*`, `single_group_only`, `allow_group_creation`, `account_sessions_enabled`, `new_device_login_email_enabled`, `allow_registration`, `require_email_verify`, `maintenance_mode`, `layout_orientation`, `color_*`) do NOT appear in the grid.
- [ ] **SA** A blue info banner at the top of the site-scope grid explains that keys managed on dedicated pages are hidden.
- [ ] **SA** If only managed keys exist at `scope=site`, the empty-state message reads "No ad-hoc settings yet. Every site-level setting is currently managed on a dedicated page above." — not the generic "No settings yet."
- [ ] **SA** Switch to `scope=page` / `scope=function` / `scope=group` tabs → all keys for those scopes show (no managed-key filter applies outside `site`).
- [ ] **SA** Type-appropriate widgets render per row: boolean → sliding toggle with a green "boolean" badge; integer → `<input type="number">`; text/json → `<textarea>` (JSON in monospace); string → text input.
- [ ] **SA** The type column badge reflects the actual DB `type` column (not a hardcoded "string").
- [ ] **SA** Toggle a boolean off in the grid, press Save All → the DB row's `value` flips and `type` stays `boolean` (regression test for the old bug where Save All silently demoted every row to `type=string`).
- [ ] **SA** Toggle a boolean row off, save, refresh → the toggle renders in the off state (unchecked state is actually posted as `0` via the paired hidden input, not dropped by the browser).
- [ ] **SA** Add a new custom setting via the "Add Setting" card (e.g. `custom_homepage_banner`, type=`string`, value=`Hello`) → row appears in the grid on refresh and is editable.

**Managed-key guardrails** — defense against crafted POSTs.

- [ ] **SA** The delete (×) button does not render for any managed key in the grid (because they don't appear there in the first place).
- [ ] **SA** Craft a delete POST for a managed key (e.g. via curl with a valid CSRF token) against `/admin/settings/delete` with `key=footer_enabled` → request is refused with an error flash "\"footer_enabled\" is managed on a dedicated settings page and can't be deleted here." The row in `settings` remains intact.
- [ ] **SA** Craft a save POST that includes `settings[account_sessions_enabled]=0` and `types[account_sessions_enabled]=boolean` against `/admin/settings` at `scope=site` → the managed key is silently skipped; the DB row is untouched.
- [ ] **SA** Attempt to "Add Setting" with a managed key name (e.g. `new_key=allow_registration`) → the add is silently ignored (no duplicate / shadow row created).

**Permission checks.**

- [ ] **A** (non-SA) visits `/admin/settings` or any dedicated sub-page → redirected to `/admin` with "Superadmin access required." flash (every settings endpoint is superadmin-only).
- [ ] **U** Non-admin visits any `/admin/settings/*` URL → bounced by AuthMiddleware/RequireSuperadmin before the controller runs.

### menus

- [ ] **A** `/admin/menus` — create a menu, add items, reorder, save.
- [ ] **G/U** Visit a page rendering that menu → items appear in the right order.
- [ ] **A** Delete a menu → references on pages gracefully degrade (empty menu, not error).

### notifications

- [ ] **U** Perform an action that generates a notification (e.g. someone replies to your comment) → bell-badge count increments.
- [ ] **U** Open `/notifications` → list shown, marked read on view or via button.
- [ ] **U** Configure per-channel preferences (if surfaced) → respected on next dispatch.
- [ ] **A** Disable a notification type site-wide → no dispatch occurs for that type.

---

## Content modules

### pages

- [ ] **A** `/admin/pages` — create a page with title, slug, body; publish.
- [ ] **G** Visit `/{slug}` → page renders.
- [ ] **A** Unpublish page → 404 for guests; still visible in admin list.
- [ ] **A** Delete page → removed everywhere.

### blog

- [ ] **A** Create a post with title, body, featured image; publish.
- [ ] **G** `/blog` lists posts, post detail renders.
- [ ] **G** Attach a comment via the comments module (if installed) → appears under the post.

### faq

- [ ] **A** Create FAQ entries grouped by category.
- [ ] **G** `/faq` renders with collapsible entries; deep-link to a specific entry works.

### content

- [ ] **A** Create a content item with polymorphic ownership (user or group).
- [ ] **GA** As group admin of the owning group, edit group-owned content → allowed.
- [ ] **U** (not owner) attempts to edit → forbidden.

### knowledge-base

- [ ] **S-knowledgebase** `/admin/kb/create` — new article; save populates a revision.
- [ ] **S-knowledgebase** Edit article → new revision row appears in sidebar.
- [ ] **S-knowledgebase** Click "Publish" on a specific revision → article status flips to published, `current_revision_id` updated.
- [ ] **S-knowledgebase** Click "Restore" on an old revision → new revision created with that body, note "restored from #N". Previous publish still live.
- [ ] **G** Visit `/kb/{slug}` → current published revision renders.
- [ ] **G** `/kb?q=term` → LIKE search returns matches ranked by title > summary > body.
- [ ] **S-knowledgebase** Archive article → 404 for guests, still in admin list.

### hierarchies

- [ ] **S-hierarchies** `/admin/hierarchies/create` — new tree.
- [ ] **S-hierarchies** Add root node, then child node, then grandchild.
- [ ] **S-hierarchies** Delete a mid-level node → descendants cascade-delete.
- [ ] Developer In a view, call `hierarchy_tree('slug')` → nested array returned; `render_hierarchy_nav('slug')` outputs `<ul>`.

### taxonomy

- [ ] **A** Create a vocabulary/set and a few terms.
- [ ] **A** Attach a term to a content item via `attach_term(...)` (or the admin UI if wired for that type).
- [ ] **G/U** Browse by term → items filtered correctly.
- [ ] **A** Delete a term → `taxonomy_entity_terms` rows cascade.

---

## Commerce modules

### store

- [ ] **S-store** Create a physical product with stock tracking + stock=3.
- [ ] **S-store** Create a digital product with `digital_file_path` pointing at a real file under `storage/`.
- [ ] **S-store** Create a shipping zone covering your country with flat rate.
- [ ] **S-store** Create a tax rate for your country at ~8%.
- [ ] **S-store** Add option axes (Color, Size) to a product and create variants; each gets its own price/stock.
- [ ] **S-store** Upload gallery images and a spec sheet to a product.
- [ ] **U** `/shop` — products listed with images + prices; variant products show "from $X".
- [ ] **U** `/shop/product/{slug}` — variant selector appears when axes exist; picking sold-out variant is disabled.
- [ ] **U** Add a physical + a digital product to cart → `/cart` shows both, variant options displayed on physical.
- [ ] **U** Update qty to exceed stock → rejected.
- [ ] **U** `/checkout` — fill shipping address, submit → Stripe Checkout URL returned; pending order row exists in `store_orders`.
- [ ] **U** (Stripe test mode) complete payment → webhook fires → order status = paid, stock decremented, download token issued for digital item.
- [ ] **U** Cancel on Stripe's page → `/checkout/cancel` restores cart, order marked cancelled.
- [ ] **U** `/orders/{id}` — see order detail, "Download" link on digital items.
- [ ] **U** Click download link → file streams correctly. Wait for `expires_at` → link returns 410.
- [ ] **S-store** `/admin/store/orders` — status transitions (paid → shipped → delivered).
- [ ] **U** Same customer places a second order → only one invoice per order remains (`UNIQUE (source, source_id)` on `invoices` if invoicing is wired).

### subscriptions

- [ ] **A** `/admin/subscriptions/plans` — create a plan with a Stripe price_id.
- [ ] **U** `/billing` — see plans, click Subscribe → Stripe Checkout.
- [ ] **U** Complete Stripe checkout in test mode → subscription row in `trial` or `active`, local state reflects Stripe.
- [ ] **U** `/billing` shows subscription details; Cancel at period end works.
- [ ] **A** Force a `invoice.payment_failed` webhook replay → subscription moves to `past_due`.
- [ ] **A** DunningJob runs at 09:00 → past-due users notified (check message log).

### coupons

- [ ] **S-coupons** `/admin/coupons/create` — code `SAVE10`, 10% off cart, usage_limit=100, per_user_limit=1, expires in 7 days.
- [ ] **U** Add items to cart, `/cart` → coupon input visible.
- [ ] **U** Apply `SAVE10` → green discount line appears, total reduced by 10%.
- [ ] **U** Try to apply same coupon a second time (different session) → rejected with "already used".
- [ ] **U** Apply `SAVE10` after `ends_at` → rejected with "expired".
- [ ] **S-coupons** Create a fixed-amount coupon exceeding subtotal → applied discount caps at subtotal (no negative total).
- [ ] **S-coupons** Create a free-shipping coupon → applying zeros shipping line.
- [ ] **S-coupons** Create a products-scope coupon limited to product #3 → only line items matching contribute to the discount.
- [ ] **S-coupons** `/admin/coupons/{id}/redemptions` — log shows each redemption with user + order linkage.

### reviews

- [ ] **U** On a product page with `reviews_for('store_product', id)` dropped in → aggregate bar, form, and list visible.
- [ ] **U** Submit a 4-star review → appears in list; if user has purchased the product, "verified purchase" badge shows.
- [ ] **U** Submit a second review on same product → first is updated (upsert, not duplicate).
- [ ] **U2** Submit a review, mark first user's review as helpful → helpful_count increments.
- [ ] **U2** Toggle helpful off → count decrements (never below 0).
- [ ] **A/M** `/admin/reviews?status=pending` — moderate pending reviews.
- [ ] **Product author (U)** Visit `/admin/reviews` → sees only reviews on their own products; can mark spam/delete.

### invoicing

- [ ] **A** `/admin/invoices/from-order/{order_id}` — generate invoice for a paid order.
- [ ] **U** `/billing/invoices` — user's invoices listed.
- [ ] **U** `/billing/invoices/{number}` — HTML view renders correctly.
- [ ] **U** `/billing/invoices/{number}/pdf` — if dompdf installed, PDF downloads; otherwise HTML view is streamed (graceful fallback).
- [ ] **A** Status transitions (draft → sent → paid → void) work from admin detail page.
- [ ] **A** Generate invoice twice for same order → idempotent (returns same invoice, no duplicate).

---

## Social modules

### social

- [ ] **U** `/feed` — empty at first; compose a public post, it appears.
- [ ] **U2** Visit `/users/{U.username}` → see U's public posts + Follow button.
- [ ] **U2** Follow U → `/feed` now shows U's posts.
- [ ] **U2** Unfollow → posts drop off.
- [ ] **GM** `/feed/groups/{slug}` — visible because a member. Compose a group post; it appears.
- [ ] **U** (non-member) visit same URL → redirected to group page.
- [ ] **U** `/people` — suggestions + user search both functional.
- [ ] **U** Soft-delete own post → disappears from all feeds; still exists in DB with `deleted_at` set.

### comments

- [ ] **U** On a page with `comments('content', id)` → box visible; post a comment → appears in list.
- [ ] **U** Reply to own comment (threaded) → nests correctly up to `comments_max_depth`.
- [ ] **U** Edit own comment within the 15-minute window → works. After window → blocked for non-admins.
- [ ] **A/M** With `comments.moderate` perm → `/admin/comments` shows all pending; set-status and delete work.
- [ ] **GA** With group ownership of the target → `/admin/comments` shows only comments on their group's content.
- [ ] **Target author** (post owner) → `/admin/comments` shows comments on their own content; can moderate via the author path.
- [ ] **SA** Toggle scope-override at `/admin/comments/scopes`: per-group moderation required → new comments on that group's content land pending.
- [ ] **U** React (like/love/laugh/wow/sad) on a comment → count increments; react again same type → removes (idempotent toggle).

### messaging

- [ ] **U** Visit `/users/{U2.username}` → Message button (if profile view has `dm_link_to`).
- [ ] **U** `/messages/with/{U2.username}` → conversation created, thread view.
- [ ] **U** Send a message → appears; U2 sees unread count increment.
- [ ] **U2** Open thread → mark-read fires; U's unread count does NOT change (only recipient's).
- [ ] **U** Soft-delete own message → disappears from thread; U2's view also reflects.
- [ ] **U** Attempt to POST to a conversation you're not part of → 404.
- [ ] **U** Attempt to DM self → rejected.

### activity-feed

- [ ] Developer Call `activity_emit(userId, 'test.event', 'test', 1, summary: 'Test')` from a controller → row appears in `activity_events`.
- [ ] **U** `/activity` (home) — see events where you're actor or member-of-group.
- [ ] **G** `/activity/user/{username}` — only public-visibility events shown.
- [ ] **GM** `/activity/group/{slug}` — group events shown; non-members get redirected.
- [ ] **A** `/admin/activity` — filter by verb/actor/group works.

### events

- [ ] **S-events** Create event with capacity=3, published ✓, starts_at in future.
- [ ] **U, U2, and a third U3** all RSVP yes → all confirmed.
- [ ] **U4** RSVPs yes → waitlisted.
- [ ] **U5** RSVPs with guest_count=2 → waitlisted (needed 3 seats but none available).
- [ ] **U** flips RSVP to `no` → oldest waitlist-fit promotes (U4 if they need 1 seat; U5 needs 3 and wouldn't yet).
- [ ] **S-events** Raise capacity to 6 → save → U5 auto-promoted (waitlist sweep fires).
- [ ] **U** After deadline passes, attempt new yes-RSVP → rejected; `no` (cancellation) still allowed.
- [ ] **G** Download `/events/{slug}.ics` → opens in Calendar app as a valid event.

### polls

- [ ] **S-polls** Create a single-choice poll.
- [ ] **U** Vote once → counted.
- [ ] **U** Re-vote with different choice → previous vote replaced.
- [ ] **U2** Vote → aggregates update.
- [ ] **S-polls** Create a multi-choice poll with `max_choices=2`.
- [ ] **U** Pick 3 options → rejected with too-many-options error.
- [ ] **S-polls** Create a ranked poll with 3 options.
- [ ] **U** Submit duplicate rank → rejected.
- [ ] **U** Submit full ranking → counted; results show Borda totals.
- [ ] **S-polls** Edit poll renaming an option (say "Red" → "Red ") → vote rows preserved because label-match dedup keeps the id.
- [ ] **S-polls** Delete an option entirely → votes for that option cascade-delete.

---

## Utility modules

### forms

- [ ] **A** Create a form with mixed field types (text, email, select, checkbox).
- [ ] **G** Fill + submit → row in form_submissions; CAPTCHA (if enabled) validates.
- [ ] **G** Submit with missing required field → rejected with inline errors.
- [ ] **A** `/admin/forms/{id}/submissions` — view list, export CSV.
- [ ] **A** Configure webhook → a submission triggers a POST to the webhook URL with the payload.

### scheduling

- [ ] **A** Create a resource (name, duration=30, capacity=1, timezone).
- [ ] **A** Add weekly availability windows (Mon 9-12, 13-17).
- [ ] **U** `/book/{slug}?date=YYYY-MM-DD` — see slots; book one.
- [ ] **U** Open second tab, book same slot → second request rejected (transactional check).
- [ ] **U** `/book/my` — see own booking; cancel.
- [ ] **A** `/admin/scheduling/bookings` — set status to completed/no_show.
- [ ] **U** Confirmation email arrives (check mail driver).

### integrations

- [ ] **A** Configure an integration (Slack/Discord/etc.).
- [ ] **A** Send a test message → delivered to target channel.
- [ ] **A** Disable integration → no further dispatches.

### moderation

- [ ] **U** On a post/comment/review, click "Report" → submit → row in `reports`.
- [ ] **U** Attempt to report same item twice → second returns existing open report (idempotent).
- [ ] **S-moderation** `/admin/moderation/reports` — queue shows pending.
- [ ] **S-moderation** Resolve with `warn` action on target user → `user_moderation_state.warnings_count` increments.
- [ ] **S-moderation** Resolve another report with `mute` + days=7 → `muted_until` set.
- [ ] **S-moderation** Resolve a content-report with `remove_content` on a comment → comment status flips to `deleted` (visible change on public view).
- [ ] **Targeted user (U)** `/my/appeals` — sees the actions against them; click Request review.
- [ ] **U** Submit appeal → row in `appeals`, status=pending.
- [ ] **S-moderation** `/admin/moderation/appeals` — approve → action marked reversed; content restored; warning decremented.
- [ ] **Deny** on another appeal → action stays; reviewer note saved.
- [ ] Hourly `ExpireModerationActionsJob` run (`php artisan schedule:run`) → expired mute/suspend rows cleared.

---

## Infrastructure modules

### audit-log-viewer

- [ ] **A** Log in, log out → two rows in `audit_log`: `auth.login`, `auth.logout`.
- [ ] **A** `/admin/audit-log?action=auth.*` — prefix match filters to the two rows.
- [ ] **A** Click into a row → detail view shows old/new JSON side-by-side (only populated for actions that set them).
- [ ] **A** Filter by date range → results bounded.
- [ ] **SA** Toggle superadmin mode → new row with `superadmin=1` visible in viewer.

### api-keys

- [ ] **U** `/account/api-keys` — empty list.
- [ ] **U** Create key "My laptop" with scopes `read:store` → plaintext token shown ONCE; copy it.
- [ ] **U** Reload the page → token is gone; only prefix + last 4 remain.
- [ ] **API** `curl -H "Authorization: Bearer {token}" {app_url}/api/me` → 200 with user_id + scopes.
- [ ] **API** Same curl without the header → 401 `missing_bearer_token`.
- [ ] **API** With a garbage token → 401 `invalid_token`.
- [ ] **U** Revoke the key → subsequent API call returns 401.
- [ ] **U** Create a key with a 1-day expiry → after the date passes, API calls return 401.
- [ ] **U** Create a key with empty scopes list → API calls to endpoints requiring scopes return 403 `missing_scope`; unscoped endpoints (`/api/me`) still work.

### import-export

- [ ] **A** `/admin/import` — upload a user CSV with 5 rows.
- [ ] **A** `/admin/import/{id}` — map columns → Dry run shows row counts without writing.
- [ ] **A** Click Run → rows inserted/updated; stats match the file.
- [ ] **A** Upload CSV with invalid email rows → dry run surfaces per-row errors; Run processes valid ones and records errors in `errors_json`.
- [ ] **A** `/admin/export/users.csv` — download → file opens cleanly in Excel with correct headers.
- [ ] Developer Register a new handler for a custom entity type in a module's `register()` → it appears in the dropdown; upload + import round-trips.

### i18n

- [ ] **A** `/admin/i18n` — add locale `fr` / "French", enabled.
- [ ] **A** Add translation: locale=`en`, key=`cart.checkout_button`, value="Checkout".
- [ ] **A** Add translation: locale=`fr`, key=`cart.checkout_button`, value="Payer".
- [ ] Developer Add `<?= t('cart.checkout_button') ?>` somewhere in a view.
- [ ] **U** Page renders "Checkout" (en is default/session).
- [ ] **U** `locale_switch()` dropdown → pick French → page re-renders with "Payer".
- [ ] Developer Add `<?= t('hello', ['name' => $user['username']]) ?>` with translation "Bonjour {name}" — placeholder substitutes.
- [ ] Developer Reference a missing key → renders the raw key verbatim (so it's visible, not blank).
- [ ] **A** Delete locale `fr` → `fr` translations cascade-drop; users with `fr` in session fall back to `en`.

### feature-flags

- [ ] **A** `/admin/feature-flags/create` — new flag `new_checkout`, enabled ✓, rollout_percent=100.
- [ ] Developer Add `<?= feature('new_checkout') ? 'NEW' : 'OLD' ?>` in a view.
- [ ] **U** Page renders "NEW".
- [ ] **A** Flip global enabled off → page renders "OLD" (cache invalidated).
- [ ] **A** Re-enable; set rollout_percent=0 → all users see "OLD" including guests.
- [ ] **A** Set rollout_percent=50 → roughly half the users see "NEW"; same user sees the same branch on every reload (deterministic hash).
- [ ] **A** Create per-user override (user_id=U.id, enabled=1) while flag is disabled → U sees "NEW", everyone else "OLD".
- [ ] **A** Add a group to the flag; rollout_percent=0 → group members see "NEW", non-members still bound by the 0% rule.
- [ ] **A** Clear override → U reverts to the general rule.
- [ ] **A** Delete flag → override rows cascade; `feature('new_checkout')` returns false thereafter.

---

## Cross-cutting final sweep

Once every module is individually green, walk this list:

- [ ] **A** `error_log` / PHP error log is empty of unexpected warnings/notices across a full browse session.
- [ ] **A** `audit_log` row count is growing with sensible entries; no obvious PII leaks (passwords, card numbers, etc.).
- [ ] **A** `jobs` table has no permanently-stuck rows (all completed or actively retrying).
- [ ] **A** Every scheduled_tasks row has a recent `last_run_at`.
- [ ] **A** `php artisan schedule:run` runs cleanly end-to-end.
- [ ] **A** Webhook replays (for Stripe subscriptions + store) are idempotent — replay same event, no side-effect doubled.
- [ ] **A** Database migrations run cleanly on a *fresh* database (`drop database … ; create database … ; artisan migrate`).
- [ ] **A** `dist/` zips still extract cleanly for each module and install against a fresh DB.
- [ ] **A** CSRF is required on every state-changing POST (try stripping `_token` from a form and submitting).
- [ ] **A** No route listed in `routes.php` under any module returns a 500 under a default-shaped request.
- [ ] **SA** Pull up `/admin/audit-log?action=auth.*` after a representative day's traffic — logins/logouts look reasonable, no unexpected emulation events.
- [ ] Dev `php -l` across every file in `core/` + `modules/` — zero errors.
- [ ] Dev `composer install --no-dev --optimize-autoloader` on a clean checkout → autoloads without warnings.
- [ ] Dev Visit `/shop`, `/feed`, `/events`, `/polls`, `/kb`, `/support`, `/billing`, `/admin` as each role; confirm expected content is visible and unexpected content isn't.
- [ ] **SA** Walk every `/admin/settings/*` dedicated page once more at the end: layout is centered, back link is present, every boolean is a slider (no plain checkboxes anywhere), and the generic grid at `scope=site` shows no managed keys. Covered in detail by the `settings` subsection — this is the last-mile visual sanity check.

---

## Notes on execution

- Going **bottom-up** (core → foundation → consumers) is usually fastest because later modules depend on earlier ones; a bug in auth masquerades as a bug in subscriptions otherwise.
- Keep a **single test database** seeded with the three qa_* accounts so you don't re-set-up between module sessions.
- For commerce + subscriptions, use Stripe's **test mode** end-to-end. Don't rely on production keys in a staging environment.
- For moderation + feature flags, **test as multiple roles** in different browsers (or incognito windows) so session state doesn't leak between tests.
- When something fails, the **audit log viewer** is usually the fastest way to understand what actually happened — most auth/permission-related failures leave audit trails.
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     