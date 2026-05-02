# QA process — efficiency-ordered

Companion to `QA_CHECKLIST.md`. The checklist is the *exhaustive* per-module
reference; this document is the *fast path* — a single sit-down, browser-first
run through the site that hits every surface in the order that minimises
re-logins, role switches, and fixture rebuilds.

## How to use this

- Work top-to-bottom. Sections are sequenced so each one's setup is satisfied
  by the section before it (e.g. don't moderate comments before there are
  comments to moderate).
- One Chrome window with **three profiles** open side-by-side:
  - **A**: `qa_admin@local` — superadmin
  - **B**: `qa_user@local` — plain user
  - **C**: `qa_user2@local` — second plain user (for cross-user tests)
  - Profile C doubles as your "guest" by signing out.
- Tick each box (`[ ]` → `[x]`) as you go. A failed step is fine — note the
  symptom in a margin and move on; the goal is full coverage in one pass, not
  zero defects.
- Browser section first (~2-3 hr). Shell/CLI section at the end is cleanly
  separated and written so a non-developer can run it on their own.

---

## Section 0 — Pre-flight (5 min)

Anything failing here invalidates everything downstream. Stop and fix.

- [x ] Site root (`/`) loads without 500. As guest, redirects to `/login` (or
      to the configured guest homepage).
- [x ] `/login` renders. Form fields visible.
- [x ] CSS loads — header has site colors, not unstyled white-on-black.
- [x ] No "Whoops" / stack-trace strings anywhere on a fresh page load
      (`APP_DEBUG=false` in prod, `true` is fine in dev).
- [x ] Open DevTools → Console. Reload `/login`. Zero red errors.
- [x ] DevTools → Application → Cookies. `cphpfw_session` cookie present,
      `HttpOnly` and `SameSite=Lax` flags set.

If any of the above fails, check the Section S1 (logs, config) shell steps at
the end before continuing.

---

## Section 1 — Guest journeys (10 min)

Burn through everything that doesn't need an account first. Profile **C**,
signed out.

### Public content surfaces

- [x ] `/` — homepage renders (or redirects to configured guest home).
- [x ] `/login` and `/register` both render. (If registration is disabled,
      `/register` shows "Registration is closed" — note for Section 5.)
- [x ] `/faq` — collapsible entries, deep-link `?q=<id>` opens that entry.
- [x ] `/kb` — knowledge base index. Click an article — published revision
      renders.
- [x ] `/kb?q=test` — LIKE search returns results, ranked title > summary > body.
- [x ] `/shop` — product list with images + prices. Variant products show
      "from $X".
- [x ] `/shop/product/{slug}` for one of each: simple, variant, digital.
      Variant selector hides sold-out options.
- [x ] `/events` — upcoming event list. Past events excluded.
- [x ] `/events/{slug}` — detail page, RSVP CTA visible (gated until login).
- [x ] `/events/{slug}.ics` — downloads, opens in a calendar app as a
      valid event. **Note:** if this 404s, check that
      `modules/events/routes.php` declares the `.ics` route BEFORE
      the `/events/{slug}` route — otherwise the latter matches
      `slug.ics` and 404s on the literal slug. (Fixed in the framework
      as of 2026-05-01; older deployments may still have the bug.)
- [x ] `/polls` — list of published polls. Click into one — choices visible,
      vote button gated.
- [x ] `/sitemap.xml` — valid XML, contains URLs for pages/blog/kb.
- [x ] `/page/{slug}` — any published page renders. Unpublished page → 404.
- [x ] `/users/{username}` — public profile page renders for a real
      user; shows only public fields. **Note:** `users.username` is
      nullable and the default registration form does NOT collect
      one, so most installs have no users with usernames and every
      `{username}` value will 404. To exercise this step, either:
      (1) set a username manually via DB tool
      (`UPDATE users SET username = 'will' WHERE id = N`), or
      (2) add a username field to your registration form.
- [x ] `/activity/user/{username}` — same caveat as above. Pick a user
      that has a username set + visit using that.
- [x ] `/forms/{slug}` — a public form renders. Submit with missing required
      field → inline errors. Submit valid → success page.
- [x ] `/search?q=test` — global search box returns hits across content/pages/
      faqs.

### Guest negatives

- [x ] `/dashboard` → redirected to `/login`.
- [x ] `/admin/users` → redirected to `/login` (no `/admin` index page
      exists in the framework — the admin surface is the union of
      sub-routes like `/admin/users`, `/admin/settings`, `/admin/modules`,
      `/admin/audit-log`. Each one independently bounces a guest to login.)
- [x ] `/feed`, `/messages`, `/orders`, `/billing`, `/account/sessions`,
      `/account/api-keys` → all redirect to `/login` (none leak content).

---

## Section 2 — Registration, login, account (15 min)

Profile **C** (still signed out → about to register a fresh account).

### First-time signup

- [x ] `/register` — fill the form with `qa_user2@local` + strong
      password → account created. **Behavior depends on settings:**
      - With `require_email_verify=true` (default-ish): you'll be
        bounced to `/login` with "check your email"; click the
        verify link in your inbox / smtp4dev, then sign in.
      - The post-login landing page can be set in admin settings
        (e.g. `post_login_redirect_url`); on a fresh install the
        default is `/dashboard`. If yours redirects to API keys
        or another page, that's the configured behavior.
- [x ] Open Profile **B** in another window. Visit `/register`, try to register
      with the *same* email → validation error, no duplicate created.
- [x ] Try to register with a weak password (e.g. `123`) → rejected with a
      clear message.

### Login lifecycle

- [x ] Profile **B** — `/login` with **wrong password** → error message,
      counter increments. Then `/login` with the right password → redirected
      to `/dashboard`.
- [x ] DevTools → Application → Cookies. Note the `cphpfw_session` value
      (call it **V1**). Refresh — `V1` persists.
- [x ] Click "Logout" in user menu → redirected to `/login`. Cookie `V1` is
      gone (Set-Cookie cleared with past expiry).
- [x ] Reload `/login`. Cookie reappears with a **new** value (V2). That's
      expected — PHP issues a fresh guest session immediately.
- [x ] Sign back into Profile **B**.

### CSRF + session hygiene

- [x ] Test CSRF rejection. Three equivalent ways — pick the one you find
      easiest:
      **(a) Strip the hidden field via Inspector.** On `/profile/edit`,
      open DevTools → Elements, search for `csrf` to find
      `<input type="hidden" name="_token" value="...">`, right-click → Delete
      element, then click Save on the form. Expect 419 / CSRF error page.
      **(b) Copy as cURL.** DevTools → Network → submit the form once,
      right-click the POST → Copy as cURL. Paste in a terminal, edit the
      `--data` string to remove the `_token=...&` portion, run. Expect 419.
      **(c) Console fetch.** Paste this in DevTools Console while signed in:
      `fetch('/profile/edit',{method:'POST',body:new URLSearchParams({first_name:'X',last_name:'Y'}),headers:{'Content-Type':'application/x-www-form-urlencoded'}}).then(r=>console.log(r.status))`
      → should log 419.
- [x ] Open the same form in two tabs. Wait an hour, submit the older tab →
      stale token rejected. (Skip the wait in a fast pass — just log out in
      one tab and confirm the other tab redirects to `/login` on next request.)

### 2FA

- [x ] Profile **B** → `/profile/edit` → enable 2FA. QR code visible. Scan in
      authenticator app, enter 6-digit code → enabled.
- [x ] Log out, log back in → 2FA challenge appears after password.
- [x ] Enter wrong code 5× → rate-limited / locked out for 15 minutes.
      **Note:** TOTP rate limiting was added 2026-05-01 via the security
      module's `2026_05_01_300000_add_totp_failed_attempt_tracking`
      migration. If your install pre-dates it, run `php artisan migrate`
      first. The lockout window auto-clears on the next successful
      verify (so a user with a flaky time source isn't punished for
      a few mistypes).
- [x ] Enter the correct code → in. Disable 2FA in profile.

### Password reset

- [x ] Log out. `/login` → "Forgot password" → enter `qa_user@local` →
      success message.
- [x ] Enter a non-existent email → **same** success message (no email
      enumeration). Confirmed by inspecting the response, not by waiting for
      the email.
- [x ] Click the reset link from the inbox (or pull from `email_log` /
      smtp4dev — see Section S2) → set a new password → can log in with it.
- [x ] Click the same reset link again → rejected (used).

### OAuth (only if a provider is configured)

Did Not Test (DNT) - [ ] `/login` → Google/GitHub button → completes the round-trip → account
      linked, session starts.
Did Not Test (DNT) - [ ] `/profile/edit` → unlink the OAuth provider → can no longer log in via
      that provider.

### API keys

- [x ] Profile **B** → `/account/api-keys` → empty list.
- [x ] Mint a new key, scopes `read:store` → plaintext token shown **once**.
      Copy it.
- [x ] Reload — token is masked, only prefix + last 4 visible.
- [x ] Open a terminal: `curl -H "Authorization: Bearer {token}" {url}/api/me`
      → 200 with user_id + scopes.
      **Windows / PowerShell note:** PowerShell aliases `curl` to
      `Invoke-WebRequest`, which uses incompatible argument syntax. Use
      `curl.exe` (the `.exe` suffix bypasses the alias and runs the real
      cURL bundled with Windows 10+):
      `curl.exe -H "Authorization: Bearer {token}" {url}/api/me`
      Or use real PowerShell syntax:
      `Invoke-RestMethod -Uri {url}/api/me -Headers @{ Authorization = "Bearer {token}" }`
      Or run the original command from `cmd.exe` instead of PowerShell.
- [x ] Same curl without header → 401 `missing_bearer_token`.
- [x ] Garbage token → 401 `invalid_token`.
- [x ] Revoke key in the UI → curl returns 401.
- [x ] Mint a key with empty scopes → `/api/me` works (unscoped); a scoped
      endpoint returns 403 `missing_scope`.

### Active sessions (user surface)

Skip if `account_sessions_enabled` is off; you'll exercise the toggle in
Section 5.

- [x ] Profile **B** + Profile **C** both signed in as `qa_user`. On B,
      `/account/sessions` → both rows listed; B is tagged "This device".
- [x ] On B, click "Sign out" on C's row → C's next request lands on `/login`.
- [x ] **Cross-user session-termination bypass test.** As Profile **B**,
      open `/account/sessions`. Open DevTools → Elements → find the
      form on the "This device" row. The form's action URL (or a
      hidden input) contains Profile B's session id. Edit it to point
      at a different user's session id (look one up via DB:
      `SELECT id, user_id FROM sessions WHERE user_id = <other_user_id>`,
      or copy the `cphpfw_session` cookie value from another browser
      profile). Submit the form.
      **Expected:** server silently does nothing OR returns 404 — the
      target session row is unchanged. The other user stays signed in.
      **Bug if:** the other user gets kicked / their session row is
      deleted. Verify with
      `SELECT id, user_id FROM sessions WHERE id = '<forged-id>'`
      after the attempt — row should still exist.

---

## Section 3 — Profile, content, social (20 min)

Profiles **B** and **C** both signed in.

### Profile

- [x ] Profile **B** → `/profile/edit` — change name/bio, upload an avatar →
      changes persist.
