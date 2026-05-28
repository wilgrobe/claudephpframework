# QA process — efficiency-ordered

Companion to [`qa-checklist.md`](qa-checklist.md). The checklist is the
*exhaustive* per-module reference; this document is the *fast path* — a single
sit-down, browser-first run through your install that hits every surface in the
order that minimizes re-logins, role switches, and fixture rebuilds.

> **Premium modules ship from a separate repository.** If your install includes
> [claudephpframeworkpremium](https://github.com/) modules, this file covers
> only the open-source core. Run the premium repo's parallel `docs/qa-process.md`
> after you finish this one.

## How to use this

- Work top-to-bottom. Sections are sequenced so each one's setup is satisfied
  by the section before it (e.g. don't moderate a comment before there's a
  comment to moderate).
- One Chrome window with **three browser profiles** open side-by-side:
  - **A** — your admin / superadmin account
  - **B** — a regular user account
  - **C** — a second regular user (for cross-user tests)
  - Profile C doubles as your "guest" by signing out.
- Tick each box as you go. A failed step is fine — note the symptom and move
  on; the goal is full coverage in one pass, not zero defects.
- Browser section first (~2-3 hr). Shell/CLI section at the end is cleanly
  separated and written so a non-developer can run it on their own.

---

## Section 0 — Pre-flight (5 min)

Anything failing here invalidates everything downstream. Stop and fix.

- [ ] Site root (`/`) loads without 500. As guest, redirects to `/login` (or to the configured guest homepage).
- [ ] `/login` renders. Form fields visible.
- [ ] CSS loads — header has site colors, not unstyled white-on-black.
- [ ] No "Whoops" / stack-trace strings anywhere on a fresh page load (`APP_DEBUG=false` in prod, `true` is fine in dev).
- [ ] Open DevTools → Console. Reload `/login`. Zero red errors.
- [ ] DevTools → Application → Cookies. Session cookie present, `HttpOnly` and `SameSite=Lax` flags set.

If any of the above fails, jump to Section S1 (logs) at the end before continuing.

---

## Section 1 — Guest journeys (10 min)

Burn through everything that doesn't need an account first. Profile **C**, signed out.

### Public content surfaces

- [ ] `/` — homepage renders (or redirects to configured guest home).
- [ ] `/login` and `/register` both render. (If registration is disabled, `/register` shows "Registration is closed" — note for Section 5.)
- [ ] `/faq` — collapsible entries; deep-link `?q=<id>` opens that entry.
- [ ] `/sitemap.xml` — valid XML, contains URLs for pages.
- [ ] `/page/{slug}` — any published page renders. Unpublished page → 404.
- [ ] `/search?q=test` — global search returns hits across content/pages/faqs.

### Guest negatives

- [ ] `/dashboard` → redirected to `/login`.
- [ ] `/admin/users` → redirected to `/login`. (No `/admin` index page exists; the admin surface is the union of sub-routes like `/admin/users`, `/admin/settings`, `/admin/modules`, `/admin/audit-log`. Each one independently bounces a guest to login.)
- [ ] `/account/sessions`, `/account/api-keys` → all redirect to `/login` (none leak content).

> Premium modules add public surfaces too — `/shop`, `/feed`, `/events`,
> `/polls`, `/kb`, `/forms/{slug}`. Skip those here; they're covered in the
> premium repo's `docs/qa-process.md`.

---

## Section 2 — Registration, login, account (15 min)

Profile **C** (still signed out, about to register a fresh account).

### First-time signup

- [ ] `/register` — fill the form with a fresh email + strong password → account created. Behavior depends on settings:
  - With `require_email_verify=true` (default-ish): you'll be bounced to `/login` with "check your email"; click the verify link in your inbox / mail driver, then sign in.
  - The post-login landing page can be set in admin settings (e.g. `post_login_redirect_url`); on a fresh install the default is `/dashboard`.
- [ ] Open Profile **B** in another window. Visit `/register`, try to register with the *same* email → validation error, no duplicate created.
- [ ] Try to register with a weak password (e.g. `123`) → rejected with a clear message.

### Login lifecycle

- [ ] Profile **B** — `/login` with **wrong password** → error message, counter increments. Then `/login` with the right password → redirected to `/dashboard`.
- [ ] DevTools → Application → Cookies. Note the session cookie value (call it **V1**). Refresh — V1 persists.
- [ ] Click "Logout" in user menu → redirected to `/login`. Cookie V1 is gone (Set-Cookie cleared with past expiry).
- [ ] Reload `/login`. Cookie reappears with a **new** value (V2). That's expected — PHP issues a fresh guest session immediately.
- [ ] Sign back into Profile **B**.

### CSRF + session hygiene

- [ ] Test CSRF rejection. Three equivalent ways — pick one:

  **(a) Strip the hidden field via Inspector.** On `/profile/edit`, open DevTools → Elements, search for `csrf` to find `<input type="hidden" name="_token" value="...">`, right-click → Delete element, then click Save on the form. Expect 419 / CSRF error page.

  **(b) Copy as cURL.** DevTools → Network → submit the form once, right-click the POST → Copy as cURL. Paste in a terminal, edit the `--data` string to remove the `_token=...&` portion, run. Expect 419.

  **(c) Console fetch.** Paste in DevTools Console while signed in:
  ```js
  fetch('/profile/edit', {
    method: 'POST',
    body: new URLSearchParams({first_name:'X',last_name:'Y'}),
    headers: {'Content-Type':'application/x-www-form-urlencoded'}
  }).then(r => console.log(r.status))
  ```
  Should log 419.

- [ ] Open the same form in two tabs. Wait an hour, submit the older tab → stale token rejected. (Skip the wait in a fast pass — log out in one tab and confirm the other tab redirects to `/login` on next request.)

### 2FA

- [ ] Profile **B** → `/profile/edit` → enable 2FA. QR code visible. Scan in authenticator app, enter 6-digit code → enabled.
- [ ] Log out, log back in → 2FA challenge appears after password.
- [ ] Enter wrong code 5× → rate-limited / locked out for 15 minutes. The lockout window auto-clears on the next successful verify.
- [ ] Enter the correct code → in. Disable 2FA in profile.

### Password reset

- [ ] Log out. `/login` → "Forgot password" → enter Profile B's email → success message.
- [ ] Enter a non-existent email → **same** success message (no email enumeration). Confirmed by inspecting the response, not by waiting for the email.
- [ ] Click the reset link from the inbox (or pull from `message_log` — see Section S2) → set a new password → can log in with it.
- [ ] Click the same reset link again → rejected (used).

### OAuth (only if a provider is configured)

- [ ] `/login` → provider button → completes the round-trip → account linked, session starts.
- [ ] `/profile/edit` → unlink the OAuth provider → can no longer log in via that provider.

### API keys

- [ ] Profile **B** → `/account/api-keys` → empty list.
- [ ] Mint a new key, scopes `read:store` → plaintext token shown **once**. Copy it.
- [ ] Reload — token is masked, only prefix + last 4 visible.
- [ ] Open a terminal:
  ```
  curl -H "Authorization: Bearer {token}" {url}/api/me
  ```
  → 200 with user_id + scopes.

  **Windows / PowerShell note:** PowerShell aliases `curl` to `Invoke-WebRequest`, which uses incompatible argument syntax. Use `curl.exe` (bypasses the alias and runs the real cURL bundled with Windows 10+):
  ```
  curl.exe -H "Authorization: Bearer {token}" {url}/api/me
  ```
  Or PowerShell-native:
  ```
  Invoke-RestMethod -Uri {url}/api/me -Headers @{ Authorization = "Bearer {token}" }
  ```

- [ ] Same curl without header → 401 `missing_bearer_token`.
- [ ] Garbage token → 401 `invalid_token`.
- [ ] Revoke the key in the UI → curl returns 401.
- [ ] Mint a key with empty scopes → `/api/me` works (unscoped); a scoped endpoint returns 403 `missing_scope`.

### Active sessions (user surface)

Skip if `account_sessions_enabled` is off; you'll exercise the toggle in Section 5.

- [ ] Profile **B** + Profile **C** both signed in as the same test user. On B, `/account/sessions` → both rows listed; B is tagged "This device".
- [ ] On B, click "Sign out" on C's row → C's next request lands on `/login`.
- [ ] **Cross-user session-termination bypass test.** As Profile **B**, open `/account/sessions`. Open DevTools → Elements → find the form on the "This device" row. The form's action URL contains B's session id. Edit it to point at a session id belonging to a *different user* (look one up in DB: `SELECT id, user_id FROM sessions WHERE user_id = <other_user_id>`). Submit the form. **Expected:** server silently does nothing OR returns 404; the target session row is unchanged. **Bug if:** the other user gets kicked / their session row is deleted.

---

## Section 3 — Profile + content (10 min)

Profiles **B** and **C** both signed in.

### Profile

- [ ] Profile **B** → `/profile/edit` — change name/bio, upload an avatar → changes persist.
- [ ] Upload an avatar over the size limit → rejected with clear error.
- [ ] Upload a non-image file as avatar → rejected (server-side MIME check; the file picker may still offer the file but the upload fails).

### Theme

- [ ] User menu → theme toggle. Switch dark → page re-renders dark, cookie `theme_pref` set. Switch back. Set "system" → matches OS preference.

### Notifications

- [ ] B's bell badge increments after triggering a notification (e.g. an admin action that targets B). `/notifications` shows the entries.
- [ ] Mark all read → badge clears.
- [ ] Per-channel preferences (if surfaced in profile) → flip one off, repeat the trigger → no notification of that type.

> Premium modules contribute the bulk of user-facing content surfaces (social
> feed, comments, messaging, reviews, etc.). Skip those here.

---

## Section 4 — Admin journeys (30 min)

Profile **A** (superadmin).

### Modules + dependencies

- [ ] `/admin/modules` — every module under `modules/` listed with state badges (active / disabled — missing deps / disabled by admin) and a "requires" column.
- [ ] On a leaf module, click **Disable** → confirm dialog → success flash. Badge flips to "disabled by admin". Routes the module owns now 404.
- [ ] Click **Enable** → module returns to active. Routes work again.
- [ ] On a module that other modules depend on (or fake one — see Section S6 below), disable → cascading dependents flip to "disabled — missing deps" automatically on the next request. Re-enable → all flip back.

### System layouts

- [ ] `/admin/system-layouts` → rows for `dashboard_stats` and `dashboard_main` with rows/cols/gap/max-width/placement-count + Edit button.
- [ ] `/admin/system-layouts/dashboard_main` → editor matches the per-page composer shape. Block dropdown shows every active module's blocks grouped by category.
- [ ] Reorder a placement → save → `/dashboard` reflects the new order.
- [ ] Add a placement → it appears on dashboard.
- [ ] Mark a placement's Remove checkbox + save → dashboard reflects removal.
- [ ] Save a placement with malformed settings JSON → settings stored as NULL, block falls back to its default.
- [ ] Visit `/admin/system-layouts/badname!!` → redirected with "Invalid system layout name" flash.

### Page composer

- [ ] `/admin/pages` → create a new page with title + slug + body → publish.
- [ ] Visit `/{slug}` → renders.
- [ ] On `/admin/pages/{id}/edit`, click **⊞ Layout & blocks** → lands on `/admin/pages/{id}/layout`.
- [ ] Save the layout with no placements → public URL still renders body content normally.
- [ ] Add a placement → pick a block → row=0 col=0 → save → public page renders the block in the top-left cell.
- [ ] Add a second placement of the same block at row=0 col=1 with a different settings JSON (e.g. `{"limit": 3}`) → both honor their own settings.
- [ ] Add two placements in the same cell with different sort_orders → both stack top-to-bottom.
- [ ] Set "Visible to" = Logged-in → block disappears for guests. "Guests only" — opposite.
- [ ] Edit layout to rows=3 cols=3 → public page renders new grid.
- [ ] Click **Remove layout** → confirm → page reverts to body-only.
- [ ] Resize browser < 720px → grid collapses to a single column.
- [ ] Bad settings JSON on a placement → row stored with `settings IS NULL`, no 500.

### Site-building blocks (siteblocks module)

- [ ] On a page layout, drop `siteblocks.html` → save → renders the HTML you typed (sanitized — script tags + `on*` handlers stripped). With "Wrap in card" → renders inside a card container.
- [ ] Drop `siteblocks.markdown` → save → renders Markdown headings, lists, code, links. `javascript:` href in source → neutered to `about:blank`.
- [ ] Drop the hero / image / video / CTA / spacer / search-box blocks → each renders, settings-schema modal exposes the right inputs (textarea / checkbox / image-url) per block.
- [ ] Drop `siteblocks.newsletter_signup` → submit a guest email → row appears in `newsletter_signups`. Submit duplicate → idempotent (no error page).
- [ ] Drop `siteblocks.login` and `siteblocks.register` blocks on a guest page → submitting them routes through the standard auth flow.

### Settings (dedicated pages)

- [ ] `/admin/settings/appearance`, `/footer`, `/security`, `/access` — all share the same centered 720px layout with a back link.
- [ ] Every boolean control on these pages is a sliding toggle (no plain checkboxes anywhere).
- [ ] Flip `footer_enabled` off → footer disappears site-wide.
- [ ] Flip `account_sessions_enabled` off → "Active Sessions" link gone from user menu; `/account/sessions` 404s.
- [ ] `allow_registration` off → `/register` shows "Registration is closed" and a direct POST is also refused.
- [ ] `require_email_verify` on → a freshly-created unverified user can't log in. A superadmin with unverified email can still log in (admin-exempt).
- [ ] On `/admin/users/{id}` for the unverified user, click **Mark verified** (superadmin-only) → confirm → today's date stored, audit row created, any outstanding token gets `used_at` stamped, user can now log in.
- [ ] `maintenance_mode` on → guest browser hits any URL → 503 with maintenance page. Login surface stays open: `/login`, `/logout`, `/auth/2fa/*`, `/auth/oauth/*`.
- [ ] Superadmin browser is exempt from maintenance — can still toggle it back off.
- [ ] Verify the 503 includes `Retry-After: 3600` (DevTools → Network → Response Headers).

### Settings (generic grid)

- [ ] `/admin/settings?scope=site` — managed keys (the ones owned by dedicated pages) do NOT appear. Blue info banner explains why.
- [ ] If only managed keys exist, empty-state copy reads "No ad-hoc settings yet…"
- [ ] Switch to `scope=page` / `function` / `group` — all keys for those scopes show.
- [ ] Type-appropriate widgets render: boolean → slider with green badge, integer → number input, json → monospace textarea, string → text input.
- [ ] Toggle a boolean off → Save All → DB row's `value` flips, `type` stays `boolean`.
- [ ] Toggle off, save, refresh → still off.
- [ ] Add a new custom setting via the Add Setting card → row appears.
- [ ] Try to delete a managed key from the grid → button doesn't render. Force the POST manually → refused with "managed on a dedicated page" flash.

### Roles + permissions

- [ ] `/admin/roles` → create custom role, attach a permission, assign to a user.
- [ ] Sign in as that user → can access the gated surface. Remove the role → can't.
- [ ] Try to modify a system role → policy decides; if blocked, blocked with a clear message.

### Superadmin mode + emulation

- [ ] Toggle SA mode on (header switch) → SA-only views appear. Audit log records `superadmin.mode_toggle`.
- [ ] Start emulating Profile **B** → top banner indicates emulation, UI reflects B's permissions, audit log records it.
- [ ] As emulated B, attempt an irreversibly-destructive action SA-logic blocks → blocked.
- [ ] Stop emulating → back to own identity.
- [ ] Toggle SA mode off → SA-only surfaces revert.

### Active sessions (admin)

- [ ] `/admin/sessions` → every row in `sessions` listed, joined to users, newest first. Guest rows show "(no user)".
- [ ] Sidebar "Top users by session count" shows sensible numbers.
- [ ] `?user_id={B's id}` → list narrows.
- [ ] Click **Terminate** on B's session → row deleted, B kicked on next request, audit_log gets `session.terminate`.
- [ ] Click **Sign out all devices** on B → every B row deleted in one transaction, audit row `session.terminate_all` with `terminated_count`.

### Audit log viewer

- [ ] `/admin/audit-log?action=auth.*` → recent logins/logouts visible.
- [ ] Click into a row → detail view shows old/new JSON side-by-side.
- [ ] Filter by date range → results bounded.
- [ ] Toggle SA mode again → new row with `superadmin=1`.

### Menus

- [ ] `/admin/menus` → create a new menu, add items, reorder, save.
- [ ] On a public page rendering that menu → items appear in the new order.
- [ ] Delete the menu → references gracefully degrade (empty menu, not error).

### Taxonomy + hierarchies

- [ ] `/admin/taxonomy/sets/create` → create a vocabulary, add a few terms.
- [ ] Attach a term to a content item via admin UI → public browse-by-term filter narrows correctly.
- [ ] Delete a term → `taxonomy_entity_terms` cascades.
- [ ] `/admin/hierarchies/create` → create a tree, add root → child → grandchild. Delete a mid node → descendants cascade-delete.

### Import + export

- [ ] `/admin/import` → upload a small user CSV.
- [ ] `/admin/import/{id}` → map columns → **Dry run** → row counts shown, no writes.
- [ ] **Run** → rows inserted/updated; stats match.
- [ ] Upload a CSV with invalid email rows → dry run flags them per-row; Run processes valid ones, errors recorded in `errors_json`.
- [ ] `/admin/export/users.csv` → file downloads, opens cleanly.

### Feature flags

- [ ] `/admin/feature-flags/create` → flag, enabled, rollout=100.
- [ ] On any page using `feature('flag_key')`, render shows the new branch.
- [ ] Flip global enabled off → renders the old branch.
- [ ] Set rollout=50 → roughly half of users get NEW; same user sees the same branch on every reload.
- [ ] Per-user override → only that user sees NEW.
- [ ] Add a group override → group members see NEW.
- [ ] Delete the flag → override rows cascade.

### Integrations

- [ ] `/admin/integrations` → configure (e.g.) Slack/Discord.
- [ ] Send a test message → delivered to channel.
- [ ] Disable integration → no further dispatches.

### New-device login email

- [ ] In `/admin/settings/security`, "Email users when they sign in from an unrecognized device" is a slider.
- [ ] With it ON: have B sign in from a fresh browser (different User-Agent), with at least one prior session row → email dispatched (verify in mail log).
- [ ] First-ever login (no prior `last_login_at`) → no email (suppress welcome-spam).
- [ ] Same UA on a known device → no email.
- [ ] After a successful send, `audit_log` has `auth.new_device_email_sent`.

---

## Section 5 — Compliance & security modules (45 min)

Every item below requires the corresponding module to be installed + migrated. They're sequenced so settings configured early are exercised later. Profile **A** drives admin configuration; Profiles **B** + **C** exercise user-facing flows.

### Cookie consent (`cookieconsent`)

- [ ] **G** Visit `/` in a fresh browser profile → bottom-banner appears with **Accept all** / **Customize** / **Reject all** all equally prominent. CSS uses theme tokens (matches dark mode if active).
- [ ] **G** Click **Customize** → modal opens with 4 categories (necessary always-locked-on, preferences, analytics, marketing) + per-category toggles + descriptions.
- [ ] **G** Click **Reject all** → banner dismisses, `cookie_consent` cookie set; row in `cookie_consents` with `action='reject_all'`, IP packed as VARBINARY, truncated UA.
- [ ] **G** Click **Accept all** → same flow with `action='accept_all'`.
- [ ] **U** While signed in, accept → row has `user_id` populated; `audit_log` entry `cookieconsent.save`.
- [ ] **SA** `/admin/cookie-consent` → 30-day stats strip + recent 50 events with action badges. Click "Bump version" → `policy_version` increments, every browser sees the banner again.
- [ ] **A** Drop `cookieconsent.reopen_link` block onto a page → renders a link that wipes the consent cookie + reloads.
- [ ] **Developer** `consent_allowed('analytics')` returns false until accepted, true after accept. `consent_allowed('necessary')` is always true.

### GDPR / DSAR (`gdpr`)

- [ ] **U** Visit `/account/data` → dashboard with three cards: export, restrict, delete. Empty state: "No exports yet."
- [ ] **U** Click **Build export** → row in `data_exports` with `status=ready`, `download_token`, file under `storage/gdpr/exports/`. Inline "Download" link.
- [ ] **U** Open the downloaded zip → README.txt + account.json (no password / 2FA secret leaked) + per-module folders.
- [ ] **U** Build a second export within the rate window → blocked with "you already have a recent export."
- [ ] **U** Click **Delete my account** → must type "delete my account" verbatim. Confirm → `users.deletion_grace_until` set 30 days out; `deletion_token` populated; DSAR row created.
- [ ] **U** Visit `/account/data` again → red "deletion scheduled" card with "Cancel deletion now" link.
- [ ] **U** Click cancel → grace columns NULLed; back to normal.
- [ ] **U** Click **Apply restriction** → `users.processing_restricted_at` stamped; audit row.
- [ ] **A** `/admin/gdpr` → DSAR queue with stats strip + pending erasures table.
- [ ] **A** `/admin/gdpr/dsar/{id}` for an erasure DSAR → typing "erase" and submitting fires `DataPurger`; user erased in one transaction; `audit_log` row `gdpr.user_erased` with stats.
- [ ] **A** `/admin/gdpr/handlers` → registry inspection. Each legal-hold table shows its `legal_hold_reason` text.
- [ ] After erasure, spot-check that affected modules' rows now show `[erased user #N]` where they were the user, OR are gone entirely if the handler said `erase`.

### Policy versioning (`policies`)

- [ ] **A** Create a CMS page at `/page/terms` with body content.
- [ ] **A** `/admin/policies/1` → assign source page = the page you just created. Click **Bump version 1.0** → snapshot stored.
- [ ] **U** On the next page request, blocking modal appears with "Please review our updated terms" + checkbox per required policy. Cannot dismiss without accepting.
- [ ] **U** Try to navigate to `/dashboard` → redirected back to `/policies/accept`. Logout link still works.
- [ ] **U** Check + submit → redirected to wherever you originally tried to go; `policy_acceptances` row written with IP + UA; audit log entry.
- [ ] **G** Visit `/register` → checkboxes appear for every required policy with version label; submitting without checking → rejected.
- [ ] **U** `/account/policies` → history of every accept; pending banner if anything's been bumped you haven't accepted.
- [ ] **A** Edit the source page → bump version 2.0 → users see the modal again.
- [ ] **SA** `/admin/policies/{id}/v/{vid}` → snapshot body + acceptance ratio + most recent 100 acceptances.

### Data retention (`retention`)

- [ ] **A** `/admin/retention` → "Re-sync from modules" — pulls all module-declared rules into the table. Initial sync should land ~10-15 rules.
- [ ] **A** Per-rule detail page → adjust days-keep, action, enabled → save → row updates with `source='admin_custom'`.
- [ ] **A** Click **Preview** on a rule → "N rows would be affected" flash without writing.
- [ ] **A** Click **Run now** → rows actually purged/anonymized; `last_run_*` columns updated; new `retention_runs` row.
- [ ] **A** `/admin/retention` index — recent runs table populated.

### Email compliance (`email`)

- [ ] **U** Trigger a marketing-category email. Inbox shows the email with a footer "Unsubscribe" link + "Manage preferences" link. Source: `List-Unsubscribe` + `List-Unsubscribe-Post` headers present.
- [ ] **G** Click the footer Unsubscribe link → `/unsubscribe/{token}` lands → click "Confirm" → row in `mail_suppressions` with `reason='user_unsubscribe'`.
- [ ] **U** Trigger another marketing-category email after the unsubscribe → not sent; row in `mail_suppression_blocks`.
- [ ] **U** `/account/email-preferences` → toggle a non-transactional category off + save → suppression added; toggle back on → removed. Transactional categories render disabled-checked with "always on" badge.
- [ ] **A** `/admin/email-suppressions` → list with stats strip + manual add form + search by email.
- [ ] **A** `/admin/email-suppressions/blocks` → log of skipped sends.
- [ ] **A** `/admin/email-suppressions/bounces` → webhook event log.

### Audit chain (`auditchain`)

- [ ] **A** `/admin/audit-chain` → health overview with stats. After audit-generating actions, the chain has rows.
- [ ] **A** Click **Verify range** with default dates → run completes cleanly (0 breaks); `retention_chain_runs` row created.
- [ ] **A** Manually edit an `audit_log` row's `notes` column via DB tool. Re-run verify → break detected, `audit_chain_breaks` row with `reason='hash_mismatch'`. Superadmins get an in-app notification + email.
- [ ] **A** `/admin/audit-chain/breaks` → break listed in red. Type a note + click **Ack** → row marked acknowledged.

### Security: HIBP password breach (`security`)

- [ ] **G** Try to register with a famously-breached password like `Password123!` → rejected.
- [ ] **G** Try a strong unique password → succeeds.
- [ ] **U** Try password reset with a breached password → same rejection.
- [ ] **A** `/admin/users/{id}/edit` → set password to a breached one → same rejection.
- [ ] **SA** `/admin/settings/security` → toggle off **Block on confirmed breach** → behavior switches to warn-only.
- [ ] **SA** Toggle off **Check passwords against HIBP corpus** → no check at all.
- [ ] DB → `password_breach_cache` populated; rows have `expires_at` set 24h out.

### Security: sliding session timeout

- [ ] **SA** `/admin/settings/security` → set "Session inactivity timeout" to 1 minute, save.
- [ ] **U** Sign in, wait 90 seconds, then click any link → redirected to `/login` with "after 1 minute of inactivity" flash; audit row `auth.session_idle_timeout`.
- [ ] **U** Sign in, do an action every 30 seconds for 3 minutes → session stays alive (sliding window resets on each request).
- [ ] **SA** Set the timeout back to 0 → behavior reverts.

### Security: admin IP allowlist

- [ ] **SA** `/admin/settings/security` → enter your current IP in the allowlist textarea. Toggle on, save → save succeeds.
- [ ] **SA** Try to save with the toggle on but a CIDR list that doesn't include your IP → save **refused** with anti-lockout error.
- [ ] **U** From a different network → visit `/admin` → 403 page with your IP shown.
- [ ] **SA** Toggle off → admin access restored.

### Security: PII access logging

- [ ] **SA** `/admin/settings/security` → toggle on "Log admin reads of personal data" (default on).
- [ ] **A** Visit `/admin/users/1` → audit_log row `pii.viewed` with your actor_user_id, path in new_values.
- [ ] **A** Refresh the same page within 30 seconds → no duplicate audit row (in-process throttle).
- [ ] **A** Visit `/admin/sessions` and `/admin/audit-log` → each generates its own pii.viewed row (different paths).

### Accessibility (`accessibility`)

- [ ] **G** First Tab key on any page → "Skip to main content" link becomes visible at top-left. Pressing Enter jumps focus past the header.
- [ ] **G** Tab through any page → focus indicators visible on every interactive element.
- [ ] **A** `/admin/a11y` → lint scan runs sub-second. Stats strip + by-rule table + per-file findings list.
- [ ] **A** Click **Re-scan** → button fires; audit row written.
- [ ] **Developer terminal:** `php artisan a11y:lint` — same scan, CLI output. `--errors-only` exits non-zero on any error finding (CI gate). `--json` machine-readable.

### CCPA "Do Not Sell" (`ccpa`)

- [ ] **G** Site footer shows "Do Not Sell or Share My Personal Information" link (when `ccpa_enabled` is on, default).
- [ ] **G** Click it → `/do-not-sell` form. Without an account, must provide email. Submit → cookie + `ccpa_opt_outs` row created.
- [ ] **U** While signed in, visit `/do-not-sell` → form pre-fills with account email; submit → row tied to user_id; audit row `ccpa.opt_out_recorded`.
- [ ] **U** Send a request with `Sec-GPC: 1` header. Visit `/do-not-sell` → green "Global Privacy Control detected" banner.
- [ ] **Developer** `ccpa_opted_out()` returns true after the user opts out OR when the GPC header is present.
- [ ] **A** `/admin/ccpa` → stats by source + recent 100 opt-outs.

### Login anomaly detection (`loginanomaly`)

- [ ] **SA** `/admin/settings/security` → toggle on "Detect impossible-travel sign-ins". Save.
- [ ] **U** Sign in once from your normal browser. Wait a few minutes, then sign in from a VPN exit point in another country → email arrives with detected vs prior locations + km/h.
- [ ] **A** `/admin/security/anomalies` → row appears with severity badge + rule + country/city pair.
- [ ] **A** Click **Ack** on the row → marked acknowledged.
- [ ] DB → `login_geo_cache` populated for both IPs.
- [ ] **SA** `/admin/security/anomalies` after a quiet day → "0 anomalies" green banner.

### COPPA age gate (`coppa`)

- [ ] **SA** `/admin/settings/access` → toggle on "Require date-of-birth + age gate at registration". Set minimum age (default 13). Save.
- [ ] **G** `/register` → date-of-birth field appears with helper text.
- [ ] **G** Submit with a DOB making you under the threshold → rejected; `audit_log` row `coppa.registration_blocked` with IP, UA, minimum age, and a SHA-256 prefix of the email (no DOB stored).
- [ ] **G** Submit with a DOB making you over the threshold → registration succeeds.
- [ ] **G** Submit without a DOB → "Please provide your date of birth."
- [ ] **A** `/admin/coppa` → recent rejections table with IP + email hash + min-age-at-time.
- [ ] **SA** Toggle off → DOB field disappears from registration form.

---

## Section 6 — Cross-cutting browser sweep (10 min)

Quick final pass with Profile **A** as superadmin.

- [ ] Hit `/dashboard`, `/admin` in sequence — every page renders without 500.
- [ ] Resize the browser to 360px wide on `/dashboard` and on a page-composer page — both collapse cleanly to single column.
- [ ] DevTools → Network → reload `/admin/modules`. Check for any 404s on assets (CSS, JS, images, fonts).
- [ ] Sign out, hit each `/admin/*` URL from your history → all redirect to `/login`.
- [ ] Sign in as Profile **B** (not admin), hit each `/admin/*` URL → all redirect with "Superadmin access required" or to login.

---

## Section 7 — Shell, CLI, and infra checks (for non-developers)

These checks need a terminal. They're written so you can run them without knowing PHP. **Open a terminal in the project root** (the folder containing `artisan`, `composer.json`, and `bin/`).

### S0 — Where am I?

```
pwd
```
Should print the path to the framework. If not, `cd` into that folder.

### S1 — Logs (run if anything looked wrong in the browser)

```
tail -50 storage/logs/php_error.log
```
Look for lines containing `Fatal`, `Warning`, `Notice`, or a date/time near the failure.

### S2 — Did emails actually send?

The framework supports three mail drivers, selected via `MAIL_DRIVER` in `.env`.

**A. The `message_log` DB table — works for ALL drivers.** Authoritative. Run:
```sql
SELECT id, recipient, subject, status, error, attempts, created_at
FROM message_log
WHERE channel = 'email'
ORDER BY id DESC
LIMIT 20;
```
The full message body is in the `body` column.

**B. `MAIL_DRIVER=log` — append to a flat file (easiest dev setup):**
```
tail -100 storage/logs/mail.log
```
Each entry shows From / To / Subject / TEXT / HTML clearly delimited.

**C. `MAIL_DRIVER=smtp` against smtp4dev** (a local fake mail server): open `http://localhost:5000` in a browser to see the inbox.

**D. `MAIL_DRIVER=smtp` against a real provider:** check the provider's delivery dashboard.

**Verify** that every QA action that should have sent email (password reset, new-device login, etc.) has a corresponding entry from the same minute.

### S3 — Are background jobs running?

```
php artisan queue:list
```
Healthy state:
- **completed** — fine.
- **pending** — fine if recent, bad if older than ~5 minutes.
- **failed** — investigate. Each has a `last_error` column.

Retry failures (one shot):
```
php artisan queue:retry
```

Run the worker manually for ~30 seconds (if no daemon is set up):
```
php artisan queue:work --once
```

### S4 — Are scheduled tasks running?

```
php artisan schedule:run
```
The master cycle. Picks up every due scheduled task and runs them. Should exit 0 and print one line per task.

To list registered tasks and when they last fired:
```
php artisan schedule:run --list
```

### S5 — Module dependency cascade test

This is the only QA step that requires editing source. Skip if you're not comfortable.

Open any module's `module.php`. Find the `requires()` method (or add one if missing). Temporarily change it to:
```
public function requires(): array { return ['some_other_module']; }
```
Save, reload `/admin/modules`. Now disable `some_other_module` from that page. On the next request, your edited module should flip to "disabled — missing deps". Re-enable → it flips back. **Revert your edit** when done.

### S6 — Lint every PHP file

```
bin/php -l core/*.php
find core -name '*.php' -exec bin/php -l {} \;
find modules -name '*.php' -exec bin/php -l {} \;
```
Every line should end with `No syntax errors detected`.

The `bin/php` wrapper exists so you don't need PHP installed on your machine; the framework ships its own. If `bin/php` is missing, run:
```
bash bin/setup-php.sh
```

### S7 — Composer + autoload sanity

```
composer install --no-dev --optimize-autoloader
```
Should finish with `Generating optimized autoload files` and zero warnings.

### S8 — Database migrations on a fresh DB

Only do this if you have a throwaway database. **Never run on production.**

```
php artisan migrate:status
```
Shows every migration and whether it's been applied.

End-to-end test on a clean DB:
```
mysql -u <user> -p -e "DROP DATABASE qa_fresh; CREATE DATABASE qa_fresh;"
DB_DATABASE=qa_fresh php artisan migrate
```
Should complete without errors. Spot-check a few tables exist with `SHOW TABLES;`.

### S9 — Audit log spot-check

```sql
SELECT action, COUNT(*) FROM audit_log
  WHERE created_at > NOW() - INTERVAL 1 HOUR
  GROUP BY action ORDER BY 2 DESC;
```
You should see rows for `auth.login`, `auth.logout`, `settings.*.save`, `module.*`, `superadmin.mode_toggle`, etc. — anything you exercised.

Sanity-check that no row contains a password or other secret:
```sql
SELECT id, action, new_values FROM audit_log
  WHERE new_values LIKE '%password%'
     OR new_values LIKE '%card%'
     OR new_values LIKE '%token%'
  LIMIT 20;
```
Hits should be flag names (e.g. `password_changed_at`, `csrf_token`), **not actual values**.

### S10 — Sessions table sanity

```sql
SELECT COUNT(*) AS total,
       SUM(user_id IS NULL) AS guest,
       SUM(user_id IS NOT NULL) AS authed
FROM sessions;
```

Force-sign-out a user (the emergency-kick primitive):
```sql
DELETE FROM sessions WHERE user_id = <user_id>;
```
That user's next request lands on `/login`.

### S11 — Stuck jobs

```sql
SELECT id, queue, attempts, last_error, created_at
FROM jobs
WHERE attempts >= 5 OR (status = 'pending' AND created_at < NOW() - INTERVAL 1 HOUR);
```
Should return zero rows after a healthy QA pass.

### S12 — Compliance / security CLI

The compliance modules ship CLI surfaces. Run from the project root.

**Accessibility lint (CI gate):**
```
php artisan a11y:lint                 # human-readable
php artisan a11y:lint --errors-only   # exits non-zero on any error finding
php artisan a11y:lint --json          # machine-readable for CI integration
```

**Audit-chain verification + retention sweep:**
```
php artisan schedule:run              # runs the daily audit-chain verify and retention sweep
```

**GDPR purge of past-grace-window users:**
```
php artisan schedule:run              # PurgeUserJob runs hourly via schedule
```

### S13 — Migration sanity (compliance modules)

If you've added compliance modules between deploys, verify migrations applied:
```
php artisan migrate:status | grep -E "cookie_|gdpr|policies|retention|email|auditchain|security|accessibility|ccpa|loginanomaly|coppa|password_breach"
```
Every line should end in "Ran". If any show "Pending", run `php artisan migrate`.

### S14 — Compliance audit-log spot-checks

After a representative day's traffic:
```sql
SELECT action, COUNT(*) FROM audit_log
  WHERE created_at > NOW() - INTERVAL 1 DAY
    AND (action LIKE 'gdpr.%' OR action LIKE 'cookieconsent.%'
      OR action LIKE 'policies.%' OR action LIKE 'retention.%'
      OR action LIKE 'email.%' OR action LIKE 'auditchain.%'
      OR action LIKE 'ccpa.%' OR action LIKE 'loginanomaly.%'
      OR action LIKE 'coppa.%' OR action LIKE 'pii.%'
      OR action LIKE 'security.%')
  GROUP BY action ORDER BY 2 DESC;
```
You should see a healthy mix of write events.

```sql
-- Verify the audit chain columns are populating on writes
SELECT id, action, prev_hash IS NULL AS chain_missing
FROM audit_log
WHERE created_at > NOW() - INTERVAL 1 HOUR
  AND prev_hash IS NULL
LIMIT 20;
```
Should return zero rows if `auditchain` is installed + migrated.

### S15 — Final summary

If you finished the browser pass and the shell pass, paste this block back to your developer with notes:

```
QA pass completed: <date>
Browser sections: 0 1 2 3 4 5 6
Shell sections: S1 S2 S3 S4 S6 S7 S9 S10 S11 S12 S13 S14
Failures observed:
  - <section>.<step>: <one-line symptom>
  - ...
Logs at tail of php_error.log: <paste last 20 lines>
```

---

## Notes on execution

The browser section is sequenced so each step's setup is satisfied by the step before it. Don't skip ahead to a moderation step without first generating the comment/review/report it moderates.

Use **three browser profiles**, not three incognito windows. Cookies survive between tasks that way.

When something fails, the **audit log viewer** (`/admin/audit-log`) is usually the fastest way to understand what actually happened. Most auth/permission failures leave audit trails before you reach the shell.

The exhaustive per-module reference lives in [`qa-checklist.md`](qa-checklist.md). If a step in this process surprises you, open the matching subsection there for the deeper "what should the row look like in the DB" detail.

If your install includes premium modules, run the parallel checklist in the
`claudephpframeworkpremium` repo's `docs/qa-process.md` after this one is green.