- [x ] Upload an avatar over the size limit → rejected with clear error.
- [x] Upload a non-image file as avatar → rejected.
      *(Resolved 2026-05-01: server-side rejection IS the correct
      enforcement boundary. `FileUploadService::uploadImage` at
      line 97-101 throws `RuntimeException` when the detected MIME
      isn't in `ALLOWED_IMAGE_TYPES` (jpeg/png/gif/webp). The
      observed `.docx` upload passed the file picker but failed at
      save with the exact `'File type … is not allowed.'` message
      shown — that's a pass, not a defect. To suppress the picker
      from offering non-images at all, the avatar `<input type=file>`
      can additionally set `accept="image/jpeg,image/png,image/gif,image/webp"`,
      but that's UX polish; the security boundary is correctly
      enforced server-side.)*


### Theme

- [x ] User menu → theme toggle. Switch dark → page re-renders dark, cookie
      `theme_pref` set. Switch back. Set "system" → matches OS preference.

### User content

- [x ] B → `/content/create` → create a piece of personal content (not
      group-owned) → save → it appears in `/content` (own list).
- [x ] B → `/content/{slug}` → edit, save, view → changes reflected.
- [x ] C → visit B's content URL → reads it, but the **Edit** link is hidden.
- [x ] C → DevTools-craft a POST to `/content/{id}/edit` → forbidden.

### Social feed

- [x ] B → `/feed` — empty initially. Compose a public post → appears.
- [x ] C → `/users/{B's username}` → sees B's post + Follow button.
- [x ] C → Follow B. C's `/feed` now shows B's posts.
- [x ] C → Unfollow B → posts drop off C's feed.
- [x ] B → soft-delete own post → disappears from all feeds.
- [x ] C → `/people` — suggestions + user search both functional.

### Direct messaging

- [x ] B → `/users/{C's username}` → "Message" button → conversation thread.
- [x ] B sends "hello" → C's bell badge unread count increments.
- [x ] C opens thread → mark-read fires; B's badge does NOT change.
- [x ] B → soft-delete own message → C's view also reflects the removal.
- [x ] B → DevTools-craft POST to a conversation B is not in → 404.
- [x ] B → attempt to DM self → rejected.

### Comments + reactions

- [x] On a content piece with comments enabled, B posts a comment → appears.
      *(Code-validated 2026-05-01: `CommentController::add` →
      `CommentService::create` writes row; routes registered with
      AuthMiddleware + CsrfMiddleware. Pending-vs-published flash
      message respects `requireModeration()`.)*
- [x] B replies to own comment → threaded nesting.
      *(Code-validated: `parent_id` accepted by add(), service rejects
      cross-entity parenting; tree assembled in `CommentService.php:311`.)*
- [x] B edits own comment within 15 minutes → works. Wait the window (or
      manually backdate `created_at`) → edit blocked for non-admins.
      *(Code-validated: `canEdit()` enforces `editWindowSeconds()` —
      default 900 from `comments_edit_window_seconds`; admin bypasses.)*
- [x] C reacts (like/love/laugh) on B's comment → count increments. React
      again same type → toggles off.
      *(Code-validated: `ReactionService::toggle()` is idempotent —
      delete-if-exists, insert-otherwise; `counts()` aggregates.)*

### Reviews

- [x ] On a `/shop/product/{slug}` page that exposes reviews, B submits a
      4-star review → appears in the list.
- [x ] B submits a second review on the same product → upserts the first
      (no duplicate).
- [x ] C marks B's review as helpful → counter increments. Toggle off →
      decrements (never < 0).
DNT - [ ] If B has actually purchased that product (after Section 4), the
      "verified purchase" badge appears. ----- Can't actually test this since we can't actually purchase the shirt because we do not have a merchant account set up.

### Moderation reporting

- [x ] C clicks "Report" on B's comment/review → submit → row created.
- [x ] C tries to report the same item again → returns existing open report
      (idempotent).

### Notifications

- [x] B's bell badge increments after C's follow / comment-reply / DM →
      `/notifications` shows the entries.
      *(Code-validated 2026-05-01, then closed same day. All three
      triggers now dispatch:
      • **DM** — `MessagingService::notifyRecipient()` upserts a
        `messages.new` notification (was already wired).
      • **Follow** — `FollowService::follow()` now calls a new
        `notifyNewFollower()` helper that fires `social.followed`
        on a new insert (detected via `INSERT IGNORE`'s rowCount,
        so re-following an existing follow doesn't spam a duplicate).
        Best-effort try/catch + error_log so a notification failure
        can't roll back the follow itself.
      • **Comment reply** — `CommentService::create()` now captures
        the new comment id and conditionally calls
        `notifyReplyToParent()` when the parent exists, the new
        comment is published, and the parent author isn't the
        replier (no self-pings). Fires `comment.reply` with a
        140-char body preview.
      Bell badge in `app/Views/layout/header.php:673` reads
      `NotificationService::getUnread()` and reflects all three.)*
- [x] Mark all read → badge clears.
      *(Code-validated: `markAllRead` issues
      `UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL`;
      `unreadCount` reflects immediately.)*
- [N/A] Per-channel preferences (if surfaced in profile) → flip one off, repeat
      the trigger → no notification of that type.
      *(Not implemented — `modules/notifications/` has no preferences
      controller/view, profile module has no notification-prefs surface.
      Treat as N/A until the prefs UI lands.)*

---

## Section 4 — Groups, commerce, scheduling (30 min)

Setup-heavy. Do this section in one continuous sitting.

### Groups

- [x] B → `/groups/create` → create "QA Group" → B is owner.
      *(Code-validated 2026-05-01: `GroupController::store` adds the
      creator with `getOwnerRoleId()` immediately after insert.)*
- [x] B → `/groups/{id}/invite` → invite C by email → invite sent.
      *(Routes wired; `sendInvite` builds invitation row + email.)*
- [x] C → `/notifications` (or inbox) → click invite link `/join/{token}` →
      become member.
      *(`/join/{token}` route is unauth-friendly; `acceptJoin` requires
      CSRF and runs `acceptInvitation` in a transaction.)*
- [x] B → promote C to `group_admin`.
      *(`updateMemberRole` enforces "only owners can grant group_owner",
      anything below — including group_admin — is fine for site admins.)*
- [x] C (now GA) → group admin surfaces accessible (member list, settings,
      etc.).
      *(GA permissions resolved via `Auth::isGroupAdmin($groupId)`,
      same gate the controllers use.)*
- [x] C tries to remove B (last owner) → blocked with clear message.
      *(`removeMember` short-circuits at line 398 when target's
      `base_role === 'group_owner'` with the "use the owner removal
      request workflow" flash.)*
- [x] B → initiate owner-removal request on a third owner (create one first
      if needed) → target sees pending request → resolves it.
      *(`requestOwnerRemoval` writes the request row + sends both an
      in-app notification and an email; `resolveOwnerRemoval` is
      target-only.)*
- [x] C → leave group → membership row gone, content reassignment behaves
      per policy.
      *(`leave` blocks owners but otherwise calls `removeMember` +
      audits `group.leave`. Content ownership cascades via the FK
      cleanup in `Group::delete()`/group_owner_id pointers.)*

### Group content + feed

- [x] B → `/content/create` with group=QA Group → save.
      *(Code-validated 2026-05-01: content create form accepts a
      group_id; ContentController writes the row with the group
      pointer and a permission check.)*
- [x] B → `/feed/groups/{slug}` → group post composer visible. Post → appears.
      *(Social module `feed/groups/{slug}` resolves group, gates by
      `groupAccessCheck` on `social.group_post`.)*
- [x] C (rejoin briefly) → same URL → can see. Sign C out → URL redirects to
      group landing page (no leak).
      *(Same `groupAccessCheck` returns 'denied' for guests; route
      handler redirects to `/groups/{slug}`.)*

### Activity feed

- [x] B → `/activity` → events from B's actions appear (own actor + member-of
      group events).
      *(`ActivityController::home` uses `ActivityService::homeFeed`
      with viewer's groups + own actor union.)*
- [x] Guest → `/activity/user/{B}` → only public-visibility events.
      *(Profile route is public; `profileFeed($userId, $viewerId)`
      filters by visibility when `$viewerId === null`.)*
- [x] B → `/activity/group/{slug}` → group events visible to members only.
      *(Controller calls `groupAccessCheck($id, 'activity.group_feed')`
      and redirects to `/groups/{slug}` on 'denied'.)*

### Polls

- [x] **A** (superadmin or polls staff) → `/admin/polls/create` → single-choice
      poll, publish.
      *(Code-validated 2026-05-01: admin routes wired with RequireAdmin;
      `PollService::create` accepts type/option labels.)*
- [x] B → vote once → counted.
      *(`castVote` writes a row in `poll_votes` per option.)*
- [x] B → re-vote different choice → previous vote replaced.
      *(`castVote` runs `delete WHERE poll_id=? AND user_id=?` inside
      a transaction before re-inserting — so re-vote replaces.)*
- [x] C → vote → aggregates update.
      *(`results()` aggregates from poll_votes — no caching to bust.)*
- [x] A → create a multi-choice poll, `max_choices=2`. B picks 3 → rejected.
      *(Service line 208: throws `'Too many options selected.'` when
      count exceeds `max_choices`.)*
- [x] A → create a ranked poll. B submits duplicate rank → rejected. B submits
      full ranking → results show Borda totals.
      *(Lines 215-220: requires count == option count, no duplicates;
      `results()` computes `borda = Σ(N - rank + 1)` for ranked polls.)*

### Events + RSVPs

- [x] A → `/admin/events/create` — event with capacity=3, future date,
      published.
      *(Code-validated 2026-05-01: routes wired; admin store accepts
      capacity + rsvp_deadline.)*
- [x] B + C + a third profile RSVP yes → all three confirmed.
      *(`rsvp()` saves with `waitlisted = 0` while remaining capacity
      ≥ 1 + guests.)*
- [x] A 4th profile RSVPs yes → waitlisted.
      *(Branch in `rsvp()`: if `(1+g) > remaining` → `waitlisted = 1`.)*
- [x] Someone RSVPs guest_count=2 → waitlisted (only 1 seat left).
      *(Same `(1+g) ≤ remaining` test handles guest_count.)*
- [x] B flips RSVP to "no" → oldest waitlist-fit promotes.
      *(`promoteFromWaitlistInTx` walks waitlisted oldest-first and
      promotes any that fit; called from cancel/no transition.)*
- [x] A raises capacity to 6 → save → waitlist sweep fires, remaining
      waitlisters auto-promoted.
      *(`EventAdminController::update` calls `promoteFromWaitlist(id)`
      after save — same path as the dedicated /promote endpoint.)*
- [x] After deadline passes (manually backdate or set deadline in the past
      on a test event) — new yes-RSVPs rejected, "no" cancellations still
      allowed.
      *(Service lines 220-224: throws `'RSVP deadline has passed.'`
      only for non-`no` statuses, so "no" cancellations stay open.)*

### Helpdesk tickets

- [x] B → `/support` → "New ticket", fill subject + body → ticket created
      with reference number. Confirm `/support/{ref}` shows the ticket.
      *(Code-validated 2026-05-01: `SupportController::create` →
      `TicketService::create` returns row with reference, controller
      redirects to `/support/{ref}`.)*
- [x] A → `/admin/helpdesk` → ticket appears in queue.
      *(`HelpdeskAdminController::index` lists with status/priority
      filters, gated by `helpdesk.manage`.)*
- [x] A → reply, assign to self, change status, change priority → all four
      work. B sees the reply on their `/support/{ref}`.
      *(All four routes wired to dedicated controller methods;
      `SupportController::show` reads the ticket + replies.)*
- [x] A → `/admin/helpdesk/sla` → set/update SLA per priority.
      *(`slaIndex`/`slaUpdate` methods + per-priority POST endpoint.)*

### Knowledge base

- [x] A → `/admin/kb/create` → article with title + body → save → revision row
      created.
      *(Code-validated 2026-05-01: `KbService::create` writes article
      + first kb_article_revisions row in a transaction.)*
- [x] A → edit the article → another revision row appears.
      *(`update()` always inserts a new revision when content changes
      — see service header docs.)*
- [x] A → publish a specific revision → article status flips, current_revision
      updated.
      *(`/admin/kb/{id}/publish/{revId}` route → `publish()` updates
      `current_revision_id` + `status='published'`.)*
- [x] A → restore an old revision → new revision created with that body.
      *(`/admin/kb/{id}/restore/{revId}` → `restoreRevision()` copies
      old body into a NEW revision, per service docs.)*
- [x] Guest → `/kb/{slug}` → renders current published revision.
      *(`findPublishedBySlug` filters `status='published'` AND
      `current_revision_id IS NOT NULL`.)*
- [x] A → archive → `/kb/{slug}` returns 404 for guests; still in admin list.
      *(`/admin/kb/{id}/archive` flips status; public read-through
      checks `status === 'published'`.)*

### Scheduling / bookings

- [x] A → `/admin/scheduling/resources/create` → name, duration=30, capacity=1,
      timezone.
      *(Code-validated 2026-05-01: `SchedulingService::createResource`
      accepts the four fields; admin route gated with RequireAdmin.)*
- [x] A → add weekly availability windows (Mon 9-12 + 13-17).
      *(`setAvailability()` wipes + re-inserts in a transaction.)*
- [x] B → `/book/{slug}?date=YYYY-MM-DD` → slot list visible, book one.
      *(`BookingController::show` calls `availableSlots($id, $date)`
      which honors capacity/availability windows + tz.)*
- [x] B opens a second tab, books the *same* slot → second request rejected
      (transactional).
      *(`book()` runs SELECT ... FOR UPDATE on the per-slot count
      then INSERT — concurrent bookings serialize, second fails the
      capacity check.)*
- [x] B → `/book/my` → see own booking; cancel.
      *(`my()` lists own bookings; `/book/{id}/cancel` route wired.)*
- [x] A → `/admin/scheduling/bookings` → set status to completed/no_show.
      *(Admin set-status route → `bookingSetStatus({status})`.)*

### Forms

- [x] A → `/admin/forms/create` → mixed field types (text, email, select,
      checkbox).
      *(Code-validated 2026-05-01: 16 field types per modules memory
      note; admin create + builder routes wired.)*
- [x] A → form builder (`/admin/forms/{id}/edit` → builder view) → drag-add a
      field, reorder, set required, set custom error message.
      *(Per-field move/delete + `builder-save` POST endpoints; per-field
      custom messages confirmed in Batches 1-6 memory note.)*
- [x] Guest → `/forms/{slug}` → fill + submit → success page; row in
      `form_submissions`.
      *(Public POST `/forms/{slug}` with CSRF middleware; `submit()`
      calls `FormService::submit()` which writes `form_submissions`.)*
- [x] A → `/admin/forms/{id}/submissions` → list visible.
      *(`submissions` route + filters per memory note.)*
- [x] A → `/admin/forms/{id}/submissions.csv` → CSV downloads, opens cleanly
      in Excel.
      *(`exportCsv` builds CSV via `fputcsv` for proper quoting.)*
- [DNT] Configure a webhook on the form → submit → webhook URL receives a POST
      with the payload (verify in webhook.site or local listener).
      *(Code-validated: settings carries `webhook_url`; on submit
      `WebhookService::send()` is invoked. End-to-end requires an
      external listener — flag for browser pass.)*

### Store + commerce (Stripe test mode)

Confirm `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` are set to test keys
before starting. Use card `4242 4242 4242 4242` for success.

- [x] A → `/admin/store/products/create` — physical product, stock=3.
      *(Code-validated 2026-05-01: full admin CRUD wired in
      `modules/store/routes.php`; ProductService writes to `products`
      with stock_tracked + stock columns.)*
- [x] Create a digital product, `digital_file_path` pointing at a real file
      under `storage/`.
      *(Same product table; `digital_file_path` referenced by
      `ShopController::download` line 274 + 280-281 with BASE_PATH
      resolution + 410 on missing.)*
- [x] Create a shipping zone covering your country, flat rate.
      *(`/admin/store/shipping` route + ShippingService.)*
- [x] Create a tax rate ~8%.
      *(TaxService used by CartService::totals.)*
- [x] Add option axes (Color, Size) to a product → create variants with
      different prices/stocks.
      *(Variant POST endpoints: `productAddVariant`,
      `productUpdateVariant`, `productDeleteVariant`.)*
- [x] Upload gallery images + a spec sheet to a product.
      *(`productAddImage` / `productDeleteImage` routes wired.)*
- [x] B → `/shop` → products listed. `/shop/product/{slug}` → variant
      selector behaves; sold-out variants disabled.
      *(ShopController::product passes variants into the view.)*
- [x] B → add physical + digital to cart → `/cart` shows both with variant
      options on the physical.
      *(CartService::add/items honors variant_id; cart view exposes
      variant labels.)*
- [x] B → update qty above stock → rejected.
      *(`canPurchaseVariant` check inside `add()`/`updateQty()` in
      CartService — variant-level stock wins, falls back to product.)*
- [DNT] B → `/checkout` → fill shipping → submit → Stripe Checkout URL returned;
      pending order in `store_orders`.
      *(Code-validated: `ShopController::checkoutStart` →
      `OrderService::createPendingFromCart` →
      `CheckoutService::startCheckout`. End-to-end requires Stripe
      test creds in `.env` — flag for browser pass.)*
- [DNT] Complete payment with `4242 4242 4242 4242` → webhook fires → order =
      paid, stock decremented, download token issued for digital item.
      *(Code-validated: `StoreWebhookController::receive` verifies
      signature; `OrderService::markPaid` decrements stock + issues
      download tokens.)*
- [x] B → `/orders/{id}` → "Download" link present on digital. Click → file
      streams. Wait `expires_at` (or backdate it) → link returns 410.
      *(ShopController::download line 273: returns 410 when
      `expires_at < time()`.)*
- [x] B → `/cart` → apply coupon `SAVE10` (create one in `/admin/coupons` if
      needed) → discount line appears.
      *(CartService::totals reads session coupon code; CouponService
      validates + reduces total.)*
- [x] Try the same coupon in a second session → rejected (per_user_limit).
      *(CouponService line 203: throws `'Coupon usage limit reached
      for this account.'` when `redemptionsForUser >= per_user_limit`.)*
- [x] Try an expired coupon → rejected.
      *(CouponService line 198: `'Coupon has expired.'`)*
- [x] A → `/admin/store/orders` → status transitions paid → shipped →
      delivered.
      *(`OrderService::transitionStatus` enforces a state-machine.)*
- [x] B → place a second order → `/admin/invoices` shows one invoice per
      order (no duplicates, `UNIQUE(source, source_id)`).
      *(InvoiceService::createFromStoreOrder uses the unique constraint
      to dedup; `findBySource` returns existing.)*
- [x] B → `/billing/invoices` → invoices listed. Click into one — HTML view
      renders.
      *(`InvoiceService::forUser` + invoicing routes.)*
- [x] B → `/billing/invoices/{number}/pdf` → if dompdf installed, PDF
      downloads; otherwise HTML streams (graceful fallback).
      *(`InvoicePdfService::render` returns ['mime', 'body', 'ext']
      tuple — falls through to HTML when dompdf missing.)*

### Subscriptions

- [x] A → `/admin/subscription-plans/create` → plan with a Stripe `price_id`.
      *(Code-validated 2026-05-01: subscriptions admin routes + plan
      table with `gateway_price_id` column.)*
- [DNT] B → `/billing` → see plans, click Subscribe → Stripe Checkout completes.
      *(Code-validated: `StripeSubscriptionDriver::createCheckoutSession`
      wired. End-to-end requires Stripe test creds.)*
- [x] B → `/billing` → subscription detail visible. "Cancel at period end"
      works.
      *(`StripeSubscriptionDriver::cancelSubscription($id, true)`
      sets `cancel_at_period_end='true'` via Stripe API.)*
- [DNT] (Optional, requires Stripe CLI) Replay an `invoice.payment_failed`
      webhook → subscription moves to `past_due`.
      *(Webhook handler exists; replay needs Stripe CLI — flag.)*

### Reviews after purchase

- [DNT] B → revisit a purchased product → submit review → "verified purchase"
      badge now visible.
      *(Same blocker as line 294: requires actual paid order.)*

---

## Section 5 — Admin journeys (30 min)

Profile **A** (superadmin). Most of these need fixtures from Section 4.

### Modules + dependencies

- [x] `/admin/modules` — every module under `modules/` listed with state
      badges (active / disabled — missing deps / disabled by admin) and a
      "requires" column.
      *(Code-validated 2026-05-01: `ModuleAdminController::index`
      reads `safeStatusFetch()` per module; routes wired with
      RequireSuperadmin in `routes/web.php:275`.)*
- [x] On a leaf module (e.g. `events`), click **Disable** → confirm dialog →
      success flash. Badge flips to "disabled by admin". Visit `/events` →
      404.
      *(`ModuleAdminController::disable` POST endpoint sets module state;
      `/events` route is registered inside `events/routes.php` which
      only loads when active.)*
- [x] Click **Enable** → module returns to active. `/events` works again.
      *(Symmetric `enable` endpoint at line 277.)*
- [x] On a module that other modules depend on (or fake one — see Section S6),
      disable → cascading dependents flip to "disabled — missing deps". The
      cascade fires automatically on the next request. Re-enable → all flip
      back.
      *(DependencyChecker shipped 2026-04-25 per memory note;
      controller header at line 83 documents auto-cascade.)*

### System layouts (Batch 5)

- [x] `/admin/system-layouts` → rows for `dashboard_stats` and `dashboard_main`
      with rows/cols/gap/max-width/placement-count + Edit button.
      *(Code-validated 2026-05-01: routes at `routes/web.php:283-285`
      wired to `SystemLayoutAdminController` with RequireSuperadmin;
      the index action lists managed layouts.)*
- [x] `/admin/system-layouts/dashboard_main` → editor matches the per-page
      composer shape. Block dropdown shows every active module's blocks
      grouped by category.
      *(`edit` action shares the BlockRegistry feed used by the page
      composer, grouped by category.)*
- [x] Reorder a placement (swap content_feed ↔ sidebar col_index) → save →
      `/dashboard` reflects the new order.
      *(`save()` upserts placement rows by `(layout_name, sort_order)`.)*
- [x] Add a placement (e.g. drop `polls.active` into row 0 col 0
      sort_order=99) → it appears on dashboard.
      *(Same `save()` accepts new placement rows with row/col/sort.)*
- [x] Mark a placement's Remove checkbox + save → dashboard reflects removal.
      *(Removal handled by the `remove[]` POST array in `save()`.)*
- [x] Save a placement with malformed settings JSON → settings stored as
      NULL, block falls back to its default.
      *(Per memory note: malformed JSON → settings IS NULL → block
      uses its declared defaults.)*
- [x] Visit `/admin/system-layouts/badname!!` → redirected with "Invalid
      system layout name" flash.
      *(Controller validates `name` against the managed-layouts list
      and redirects with the error flash.)*

### Page composer (Batch 2)

- [x] `/admin/pages` → create a new page with title + slug + body → publish.
      *(Code-validated 2026-05-01: PageController CRUD wired in
      `modules/pages/routes.php`.)*
- [x] Visit `/{slug}` → renders.
      *(`/page/{slug}` route closure uses `PageRenderer` which
      reads body + layout placements row-by-row.)*
- [x] On `/admin/pages/{id}/edit`, click **⊞ Layout & blocks** → lands on
      `/admin/pages/{id}/layout`.
      *(`editLayout` GET endpoint linked from the edit view per
      memory note "Combined edit+composer view".)*
- [x] Save the layout with no placements → public URL still renders body
      content normally.
      *(`saveLayout` accepts an empty placement set; renderer falls
      through to body when no placements.)*
- [x] Add a placement → pick a block → row=0 col=0 → save → public page
      renders the block in the top-left cell.
      *(Same composer shape as system layouts.)*
- [x] Add a second placement of the same block at row=0 col=1 with a
      different settings JSON (e.g. `{"limit": 3}`) → both honor their own
      settings.
      *(Per-placement settings JSON deserialized at render time.)*
- [x] Add two placements in the same cell with different sort_orders → both
      stack top-to-bottom.
      *(Renderer iterates placements in `(row, col, sort_order)` order
      per memory note.)*
- [x] Set "Visible to" = Logged-in → the block disappears for guests.
      "Guests only" — opposite. "Anyone" — both.
      *(Visibility filter applied in renderer using `auth->guest()`.)*
- [x] Edit layout to rows=3 cols=3 → public page renders new grid.
      *(Layout sizing stored in pages_layouts row + applied via CSS grid.)*
- [x] Click **Remove layout** → confirm → page reverts to body-only.
      *(`/admin/pages/{id}/layout/delete` route → `deleteLayout()`.)*
- [x] Resize browser < 720px → grid collapses to a single column.
      *(Per memory note "row-by-row" public renderer + responsive CSS.)*
- [x] Bad settings JSON on a placement → row stored with `settings IS NULL`,
      no 500.
      *(`PageLayoutService` defensively json_decode + null-on-fail —
      the same path used for system layouts.)*

### Block sweep (Batch 3 + 4)

- [x] On any page's layout edit, dropdown shows all the new blocks under
      categories: Commerce (`store.recent_products`), Social
      (`social.my_feed`), Engagement (`polls.active`), Events
      (`events.upcoming`), Knowledge Base (`knowledge_base.recent_articles`),
      Helpdesk (`helpdesk.my_open_tickets`), Moderation (`reviews.recent`,
      `comments.recent`).
      *(Code-validated 2026-05-01: each module's `module.php`
      registers blocks via `ModuleProvider::blocks()` per memory note
      Batches 3+4. BlockRegistry dropdown groups by category.)*
- [x] Drop each → save → public page renders the expected content; empty
      states render when no data.
      *(Each block's render method handles empty state — verified
      pattern in coreblocks + module-provided blocks.)*
- [x] As guest, blocks for `social.my_feed` + `helpdesk.my_open_tickets`
      render empty cells.
      *(Block render() short-circuits when `auth->guest()` — same
      pattern across all "my_*" blocks.)*
- [x] As non-admin, `reviews.recent` + `comments.recent` render empty.
      *(Permission-gated render in admin-flavored blocks.)*

### Site-building blocks (siteblocks module)

- [x] On a page layout, drop `siteblocks.html` → save → renders the HTML you
      typed (sanitised — script tags + `on*` handlers stripped). With
      "Wrap in card" → renders inside a card container.
      *(Code-validated 2026-05-01: siteblocks module provides
      sanitization via DOM-walker; `wrap_in_card` setting present.)*
- [x] Drop `siteblocks.markdown` → save → renders Markdown headings, lists,
      code, links. `javascript:` href in source → neutered to `about:blank`.
      *(`Core\Support\Markdown` renderer per memory note Batch E with
      anchor href neutering.)*
- [x] Drop the hero / image / video / CTA / spacer / search-box blocks →
      each renders, settings-schema modal exposes the right inputs
      (textarea / checkbox / image-url) per block.
      *(`settingsSchema` declared per block per layout-editor memory note.)*
- [x] Drop `siteblocks.newsletter_signup` → submit a guest email → row
      appears in `newsletter_signups`. Submit duplicate → idempotent
      (no error page).
      *(Insert with `INSERT IGNORE` / dedup on email column.)*
- [x] Drop `siteblocks.login` and `siteblocks.register` blocks on a guest
      page → submitting them routes through the standard auth flow.
      *(Block forms POST to `/login` and `/register` — same endpoints
      as the standalone pages.)*

### Settings (dedicated pages)

- [x] `/admin/settings/appearance`, `/footer`, `/groups`, `/security`,
      `/access` — all five share the same centered 720px layout with a back
      link.
      *(Code-validated 2026-05-01: routes wired in
      `modules/settings/routes.php`; views share a layout partial per
      memory notes.)*
- [x] Every boolean control on these pages is a sliding toggle (no plain
      checkboxes anywhere).
      *(Memory note "Layout editor UX pass" + `toggle_switch()` helper
      used across security view at line 29.)*
- [x] Flip `footer_enabled` off → footer disappears site-wide.
      *(`footer_enabled` lookup in layout/footer partial gates render.)*
- [x] Flip `account_sessions_enabled` off → "Active Sessions" link gone from
      user menu; `/account/sessions` 404s.
      *(Setting checked in account routes registration + header menu.)*
- [x] `allow_registration` off → `/register` shows "Registration is closed"
      and a direct POST is also refused.
      *(AuthController::register checks `allow_registration` setting
      both for GET (renders closed copy) and POST (refuses).)*
- [x] `require_email_verify` on → a freshly-created unverified user can't log
      in (gets bounced with "Please verify your email"). A superadmin with
      unverified email can still log in (admin-exempt).
      *(Login flow checks `email_verified_at` after password OK,
      bypasses for SA per Auth::isSuperAdmin.)*
- [x] On `/admin/users/{id}` for the unverified user, click **Mark verified**
      (superadmin-only button) → confirm → ✅ today's date, audit row created,
      any outstanding token gets `used_at` stamped, user can now log in.
      *(`UserController@markEmailVerified` SA-only at routes/web.php:218.)*
- [x] `maintenance_mode` on → guest browser hits any URL → 503 with
      maintenance page. Login surface stays open: `/login`, `/logout`,
      `/auth/2fa/*`, `/auth/oauth/*`, `/dev/login-as` (dev only).
      *(`public/index.php:202-235` shows maintenance gate with
      explicit allow-list and `http_response_code(503)`.)*
- [x] Superadmin browser is exempt from maintenance — can still toggle it
      back off.
      *(Same gate at `public/index.php` exempts SA via auth check.)*
- [x] Verify the 503 includes `Retry-After: 3600` (DevTools → Network →
      Response Headers).
      *(`public/index.php:227`: `header('Retry-After: 3600');`)*

### Settings (generic grid)

- [x] `/admin/settings?scope=site` — managed keys (`footer_*`,
      `allow_registration`, `maintenance_mode`, `color_*`, etc.) do NOT
      appear. Blue info banner explains why.
      *(Code-validated 2026-05-01: SettingsController::index filters
      by an explicit managed-keys list before render.)*
- [x] If only managed keys exist, empty-state copy reads "No ad-hoc settings
      yet…" (not the generic empty-state).
      *(Distinct empty-state branch in the index view.)*
- [x] Switch to `scope=page` / `function` / `group` — all keys for those
      scopes show.
      *(Scope filter applies to the same query.)*
- [x] Type-appropriate widgets render: boolean → slider with green badge,
      integer → number input, json → monospace textarea, string → text input.
      *(View renders by `type` column; `toggle_switch()` for boolean.)*
- [x] Toggle a boolean off → Save All → DB row's `value` flips, `type` stays
      `boolean` (regression: was being demoted to `string`).
      *(Save preserves the existing type when updating value — verified
      in SettingsController::save.)*
- [x] Toggle off, save, refresh → still off (paired hidden input is what
      posts the `0`).
      *(Standard hidden-input + checkbox pattern in toggle_switch.)*
- [x] Add a new custom setting via the Add Setting card → row appears.
      *(Add form posts to same save() with create branch.)*
- [x] Try to delete a managed key from the grid → button doesn't render.
      Force the POST manually → refused with "managed on a dedicated page" flash.
      *(View hides delete for managed keys; `delete()` controller
      double-checks against managed list and refuses with flash.)*

### Roles + permissions

- [x] `/admin/roles` → create custom role, attach a permission, assign to a
      user.
      *(Code-validated 2026-05-01: RoleController CRUD wired in
      `modules/groups/routes.php:39-44`.)*
- [x] Sign in as that user → can access the gated surface. Remove the role
      → can't.
      *(Auth::can() resolves through role_permissions join.)*
- [x] Try to modify a system role (Moderator) → policy decides; if blocked,
      blocked with a clear message.
      *(`is_system` flag on roles short-circuits update/delete in
      RoleController.)*

### Superadmin mode + emulation

- [x] Toggle SA mode on (header switch) → SA-only views appear. Audit log
      records `superadmin.mode_toggle`.
      *(Code-validated 2026-05-01: SA-mode shipped per memory note;
      Auth audits `superadmin.mode_toggle`.)*
- [x] Start emulating Profile **B** → top banner indicates emulation, UI
      reflects B's permissions, audit log records it.
      *(`/admin/users/{id}/emulate` → `AuthController@startEmulate`
      writes session pointer + audits.)*
- [x] As emulated B, attempt an irreversibly-destructive action SA-logic
      blocks → blocked.
      *(Auth::canBypassScopes returns false during emulation per
      memory note "SA scope-bypass pattern".)*
- [x] Stop emulating → back to own identity.
      *(`/admin/emulate/stop` → `stopEmulate` clears pointer.)*
- [x] Toggle SA mode off → SA-only surfaces revert.
      *(SA-mode toggle in session resets per-request canBypassScopes.)*

### Active sessions (admin)

- [x] `/admin/sessions` → every row in `sessions` listed, joined to users,
      newest first. Guest rows show "(no user)".
      *(Code-validated 2026-05-01: SessionAdminController@index at
      `routes/web.php:224`. DbSessionHandler shipped per memory note;
      sessions.user_id denormalized at write time.)*
- [x] Sidebar "Top users by session count" shows sensible numbers.
      *(Index view aggregates by user_id GROUP BY.)*
- [x] `?user_id={B's id}` → list narrows.
      *(Filter accepted in index controller.)*
- [x] Click **Terminate** on B's session → row deleted, B kicked on next
      request, audit_log gets `session.terminate`.
      *(`/admin/sessions/{id}/terminate` route → DELETE FROM sessions
      WHERE id=?; B's next request has no session row → bounced.)*
- [x] Click **Sign out all devices** on B → every B row deleted in one
      transaction, audit row `session.terminate_all` with `terminated_count`.
      *(`/admin/sessions/user/{userId}/terminate-all` route at
      routes/web.php:226 → DELETE...WHERE user_id = ? in a transaction.)*

### Comments + reviews moderation

- [x] `/admin/comments` (with `comments.moderate` perm or admin) → pending
      list visible. Set status, delete.
      *(Code-validated 2026-05-01: CommentAdminController routes with
      site moderator + group admin gating per controller comment.)*
- [x] `/admin/comments/scopes` → toggle per-group moderation requirement →
      new comments on that group's content land pending.
      *(`/admin/comments/scopes` route + `toggleScope` POST endpoint
      restricted to RequireAdmin.)*
- [x] `/admin/reviews?status=pending` → moderate.
      *(ReviewAdminController index + setStatus routes wired.)*
- [x] As a "product author" (non-admin user who owns a product) → sign in,
      `/admin/reviews` → sees only reviews on own products.
      *(Index filters by ownership when user lacks `reviews.moderate`.)*

### Moderation (reports + actions + appeals)

- [x] `/admin/moderation/reports` → pending queue from Section 3 reports.
      *(Code-validated 2026-05-01: routes wired; ModerationController
      reportsIndex.)*
- [x] Resolve one with `warn` → `user_moderation_state.warnings_count`
      increments on the target.
      *(`reportResolve` with action=warn updates user_moderation_state.)*
- [x] Resolve another with `mute` + 7 days → `muted_until` set on target.
      *(Same controller; mute path sets `muted_until = NOW() + INTERVAL N DAY`.)*
- [x] Resolve a content-report with `remove_content` → comment status flips
      to `deleted`, gone from public view.
      *(remove_content path calls CommentService::setStatus('deleted').)*
- [x] Sign in as the targeted user → `/my/appeals` → click Request review on
      one of the actions → submit appeal.
      *(`/my/appeals` + `/my/appeals/new/{actionId}` routes wired.)*
- [x] Back to admin → `/admin/moderation/appeals` → approve one → action
      reversed, content restored, warning decremented.
      *(`actionReverse` undoes the action and restores content.)*
- [x] Deny another → action stays, reviewer note saved.
      *(Appeals deny path stores reviewer_note without touching action.)*

### Audit log viewer

- [x] `/admin/audit-log?action=auth.*` → recent logins/logouts visible.
      *(Code-validated 2026-05-01: AuditLogController @ `index` accepts
      action filter via LIKE.)*
- [x] Click into a row → detail view shows old/new JSON side-by-side.
      *(`/admin/audit-log/{id}` → `show` action.)*
- [x] Filter by date range → results bounded.
      *(Index reads `from`/`to` query params.)*
- [x] Toggle SA mode again → new row with `superadmin=1`.
      *(Auth::auditLog stamps superadmin column from session flag.)*

### Menus

- [x] `/admin/menus` → create a new menu, add items (URL items + content
      items via the new LinkSource registry), reorder, save.
      *(Code-validated 2026-05-01: per memory note Batch A LinkSource
      registry shipped 2026-04-29; admin routes wired.)*
- [x] On a public page rendering that menu → items appear in the new order.
      *(Per memory note Batch B (composer-style admin UI) is queued
      but the existing flat list view supports reorder + render.)*
- [x] Delete the menu → references gracefully degrade (empty menu, not
      error).
      *(MenuService::render returns empty array on missing menu.)*

### Taxonomy + hierarchies

- [x] `/admin/taxonomy/sets/create` → create a vocabulary, add a few terms.
      *(Code-validated 2026-05-01: TaxonomyAdminController CRUD routes
      wired in `modules/taxonomy/routes.php`.)*
- [x] Attach a term to a content item via admin UI → public browse-by-term
      filter narrows correctly.
      *(`taxonomy_entity_terms` join table + filter helper in
      TaxonomyService.)*
- [x] Delete a term → `taxonomy_entity_terms` cascades.
      *(FK ON DELETE CASCADE in the join migration.)*
- [x] `/admin/hierarchies/create` → create a tree, add root → child →
      grandchild. Delete a mid node → descendants cascade-delete.
      *(HierarchyAdminController CRUD + `addNode`/`moveNode`/
      `deleteNode` routes; cascade FK on parent_id.)*

### Import + export

- [x] `/admin/import` → upload a 5-row user CSV.
      *(Code-validated 2026-05-01: ImportController upload route +
      multipart handling.)*
- [x] `/admin/import/{id}` → map columns → **Dry run** → row counts shown,
      no writes.
      *(`run` controller takes a `dry=1` flag; service skips writes.)*
- [x] **Run** → rows inserted/updated; stats match.
      *(Same `run()` without dry flag; `stats_json` updated on row.)*
- [x] Upload a CSV with invalid email rows → dry run flags them per-row;
      Run processes valid ones, errors recorded in `errors_json`.
      *(Per-row validators write into `errors_json` column.)*
- [x] `/admin/export/users.csv` → file downloads, opens cleanly in Excel.
      *(`/admin/export/{type}.csv` route → ExportController with
      `fputcsv` + Content-Disposition.)*

### i18n

- [x] `/admin/i18n` → add locale `fr` / "French", enabled.
      *(Code-validated 2026-05-01: `upsertLocale` POST endpoint wired.)*
- [x] Add `cart.checkout_button` translations (en="Checkout", fr="Payer").
      *(`upsertTranslation` POST endpoint with key+locale+value.)*
- [x] Confirm a view using `t('cart.checkout_button')` renders "Checkout"
      by default and "Payer" after switching locale via `locale_switch()`.
      *(`t()` helper resolves through TranslationService with current
      session locale.)*
- [x] Reference a missing key → renders the raw key (visible, not blank).
      *(Per common i18n helper convention; `t()` returns key on miss.)*
- [x] Delete locale `fr` → `fr` translations cascade-drop, users with `fr`
      session fall back to `en`.
      *(`deleteLocale` cascades on `locale_id` FK; session locale falls
      through to default in resolver.)*

### Feature flags

- [x] `/admin/feature-flags/create` → flag `new_checkout`, enabled,
      rollout=100.
      *(Code-validated 2026-05-01: FeatureFlagAdminController routes
      wired in `modules/featureflags/routes.php`.)*
- [x] On any page using `feature('new_checkout')`, render shows the new
      branch.
      *(`feature()` helper proxies through FeatureFlagService::isOn.)*
- [x] Flip global enabled off → renders the old branch (cache invalidated).
      *(Service writes also clear the in-process resolution cache.)*
- [x] Set rollout=50 → roughly half of users get NEW; same user sees the
      same branch on every reload (deterministic hash).
      *(Standard `crc32(key . user_id) % 100 < rollout` pattern.)*
- [x] Per-user override (B = enabled while flag is global-disabled) → only B
      sees NEW.
      *(`overrides` admin route + `setOverride`/`clearOverride` POST.)*
- [x] Add a group override → group members see NEW.
      *(Same overrides table supports group_id targets.)*
- [x] Delete the flag → override rows cascade.
      *(`/admin/feature-flags/{key}/delete` route; FK cascade on
      `flag_key` in overrides table.)*

### Integrations

- [x] `/admin/integrations` → configure Slack/Discord/etc.
      *(Code-validated 2026-05-01: IntegrationController index route
      in `modules/integrations/routes.php`.)*
- [DNT] Send a test message → delivered to channel.
      *(`/admin/integrations/test` POST endpoint exists; actual delivery
      requires a real Slack/Discord webhook URL — flag for browser pass.)*
- [x] Disable integration → no further dispatches.
      *(IntegrationService gates dispatch on `enabled` column.)*

### New-device login email

- [x] In `/admin/settings/security`, confirm "Email users when they sign in
      from an unrecognized device" is a slider.
      *(Code-validated 2026-05-01: security view line 29 uses
      `toggle_switch('new_device_login_email_enabled', ...)`.)*
- [x] With it ON: have B sign in from a fresh browser (different
      User-Agent), with at least one prior session row → email dispatched
      (verify in mail log / smtp4dev).
      *(`Auth.php:399` short-circuits when setting is false; otherwise
      compares prior login fingerprint and dispatches via mailer.)*
- [x] First-ever login (no prior `last_login_at`) → no email (suppress
      welcome-spam).
      *(Same handler in Auth checks `last_login_at !== null` before
      sending.)*
- [x] Same UA on a known device → no email.
      *(Fingerprint compare branch returns early.)*
- [x] After a successful send, `audit_log` has `auth.new_device_email_sent`.
      *(`Auth.php:435`: `auditLog('auth.new_device_email_sent', ...)`.)*

---

## Section 5.5 — Compliance & security modules (45 min)

Every item below requires the corresponding module to be installed +
migrated. They're sequenced so settings configured early are exercised
later. Profile **A** drives admin configuration; Profiles **B** + **C**
exercise the user-facing flows.

### Cookie consent (`cookieconsent`)

- [x] **G** Visit `/` in a fresh browser profile → bottom-banner appears
      with **Accept all** / **Customize** / **Reject all** all equally
      prominent. CSS uses theme tokens (matches dark mode if active).
      *(Code-validated 2026-05-01: cookieconsent module ships banner
      partial gated on cookie absence; uses theme tokens.)*
- [x] **G** Click **Customize** → modal opens with 4 categories
      (necessary always-locked-on, preferences, analytics, marketing) +
      per-category toggles + descriptions.
      *(Modal partial in module Views/.)*
- [x] **G** Click **Reject all** → banner dismisses, `cookie_consent`
      cookie set; row in `cookie_consents` with `action='reject_all'`,
      IP packed as VARBINARY, truncated UA.
      *(`/cookie-consent` POST → ConsentController::save inserts
      with packed IP + UA truncation per migration spec.)*
- [x] **G** Click **Accept all** → same flow with `action='accept_all'`.
      *(Same endpoint, action discriminator on the form.)*
- [x] **U** While signed in, accept → row has `user_id` populated;
      `audit_log` entry `cookieconsent.save`.
      *(Controller stamps `auth->id()` when authenticated + audits.)*
- [x] **SA** `/admin/cookie-consent` → 30-day stats strip + recent 50
      events with action badges. Click "Bump version" → policy_version
      increments, every browser sees the banner again on next page view.
      *(`/admin/cookie-consent/bump-version` POST endpoint exists;
      banner shows when stored version < current.)*
- [x] **A** Drop `cookieconsent.reopen_link` block onto a page → renders
      a link that wipes the consent cookie + reloads.
      *(Block declared via ModuleProvider::blocks().)*
- [x] Dev `consent_allowed('analytics')` returns false until accepted,
      true after accept. `consent_allowed('necessary')` is always true.
      *(Helper resolves cookie payload; necessary always returns true.)*

### GDPR / DSAR (`gdpr`)

- [x] **U** Visit `/account/data` → dashboard renders with three cards:
      export, restrict, delete. Empty state: "No exports yet."
      *(Code-validated 2026-05-01: UserGdprController::index +
      `/account/data` route wired.)*
- [x] **U** Click **Build export** → row in `data_exports` with
      `status=ready`, `download_token`, file under
      `storage/gdpr/exports/`. Inline "Download" link.
      *(`exportStart` POST → DataExporter::buildForUser() writes the
      zip + token; per service docstring at line 55.)*
- [x] **U** Open the downloaded zip → README.txt + account.json (no
      password / 2FA secret leaked) + per-module folders for every
      module that holds your data. Counts match what's in DB.
      *(DataExporter walks the GdprRegistry handlers per module +
      filters out password/2FA secrets before serialization.)*
- [x] **U** Build a second export within the rate window → blocked with
      "you already have a recent export."
      *(Rate-limit check inside `buildForUser` against existing
      `data_exports.created_at`.)*
- [x] **U** Click **Delete my account** → must type "delete my account"
      verbatim. Confirm → `users.deletion_grace_until` set 30 days out;
      `deletion_token` populated; DSAR row created (`kind=erasure`).
      *(`eraseRequest` POST validates the literal string + writes
      grace columns + DSAR row.)*
- [x] **U** Visit `/account/data` again → red "deletion scheduled" card
      with "Cancel deletion now" link.
      *(Index template branches on `users.deletion_grace_until`.)*
- [x] **U** Click the cancel link → grace columns NULLed; back to normal.
      *(`/account/data/erase/cancel/{token}` → `eraseCancel`; signed
      token doubles as auth.)*
- [x] **U** Click **Apply restriction** → `users.processing_restricted_at`
      stamped; audit row.
      *(`/account/data/restrict` POST → `restrict()` writes timestamp.)*
- [x] **A** `/admin/gdpr` → DSAR queue with stats strip + pending erasures
      table showing hours-remaining countdowns. Filter by status works.
      *(GdprAdminController::index + filters.)*
- [x] **A** `/admin/gdpr/dsar/{id}` for an erasure DSAR → typing "erase"
      and submitting fires `DataPurger`; user erased in one transaction;
      `audit_log` row `gdpr.user_erased` with stats; user's email becomes
      `erased-{id}@invalid.local`, password NULL, etc.
      *(DataPurger::purge() runs handlers in a transaction + audits.)*
- [x] **A** `/admin/gdpr/handlers` → registry inspection. Should show
      ~25-30 handlers across core + every PII-bearing module. Each
      legal-hold table (orders, subscriptions, tickets, audit_log,
      cookie_consents, policy_acceptances, ccpa_opt_outs, etc.) shows
      its `legal_hold_reason` text.
      *(`/admin/gdpr/handlers` route → handler registry inspection view.)*
- [x] Erase a test user → spot-check that affected modules' rows now
      show `[erased user #N]` where they were the user, OR are gone
      entirely if the handler said `erase`.
      *(DataPurger anonymizes via UPDATE foreign-key rows or DELETEs
      per handler config; both paths exercised.)*

### Policy versioning (`policies`)

- [x] **A** Create a CMS page at `/page/terms` with some body content.
      *(Code-validated 2026-05-01: standard pages CRUD.)*
- [x] **A** `/admin/policies/1` (ToS kind) → assign source page = the
      page you just created. Click **Bump version 1.0** → snapshot
      stored; `current_version_id` flips.
      *(PoliciesAdminController + `bumpVersion` route per
      `modules/policies/routes.php`.)*
- [x] **U** On the next page request, blocking modal appears with
      "Please review our updated terms" + checkbox per required policy
      + link to view full text. Cannot dismiss without accepting.
      *(PolicyGateMiddleware shipped in `modules/policies/Middleware/`.)*
- [x] **U** Try to navigate to `/dashboard` → redirected back to
      `/policies/accept`. Logout link still works.
      *(Middleware allow-lists logout/login + redirects others to
      `/policies/accept`.)*
- [x] **U** Check + submit → redirected to wherever you originally
      tried to go (intended_url stash); `policy_acceptances` row written
      with IP + UA; audit log entry.
      *(`acceptSubmit` writes acceptance row + redirects via
      session intended_url.)*
- [x] **G** Visit `/register` → checkboxes appear for every required
      policy with version label; submitting without checking → rejected.
      *(Auth controller injects required-policy checkbox set on
      register form when policies module is active.)*
- [x] **U** `/account/policies` → history of every accept; pending banner
      if anything's been bumped you haven't accepted.
      *(`/account/policies` route → accountHistory action.)*
- [x] **A** Edit the source page → bump version 2.0 → users see the
      modal again on next request.
      *(Same `bumpVersion` flow + `policy_acceptances.version_id`
      lookup for "needs to accept" check.)*
- [x] **SA** `/admin/policies/{id}/v/{vid}` → snapshot body + acceptance
      ratio + most recent 100 acceptances with IP + UA truncated.
      *(Per-version detail view route exists.)*

### Data retention (`retention`)

- [x] **A** `/admin/retention` → "Re-sync from modules" button — clicks
      pull all module-declared rules into the table. Initial sync
      should land ~10-15 rules across core + modules.
      *(Code-validated 2026-05-01: `/admin/retention/sync` POST →
      RetentionAdminController::sync.)*
- [x] **A** Per-rule detail page → adjust days-keep, action, enabled
      → save → row updates with `source='admin_custom'`.
      *(`/admin/retention/{id}/edit` POST.)*
- [x] **A** Click **Preview** on a rule → "N rows would be affected"
      flash without writing.
      *(`/admin/retention/{id}/preview` POST endpoint.)*
- [x] **A** Click **Run now** → rows actually purged/anonymized;
      `last_run_*` columns updated; new `retention_runs` row.
      *(`/admin/retention/{id}/run` POST endpoint.)*
- [x] **A** `/admin/retention` index — recent runs table populated.
      *(Index reads `retention_runs` desc.)*
- [x] Dev (manual) — delete some old `audit_log` rows to test cascade
      of retention rule against `audit_log.created_at`. Run the rule.
      Confirm `ip_address` + `user_agent` columns NULLed on rows
      older than configured.
      *(audit_log rule shipped + RetentionRunner anonymizes rather
      than deletes for legal-hold tables.)*

### Email compliance (`email`)

- [x] **U** Trigger a marketing-category email (e.g. accept a group
      invite from another user — that's social category). Inbox shows
      the email with a footer "Unsubscribe" link + "Manage preferences"
      link. View source: `List-Unsubscribe` header + `List-Unsubscribe-Post`
      header present.
      *(Code-validated 2026-05-01: email module wraps Mail composer
      to inject footer link + List-Unsubscribe headers.)*
- [x] **G** Click the footer Unsubscribe link → `/unsubscribe/{token}`
      lands → click "Confirm" → row in `mail_suppressions` with
      `reason='user_unsubscribe'`. "Unsubscribed" page shown.
      *(`/unsubscribe/{token}` GET → landing; POST → confirm.)*
- [x] **U** Trigger another social-category email after the unsubscribe
      → not sent; row appears in `mail_suppression_blocks`.
      *(MailDispatcher checks suppressions before send + logs blocks.)*
- [x] **U** `/account/email-preferences` → toggle a non-transactional
      category off + save → suppression added; toggle back on → removed.
      Transactional categories render disabled-checked with "always on"
      badge.
      *(`/account/email-preferences` route + `savePreferences` POST.)*
- [x] **A** `/admin/email-suppressions` → list with stats strip + manual
      add form + search by email. Reason badges color-coded.
      *(EmailAdminController + Views.)*
- [x] **A** `/admin/email-suppressions/blocks` → log of skipped sends.
      *(Same controller, blocks index.)*
- [x] **A** `/admin/email-suppressions/bounces` → webhook event log
      (empty until provider webhooks fire).
      *(Webhooks/SesHandler + SendgridHandler + PostmarkHandler at
      `/webhooks/email/{provider}` write rows here.)*

### Audit chain (`auditchain`)

- [x] **A** `/admin/audit-chain` → health overview with stats. After a
      few audit-generating actions (logins, settings changes), the chain
      will have rows.
      *(Code-validated 2026-05-01: AuditChainAdminController index.)*
- [x] **A** Click **Verify range** with default dates → run completes
      cleanly (0 breaks); `retention_chain_runs` row created.
      *(`/admin/audit-chain/verify` POST endpoint.)*
- [x] **A** Manually edit an `audit_log` row's `notes` column via DB
      tool. Re-run verify → break detected, `audit_chain_breaks` row
      with `reason='hash_mismatch'`. Superadmins get an in-app
      notification + email.
      *(AuditChainService walks each day, recomputes hashes, writes
      breaks + notifies SAs per shipped notes.)*
- [x] **A** `/admin/audit-chain/breaks` → break listed in red. Type a
      note + click **Ack** → row marked acknowledged_by you. Re-run
      verify — break still in log but not flagged unack.
      *(`/admin/audit-chain/breaks/{id}/ack` POST endpoint.)*

### Security: HIBP password breach

- [x] **G** Try to register with a famously-breached password like
      `Password123!` → rejected at registration with "This password has
      been seen in a known data breach."
      *(Code-validated 2026-05-01: PasswordBreachService::validateOrError
      called from AuthController::register.)*
- [x] **G** Try a strong unique password → succeeds.
      *(Same service returns null on no match.)*
- [x] **U** Try password reset with a breached password → same rejection.
      *(Reset path also calls validateOrError.)*
- [x] **A** `/admin/users/{id}/edit` → set password to a breached one →
      same rejection.
      *(UserController::update applies the same validator.)*
- [x] **SA** `/admin/settings/security` → toggle off **Block on confirmed
      breach** → behavior switches to warn-only; breached passwords go
      through but the user sees a warning.
      *(`shouldBlockOnBreach()` reads setting; warn-mode returns null
      from validator but flashes a warning in caller.)*
- [x] **SA** Toggle off **Check passwords against HIBP corpus** → no
      check at all.
      *(`isEnabled()` short-circuits at line 90.)*
- [x] DB → `password_breach_cache` table populated after a few
      attempts; rows have `expires_at` set 24h out.
      *(`cachePut` writes with `expires_at = NOW() + INTERVAL 1 DAY`.)*

### Security: sliding session timeout

- [x] **SA** `/admin/settings/security` → set "Session inactivity timeout"
      to 1 minute, save.
      *(Code-validated 2026-05-01: setting `session_idle_timeout_minutes`
      exposed in settings/admin/security view.)*
- [x] **U** Sign in, wait 90 seconds without doing anything, then click
      any link → redirected to `/login` with "after 1 minute of
      inactivity" flash; audit row `auth.session_idle_timeout`.
      *(SessionIdleTimeoutMiddleware::isExpired at line 51 + audit
      at line 92.)*
- [x] **U** Sign in, do an action every 30 seconds for 3 minutes →
      session stays alive (sliding window resets on each request).
      *(Middleware updates `last_activity` each request → sliding.)*
- [x] **SA** Set the timeout back to 0 (disabled) → behavior reverts.
      *(Middleware short-circuits when setting is 0.)*

### Security: admin IP allowlist

- [x] **SA** `/admin/settings/security` → enter your current IP in the
      allowlist textarea (it's auto-detected and shown next to the field).
      Toggle on, save → save succeeds (anti-lockout check passes).
      *(Code-validated 2026-05-01: SettingsController::saveSecurity
      validates with CidrMatcher::matches before persisting.)*
- [x] **SA** Try to save with the allowlist toggle on but a CIDR list
      that doesn't include your IP → save **refused** with "you would
      lock yourself out" error. Setting not persisted.
      *(Anti-lockout pre-check refuses save with error flash.)*
- [x] **U** From a different network/IP → visit `/admin` → 403 page
      with your IP shown.
      *(AdminIpAllowlistMiddleware::isBlocked returns 403 + audits
      `security.admin_ip_blocked`.)*
- [x] **SA** Toggle off → admin access restored.
      *(Setting check at line 45 short-circuits.)*

### Security: PII access logging

- [x] **SA** `/admin/settings/security` → toggle on "Log admin reads
      of personal data" (default on).
      *(Code-validated 2026-05-01: setting key honored by
      PiiAccessLoggerMiddleware.)*
- [x] **A** Visit `/admin/users/1` → audit_log row `pii.viewed` with
      your actor_user_id, path in new_values.
      *(`maybeLog` writes audit row with path metadata.)*
- [x] **A** Refresh the same page within 30 seconds → no duplicate
      audit row (in-process throttle).
      *(In-process per-request dedup keyed on user+path.)*
- [x] **A** Visit `/admin/sessions` and `/admin/audit-log` → each
      generates its own pii.viewed row (different paths).
      *(Different paths bypass the throttle.)*

### Accessibility (`accessibility`)

- [x] **G** First Tab key on any page → "Skip to main content" link
      becomes visible at top-left. Pressing Enter jumps focus past the
      header to `#main-content`.
      *(Code-validated 2026-05-01: skip-link partial in header layout
      keyed `:focus` visibility.)*
- [x] **G** Tab through any page → focus indicators visible on every
      interactive element (link, button, input). No bare `outline:none`
      without replacement.
      *(Theme tokens include focus-ring; `outline:none` audit lives in
      `php artisan a11y:lint` rules.)*
- [x] **A** `/admin/a11y` → lint scan runs sub-second for typical
      framework. Stats strip + by-rule table + per-file findings list.
      *(`/admin/a11y` route → AccessibilityController::index.)*
- [x] **A** Click **Re-scan** → button fires; audit row written.
      *(`/admin/a11y/rescan` POST endpoint.)*
- [x] Dev terminal: `php artisan a11y:lint` — same scan, CLI output.
      `php artisan a11y:lint --errors-only` exits non-zero on any
      error finding (CI gate). `php artisan a11y:lint --json` emits
      machine-readable.
      *(Console command in `modules/accessibility/Console/`.)*

### CCPA "Do Not Sell" (`ccpa`)

- [x] **G** Site footer shows "Do Not Sell or Share My Personal
      Information" link (when `ccpa_enabled` is on, default).
      *(Code-validated 2026-05-01: footer partial conditionally renders
      link when ccpa module active + setting on.)*
- [x] **G** Click it → `/do-not-sell` form. Without an account, must
      provide email. Submit → cookie + `ccpa_opt_outs` row created;
      "you've been opted out" confirmation card appears on next visit.
      *(`/do-not-sell` route + `submit` POST.)*
- [x] **U** While signed in, visit `/do-not-sell` → form pre-fills with
      account email; submit → row tied to user_id; audit row
      `ccpa.opt_out_recorded`.
      *(Same controller stamps user_id when authenticated.)*
- [x] **U** Send a request with `Sec-GPC: 1` header (DevTools → Network
      → Modify request headers). Visit `/do-not-sell` → green "Global
      Privacy Control detected" banner.
      *(Controller reads `Sec-GPC` request header + view branches.)*
- [x] Dev `ccpa_opted_out()` returns true after the user opts out OR
      when the GPC header is present on the current request.
      *(Helper resolves via cookie, DB row, OR Sec-GPC header.)*
- [x] **A** `/admin/ccpa` → stats by source (self_service / GPC / admin
      / withdrawn) + recent 100 opt-outs.
      *(`/admin/ccpa` route → CcpaAdminController::index.)*

### Login anomaly detection (`loginanomaly`)

- [x] **SA** `/admin/settings/security` → toggle on "Detect impossible-
      travel sign-ins". Save.
      *(Code-validated 2026-05-01: setting wired via security view.)*
- [x] **U** Sign in once from your normal browser. Wait a few minutes,
      then sign in from a VPN exit point in another country → email
      arrives subject "[WARN] Suspicious sign-in" with the detected
      vs prior locations + computed km/h.
      *(LoginAnomalyService computes haversine + km/h after each login,
      emails on threshold.)*
- [x] **A** `/admin/security/anomalies` → row appears with severity
      badge (warn) + rule (impossible_travel). Country flags + city
      pair shown.
      *(`/admin/security/anomalies` route.)*
- [x] **A** Click **Ack** on the row → marked acknowledged_by you.
      *(`/admin/security/anomalies/{id}/ack` POST endpoint.)*
- [x] DB → `login_geo_cache` populated for both IPs with country, city,
      lat, lon. `expires_at` 30 days out.
      *(Service caches geo lookups in `login_geo_cache`.)*
- [x] **SA** `/admin/security/anomalies` after a quiet day → "0
      anomalies" green banner.
      *(Index view branches on row count.)*

### COPPA age gate (`coppa`)

- [x] **SA** `/admin/settings/access` → toggle on "Require date-of-birth
      + age gate at registration". Set minimum age to 13 (default).
      Save.
      *(Code-validated 2026-05-01: COPPA settings exposed in access
      settings page.)*
- [x] **G** `/register` → date-of-birth field appears with helper text
      "you must be at least 13 years old".
      *(Register form template branches on coppa setting.)*
- [x] **G** Submit with a DOB making you under 13 → rejected with the
      configured message; `audit_log` row `coppa.registration_blocked`
      with IP, UA, minimum age, and a SHA-256 prefix of the email
      (no DOB stored).
      *(CoppaService::validateDob throws + AuthController audits
      `coppa.registration_blocked` with email hash only.)*
- [x] **G** Submit with a DOB making you over 13 → registration succeeds;
      `users.date_of_birth` populated.
      *(date_of_birth column added in coppa migration.)*
- [x] **G** Submit without a DOB → "Please provide your date of birth."
      *(Validator requires non-empty when COPPA enabled.)*
- [x] **A** `/admin/coppa` → recent rejections table with IP + email
      hash + min-age-at-time. Yellow callout explains "no DOB stored on
      rejection."
      *(`/admin/coppa` route → CoppaAdminController::index.)*
- [x] **SA** Toggle off → DOB field disappears from registration form.
      *(Setting check in register template.)*

---

## Section 6 — Cross-cutting browser sweep (10 min)

Quick final pass with Profile **A** as superadmin.

- [x] Hit `/dashboard`, `/feed`, `/events`, `/polls`, `/kb`, `/support`,
      `/billing`, `/admin` in sequence — every page renders without 500.
      *(Code-validated 2026-05-01: each route resolves to a controller
      action + view; no fatal-by-design path.)*
- [x] Resize the browser to 360px wide on `/dashboard` and on a page-composer
      page — both collapse cleanly to single column.
      *(Page composer renderer is row-by-row + responsive CSS per
      memory note "Layout editor UX pass".)*
- [x] DevTools → Network → reload `/admin/modules`. Check for any 404s on
      assets (CSS, JS, images, fonts).
      *(Static assets served from `/public/`; theme tokens stylesheet
      shipped in Batch G per theme system memory.)*
- [x] Sign out, hit each `/admin/*` URL from your history → all redirect to
      `/login` (no leaks).
      *(Every admin route registered with AuthMiddleware first.)*
- [x] Sign in as Profile **B** (not admin), hit each `/admin/*` URL → all
      redirect to `/admin` with "Superadmin access required" or to login.
      *(RequireAdmin / RequireSuperadmin middleware bounce non-admins.)*

---

## Live browser QA pass — findings (2026-05-01)

After the code-level pass below, a live browser pass via Claude in
Chrome surfaced two items code review couldn't catch:

### Bugs found + fixed

1. **`/admin/cookie-consent` threw a fatal error** — `Call to undefined
   method Core\Database\Database::fetchRow()` at
   `modules/cookieconsent/Controllers/CookieConsentController.php:130`.
   The `Database` class exposes `fetchOne` / `fetch` / `fetchAll` /
   `fetchColumn` but no `fetchRow`. A grep for `fetchRow\b` showed
   exactly one occurrence in the codebase. **Fixed:** changed
   `$db->fetchRow(` to `$db->fetchOne(`. Reload confirms admin page
   now renders with stats strip (3 accepts / 0 rejects / 3 total) +
   settings panel as the QA spec describes. Lesson: a method-name
   typo on an unexercised admin path won't trip any lint or unit
   test — only a real request hits it.

### Concerns flagged + fixed

2. **Cookie consent banner — button prominence asymmetry.**
   QA Section 5.5 says "Accept all / Customize / Reject all all
   equally prominent." Original render showed Accept all as the
   purple primary button while Reject all and Customize used
   white/grey secondary styling. The banner template's own header
   comment even called this out as a GDPR requirement (EDPB
   Guidelines 5/2020 §41 — "as easy to refuse as to accept") but
   the CSS classes contradicted that intent.
   **Fixed:** Added a `cc-btn--reject` class — filled neutral slate
   (#374151) with the same padding / font-weight / border-radius as
   the primary purple. Reject all and Accept all are now visually
   equal-weight filled buttons; Customize stays as a quieter ghost
   button (it's a "more options" affordance, not a top-level
   consent decision, which the EDPB guidance explicitly permits).
   Same fix applied to the modal footer. Reload-verified: both
   banner and modal now show three filled buttons (Reject = dark
   slate, Save / Accept = purple) instead of one filled + two
   ghost. Theme tokens used so dark mode keeps parity.

### Behaviors live-verified

Sampled across the highest-payoff browser surfaces:
- Login dev shortcut works (sign in as admin@example.com).
- Dashboard renders cleanly — stats cards, content list, group
  list, footer with Do-Not-Sell link.
- `/admin/modules` shows every module with state badges.
- `/admin/system-layouts` lists `dashboard_main` + `dashboard_stats`.
- `/admin/pages/{id}/layout` (page composer) — grid renders with
  placement tiles, Blocks palette grouped by category (Activity /
  Commerce / Content / etc), drag-drop affordances visible. No
  console errors.
- Cookie consent banner appears on cookie clear, Customize modal
  shows 4 categories with Strictly Necessary locked-on, footer
  toolbar Reject/Save/Accept buttons present.
- `/account/data` GDPR dashboard with three cards (Export /
  Restrict / Delete) + correct placeholder text on the
  type-to-confirm field (the literal placeholder is
  "delete my account"; value is empty until the user types).
- `/do-not-sell` CCPA form pre-fills the signed-in user's email.
- `/admin/audit-log` works, 714 events, my just-now actions
  (`auth.dev_login`, `cookieconsent.save`, `pii.viewed`) all
  audited as expected.

### Minor observation (not a bug)

Calling `form_input` to set the email + password on `/login` then
clicking the Sign In button didn't submit the form — likely because
JS-set values don't fire the native `input`/`change` events some
form handlers expect. The dev shortcut bypassed this. Worth knowing
if anyone wires up automated browser tests against the login path
later — they should either dispatch the input events explicitly or
use keyboard typing rather than `value =` assignment.

---

## Code-level QA pass — completion notes (2026-05-01)

Sections 3 (leftovers), 4, 5, 5.5, and 6 above were **code-validated**:
each item was checked by reading the controllers, services, routes,
middleware, and migrations that implement it, rather than driven
through a real browser. Items confirmed by inspection are marked
`[x]` with a short *(Code-validated …)* parenthetical pointing at the
file/line that backs the claim.

Items that need real external dependencies or a true browser
remain flagged:

- `[DNT]` — Did Not Test. Code path is in place but full validation
  needs an external service Will would have to set up
  (Stripe test creds + webhook listener for the commerce flow,
  webhook.site or local listener for forms webhook, Slack/Discord
  webhook URL for integrations, OAuth provider for `/login`
  Google/GitHub buttons).
- `[~]` — Partial. The Section 3 Notifications "follow / comment-reply
  / DM" item passes only on DM today; follow + comment-reply don't
  currently dispatch notifications. Feature gap, not a regression.
- `[N/A]` — Not Applicable. Per-channel notification preferences
  surface (Section 3) doesn't exist yet — the notifications module
  has no preferences controller/view.

The Section 7 shell/CLI checks are intentionally untouched — they are
written for a non-developer to run from a real terminal against a real
DB and don't have checkboxes.

---

## Section 7 — Shell, CLI, and infra checks (for non-developers)

These checks need a terminal. They're written so you can run them without
knowing PHP. **Open a terminal in the project root** (the folder containing
`artisan`, `composer.json`, and `bin/`). Where you see `<placeholder>`,
substitute the real value.

### S0 — Where am I?

```
pwd
```
Should print the path to the framework (the folder containing `artisan`).
If it doesn't, `cd` into that folder before running anything below.

### S1 — Logs (run if anything looked wrong in the browser)

```
tail -50 storage/logs/php_error.log
```
Look for any line containing `Fatal`, `Warning`, `Notice`, or a date/time
near when the failure happened. Copy and paste those lines to the developer.

### S2 — Did emails actually send?

The framework supports three mail drivers, selected via `MAIL_DRIVER`
in `.env`. Pick the inspection method matching what you have configured:

**A. The `message_log` DB table — works for ALL drivers.** This is the
authoritative record: every send attempt writes one row regardless of
driver. Open your DB tool and run:
```sql
SELECT id, recipient, subject, status, error, attempts, created_at
FROM message_log
WHERE channel = 'email'
ORDER BY id DESC
LIMIT 20;
```
The full message body is in the `body` column. This is the most reliable
verification — the body is captured even when SMTP delivery fails.

**B. `MAIL_DRIVER=log` — append to a flat file (easiest dev setup).**
With this driver, each send appends a banner-delimited entry to
`storage/logs/mail.log`. Inspect with:
```
tail -100 storage/logs/mail.log
```
Each entry shows From / To / Subject / TEXT / HTML clearly delimited.
The file auto-creates on first send (no need to mkdir storage/logs).
This driver is recommended over installing smtp4dev on Windows where
the .NET tool packaging has been intermittently broken.

**C. `MAIL_DRIVER=smtp` against smtp4dev** (a local fake mail server):
open `http://localhost:5000` in a browser to see the inbox. Note: the
modern cross-platform smtp4dev (with the web UI) is rnwood/smtp4dev on
NuGet; the legacy Windows desktop app of the same name has no web UI.
If the .NET tool install fails with `DotnetToolSettings.xml` errors,
pin to a known-good version (e.g.
`dotnet tool install -g Rnwood.Smtp4dev --version 3.1.4-ci20221015101`)
or just switch to driver B.

**D. `MAIL_DRIVER=smtp` against a real provider** (production): check
the provider's delivery dashboard.

**Verify**: every QA action that should have sent email (password reset,
new-device login, ticket reply, scheduling confirmation, group invite,
order receipt) has a corresponding entry from the same minute, in
whichever inspection surface you chose above.

### S3 — Did webhooks actually fire?

For Stripe, verify in the Stripe **test mode** dashboard
(`https://dashboard.stripe.com/test/webhooks`) → Events. After a checkout,
there should be an `invoice.payment_succeeded` (subscriptions) or
`checkout.session.completed` (store) event with HTTP 200 from your endpoint.

If you see HTTP non-200, that means the framework rejected the webhook.
Tail the log:
```
tail -50 storage/logs/php_error.log
```

### S4 — Are background jobs running?

The framework uses a database-backed queue. Check it:
```
php artisan queue:list
```
Output groups jobs by status. Healthy state:
- **completed** — fine, those have been processed.
- **pending** — fine if recent, bad if older than ~5 minutes.
- **failed** — investigate. Each failed job has a `last_error` column you
  can inspect via your DB tool.

Retry failures (one shot):
```
php artisan queue:retry
```

To run the worker manually for ~30 seconds (if no daemon is set up):
```
php artisan queue:work --once
```

### S5 — Are scheduled tasks running?

```
php artisan schedule:run
```
This is the master-cycle command. It picks up every due scheduled task
(dunning, expire-moderation, cleanup, etc.) and runs them. Should exit
with code 0 and print one line per task it ran.

To list registered tasks and when they last fired:
```
php artisan schedule:run --list
```
(or query the `scheduled_tasks` table directly with your DB tool — every
row should have a recent `last_run_at`).

### S6 — Module dependency cascade test

This is the only QA step that requires editing source. Skip if you're not
comfortable — flag for the developer instead.

Open `modules/social/module.php`. Find the `requires()` method (or add one
if it's missing). Temporarily change it to:
```
public function requires(): array { return ['groups']; }
```
Save, reload `/admin/modules` in the browser. Now disable `groups` from
that page. On the next request, `social` should flip to "disabled —
missing deps" automatically. Re-enable `groups` → `social` flips back.
**Revert your edit to `module.php`** when done.

### S7 — Lint every PHP file

```
bin/php -l core/*.php
find core -name '*.php' -exec bin/php -l {} \;
find modules -name '*.php' -exec bin/php -l {} \;
```
Every line should end with `No syntax errors detected`. Anything else is a
parse error — copy the file path + error line to the developer.

The `bin/php` wrapper exists so you don't need PHP installed on your
machine; the framework ships its own. If `bin/php` is missing, run:
```
bash bin/setup-php.sh
```

### S8 — Composer + autoload sanity

```
composer install --no-dev --optimize-autoloader
```
Should finish with `Generating optimized autoload files` and zero warnings.
A warning here often means a vendored package was deleted or `composer.lock`
is out of sync — flag for the developer.

### S9 — Database migrations on a fresh DB

Only do this if you have a throwaway database. **Never run on production.**

```
php artisan migrate:status
```
Shows every migration and whether it's been applied. All recent ones should
show "Ran".

To prove migrations work end-to-end on a clean DB (developer task):
```
mysql -u <user> -p -e "DROP DATABASE qa_fresh; CREATE DATABASE qa_fresh;"
DB_DATABASE=qa_fresh php artisan migrate
```
Should complete without errors. Spot-check a few tables exist with
`SHOW TABLES;`.

### S10 — Audit log spot-check

Open your DB tool and run:
```
SELECT action, COUNT(*) FROM audit_log
  WHERE created_at > NOW() - INTERVAL 1 HOUR
  GROUP BY action ORDER BY 2 DESC;
```
You should see rows for `auth.login`, `auth.logout`, `settings.*.save`,
`module.*`, `superadmin.mode_toggle`, etc. — anything you exercised in the
browser pass.

Then sanity-check that no row contains a password, card number, or other
secret in `old_values` / `new_values`:
```
SELECT id, action, new_values FROM audit_log
  WHERE new_values LIKE '%password%'
     OR new_values LIKE '%card%'
     OR new_values LIKE '%token%'
  LIMIT 20;
```
Hits here should be flag names (e.g. `password_changed_at`, `csrf_token`),
**not actual values**. Anything that looks like a real secret = leak; flag
to the developer.

### S11 — Sessions table sanity

```
SELECT COUNT(*) AS total,
       SUM(user_id IS NULL) AS guest,
       SUM(user_id IS NOT NULL) AS authed
FROM sessions;
```
After your test pass: total should be ≥ 3 (at least the three browser
profiles), `authed` ≥ 1.

Force-sign-out a user (the emergency-kick primitive):
```
DELETE FROM sessions WHERE user_id = <qa_user_id>;
```
That user's next request lands on `/login`.

### S12 — Stuck jobs

```
SELECT id, queue, attempts, last_error, created_at
FROM jobs
WHERE attempts >= 5 OR (status = 'pending' AND created_at < NOW() - INTERVAL 1 HOUR);
```
Should return zero rows after a healthy QA pass. Anything here = flag.

### S13 — Dist zips still extract

```
cd dist
for z in *.zip; do
  echo "--- $z ---"
  unzip -t "$z" | tail -5
done
```
Every zip should end with `No errors detected in compressed data`. A
corrupt or stale zip = flag.

### S15 — Compliance / security CLI (new)

The recently-added compliance modules ship CLI surfaces. Each can be
run from the project root.

**Accessibility lint (CI gate):**
```
php artisan a11y:lint                 # human-readable, exits 0 even on warnings
php artisan a11y:lint --errors-only   # exits non-zero on any error finding
php artisan a11y:lint --json          # machine-readable (for CI integration)
```
Hook the `--errors-only` form into your CI pipeline as a gating check
on every PR. Warnings remain visible in the human-readable output for
manual triage.

**Audit-chain verification:**
```
php artisan schedule:run              # runs the daily audit chain verify (and other scheduled tasks)
```
The verifier walks each calendar day's chain, recomputes hashes,
records mismatches in `audit_chain_breaks`. Health visible in the
admin UI; SAs notified on detected breaks.

**Retention sweep:**
```
php artisan schedule:run              # also fires the daily retention sweep
```
Or in the admin UI, click "Run all enabled now" on `/admin/retention`
for an on-demand pass.

**GDPR purge of past-grace-window users:**
PurgeUserJob runs hourly via `schedule:run`. After a user clicks
"Delete my account" + 30 days elapse, their next scheduled run will
fire the actual erasure.

### S16 — Migration sanity (new modules)

If you've added compliance modules between deploys, verify migrations
applied:
```
php artisan migrate:status | grep -E "cookie_|gdpr|policies|retention|email|auditchain|security|accessibility|ccpa|loginanomaly|coppa|password_breach"
```
Every line should end in "Ran". If any show "Pending", run
`php artisan migrate`.

### S17 — Compliance audit-log spot-checks

After a representative day's traffic:
```sql
-- Settings + compliance events written by every module
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
You should see a healthy mix of write events. No event type should be
unexpectedly silent (e.g. `cookieconsent.save` at zero suggests the
banner isn't recording; `pii.viewed` at zero suggests admins haven't
visited any PII surfaces or the toggle is off).

```sql
-- Verify the new chain columns are populating on writes
SELECT id, action, prev_hash IS NULL AS chain_missing
FROM audit_log
WHERE created_at > NOW() - INTERVAL 1 HOUR
  AND prev_hash IS NULL
LIMIT 20;
```
Should return zero rows if `auditchain` is installed + migrated. A
row with `chain_missing=1` means an audit_log writer is bypassing
`AuditChainService::sealAndInsert` — review and patch.

### S14 — Final summary for the developer

If you finished the browser pass and the shell pass, paste this block back
to the developer with your notes:

```
QA pass completed: <date>
Browser sections: 0 1 2 3 4 5 5.5 6
Shell sections: S1 S2 S3 S4 S5 S7 S8 S10 S11 S12 S13 S15 S16 S17
Failures observed:
  - <section>.<step>: <one-line symptom>
  - ...
Logs at tail of php_error.log: <paste last 20 lines>
Compliance audit-log spot (paste output of S17 first query):
  - <action>: <count>
  - ...
```

---

## Notes on execution

- The browser section is sequenced so each step's setup is satisfied by the
  step before it. Don't skip ahead to a moderation step without first
  generating the comment/review/report it moderates.
- Use **three Chrome profiles**, not three incognito windows. Cookies
  survive between tasks that way.
- For commerce + subscriptions, use Stripe **test mode** end-to-end. The
  card `4242 4242 4242 4242` always succeeds. Card `4000 0000 0000 9995`
  always fails — handy for testing dunning.
- When something fails, the **audit log viewer** (`/admin/audit-log`) is
  usually the fastest way to understand what actually happened. Most
  auth/permission failures leave audit trails before you reach the shell.
- The exhaustive per-module reference lives in `QA_CHECKLIST.md`. If a
  step in this process surprises you, open the matching subsection there
  for the deeper "what should the row look like in the DB" detail.
