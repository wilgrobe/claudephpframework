<?php
// routes/web.php

use App\Middleware\{AuthMiddleware, GuestMiddleware, CsrfMiddleware, RequireAdmin, RequireSuperadmin};

/** @var \Core\Router\Router $router */

// ── Search & Autocomplete API ─────────────────────────────────────────────────

// Username availability + suggestions — used by the registration form
// to surface live availability + alternatives. Public (no auth) since
// it's pre-registration; it only ever returns booleans + suggestion
// strings, no user data. Lightly rate-limited via the existing IP
// throttling on POST endpoints; this is GET so the surface is mostly
// caching-friendly to a CDN with a short TTL.
$router->get('/api/users/check-username', function (\Core\Request $req) {
    $u     = trim((string) $req->query('u', ''));
    $email = trim((string) $req->query('email', ''));
    $first = trim((string) $req->query('first', ''));
    $last  = trim((string) $req->query('last', ''));

    $svc   = new \Core\Services\UsernameSuggester();

    $valError = $u !== '' ? $svc->validate($u) : null;
    $available = $valError === null && $svc->isAvailable($u);

    // Generate suggestions in two cases: no username typed yet
    // (give the user something to start from) OR the typed value
    // collided / is invalid (give them alternatives).
    $suggestions = ($u === '' || !$available || $valError !== null)
        ? $svc->suggest($email !== '' ? $email : null,
                        $first !== '' ? $first : null,
                        $last  !== '' ? $last  : null,
                        5)
        : [];

    return \Core\Response::json([
        'username'    => $u,
        'available'   => $available,
        'error'       => $valError,
        'suggestions' => $suggestions,
    ]);
});

// User search (JSON) — used by group invite flow to find platform users
$router->get('/api/users/search', function (\Core\Request $req) {
    if (\Core\Auth\Auth::getInstance()->guest()) {
        return \Core\Response::json(['error' => 'Unauthenticated'], 401);
    }
    $q  = trim($req->query('q', ''));
    if (strlen($q) < 2) return \Core\Response::json([]);
    $db = \Core\Database\Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT id, username, email, first_name, last_name FROM users
         WHERE is_active = 1
           AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
         LIMIT 10",
        ["%$q%", "%$q%", "%$q%", "%$q%"]
    );
    // Never expose password hashes or sensitive fields in search results
    return \Core\Response::json(array_map(fn($u) => [
        'id'    => $u['id'],
        'name'  => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
        'email' => $u['email'],
    ], $rows));
}, [\App\Middleware\AuthMiddleware::class]);

// Content search — routes through SearchService so flipping SEARCH_PROVIDER
// actually takes effect for end users. When disabled, SearchService falls
// back to MySQL FULLTEXT (fallbackSearch) and the hit shape is identical, so
// the view doesn't branch on provider.
$router->get('/search', function (\Core\Request $req) {
    $q    = trim($req->query('q', ''));
    $auth = \Core\Auth\Auth::getInstance();
    /** @var \Core\Services\SearchService $search */
    $search  = app(\Core\Services\SearchService::class);
    $results = [];

    if (strlen($q) >= 2) {
        $results['content'] = $search->search('content', $q, 10);
        $results['pages']   = $search->search('pages',   $q, 5);
        $results['faqs']    = $search->search('faqs',    $q, 5);
    }

    // Page-chrome Batch C: fragment + chrome wrap. Slug `search`
    // mirrors the URL. Admin layout at /admin/system-layouts/search.
    return \Core\Response::view('public.search', [
        'q'       => $q,
        'results' => $results,
        'user'    => $auth->user(),
    ])->withLayout('search');
});

// ── Uploads (served from storage, outside web root) ────────────────────────────

$router->get('/uploads/{folder}/{file}',           'UploadsController@serve');
$router->get('/uploads/{folder}/{sub}/{file}',     'UploadsController@serve');

// ── Sitemap & SEO ─────────────────────────────────────────────────────────────

$router->get('/sitemap.xml', 'SitemapController@index');

// ── Public / Guest ────────────────────────────────────────────────────────────

// Legacy /page/{slug} 301 and all /admin/pages/* routes have moved to the
// Pages module at modules/pages/routes.php (loaded by ModuleRegistry before
// this file). The /faq public page and /admin/faqs/* admin routes have
// moved to modules/faq/routes.php.

// Homepage. Logged-in users go to their dashboard. Guests see the page
// chosen in admin settings (stored as `guest_home_page_slug` in the site
// settings scope) — if set, published, and is_public. Otherwise fall
// through to /login, preserving the pre-existing behavior.
$router->get('/', function (\Core\Request $req) {
    $auth = \Core\Auth\Auth::getInstance();
    if ($auth->check()) {
        return \Core\Response::redirect('/dashboard');
    }

    $homeSlug = setting('guest_home_page_slug', '');
    if ($homeSlug) {
        $page = \Core\Database\Database::getInstance()->fetchOne(
            "SELECT * FROM pages WHERE slug = ? AND status = 'published' AND is_public = 1 LIMIT 1",
            [$homeSlug]
        );
        if ($page) {
            return \Core\Response::view('public.page', ['page' => $page, 'user' => null]);
        }
    }
    return \Core\Response::redirect('/login');
});

// ── Notifications ─────────────────────────────────────────────────────────────

// Notifications moved to modules/notifications/routes.php

// ── Security / Discovery files ────────────────────────────────────────────────

// These are served as static files by nginx/Apache from /public/.well-known/
// The PHP route is a fallback for servers without static file handling.
$router->get('/.well-known/security.txt', function (\Core\Request $req) {
    $path = BASE_PATH . '/public/.well-known/security.txt';
    $body = file_exists($path) ? file_get_contents($path) : '';
    return new \Core\Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

// ── Profile ───────────────────────────────────────────────────────────────────

// /profile and /profile/edit moved to modules/profile/routes.php
// /profile/2fa/* stays below — it's handled by core TwoFactorController.

// ── 2FA — Challenge (called during login, no full auth required) ──────────────

$router->get('/auth/2fa/challenge',        'TwoFactorController@showChallenge');
$router->post('/auth/2fa/challenge',       'TwoFactorController@verifyChallenge',  [CsrfMiddleware::class]);
$router->post('/auth/2fa/resend',          'TwoFactorController@resendCode',       [CsrfMiddleware::class]);
$router->get('/auth/2fa/recovery',         'TwoFactorController@showRecoveryForm');
$router->post('/auth/2fa/recovery',        'TwoFactorController@verifyRecovery',   [CsrfMiddleware::class]);

// ── 2FA — Setup (requires full auth) ─────────────────────────────────────────

$router->get('/profile/2fa',                  'TwoFactorController@settingsPage',           [AuthMiddleware::class]);
$router->get('/profile/2fa/setup',            'TwoFactorController@setupForm',              [AuthMiddleware::class]);
$router->post('/profile/2fa/enable',          'TwoFactorController@enableMethod',           [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/profile/2fa/confirm-totp',    'TwoFactorController@confirmTotp',            [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get('/profile/2fa/disable',          'TwoFactorController@disableForm',            [AuthMiddleware::class]);
$router->post('/profile/2fa/disable',         'TwoFactorController@disable',                [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get('/profile/2fa/recovery-codes',   'TwoFactorController@recoveryCodes',          [AuthMiddleware::class]);
$router->post('/profile/2fa/recovery-codes',  'TwoFactorController@regenerateRecoveryCodes',[CsrfMiddleware::class, AuthMiddleware::class]);

// ── Auth ──────────────────────────────────────────────────────────────────────

$router->get('/login',                      'AuthController@showLogin',        [GuestMiddleware::class]);
$router->post('/login',                     'AuthController@login',            [CsrfMiddleware::class, GuestMiddleware::class]);
$router->post('/dev/login-as',              'AuthController@devLoginAs',       [CsrfMiddleware::class, GuestMiddleware::class]);
$router->get('/register',                   'AuthController@showRegister',     [GuestMiddleware::class]);
$router->post('/register',                  'AuthController@register',         [CsrfMiddleware::class, GuestMiddleware::class]);
$router->post('/logout',                    'AuthController@logout',           [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get('/password/forgot',            'AuthController@showForgotPassword');
$router->post('/password/forgot',           'AuthController@sendPasswordReset',[CsrfMiddleware::class]);
$router->get('/password/reset',             'AuthController@showResetPassword');
$router->post('/password/reset',            'AuthController@resetPassword',    [CsrfMiddleware::class]);
// /verify-email intentionally has NO AuthMiddleware. The whole point of
// the link is to verify the address BEFORE the user can sign in — when
// require_email_verify is on, they CAN'T be authenticated yet. The
// 256-bit single-use token in the URL is the proof of identity.
$router->get('/verify-email',               'AuthController@verifyEmail');
$router->post('/auth/resend-verification',  'AuthController@resendVerification',[CsrfMiddleware::class, AuthMiddleware::class]);

// OAuth
$router->get('/auth/oauth/{provider}',              'AuthController@oauthRedirect');
$router->get('/auth/oauth/{provider}/callback',     'AuthController@oauthCallback');

// Superadmin mode toggle & emulation
$router->post('/admin/superadmin/toggle-mode',      'AuthController@toggleSuperadminMode', [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/admin/users/{id}/emulate',          'AuthController@startEmulate',         [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/admin/emulate/stop',                'AuthController@stopEmulate',          [CsrfMiddleware::class, AuthMiddleware::class]);

// ── Dashboard ─────────────────────────────────────────────────────────────────

$router->get('/dashboard', 'DashboardController@index', [AuthMiddleware::class]);

// ── Groups (user-facing) ──────────────────────────────────────────────────────
// Moved to modules/groups/routes.php (along with all owner-removal and
// per-group role routes).

// ── Admin: Users ──────────────────────────────────────────────────────────────

$router->get('/admin/users',               'UserController@index',  [AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/users/create',        'UserController@create', [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/users/create',       'UserController@store',  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/users/{id}',          'UserController@show',   [AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/users/{id}/edit',     'UserController@edit',   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/users/{id}/edit',    'UserController@update', [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/users/{id}/delete',  'UserController@delete', [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/users/{id}/resend-verification', 'UserController@resendVerification', [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
// Mark verified — superadmin only. Bypasses the email-click flow entirely
// (sets users.email_verified_at = NOW()), so it's gated tighter than the
// resend-verification action. Audit-logged in the controller.
$router->post('/admin/users/{id}/mark-verified', 'UserController@markEmailVerified', [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);

// ── Admin: Sessions ───────────────────────────────────────────────────────────
// Active-session oversight backed by the `sessions` table (written by
// Core\Session\DbSessionHandler). List + individual terminate + kick-all-
// for-user. All actions audit-logged.
$router->get ('/admin/sessions',                              'SessionAdminController@index',              [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/sessions/{id}/terminate',               'SessionAdminController@terminate',          [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/sessions/user/{userId}/terminate-all',  'SessionAdminController@terminateAllForUser',[CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// ── Account: Sessions ─────────────────────────────────────────────────────────
// User-facing "my active devices". Setting-gated: returns 404 when
// account_sessions_enabled is off. No admin perm required.
$router->get ('/account/sessions',              'AccountSessionsController@index',     [AuthMiddleware::class]);
$router->post('/account/sessions/{id}/terminate','AccountSessionsController@terminate', [CsrfMiddleware::class, AuthMiddleware::class]);

// ── Admin: Roles + Admin: Groups ──────────────────────────────────────────────
// Both moved to modules/groups/routes.php (Roles is part of the Groups module
// because the two are tightly coupled via the permissions schema).

// ── Admin: Menus ──────────────────────────────────────────────────────────────

// Menus moved to modules/menus/routes.php

// ── Admin: Pages ──────────────────────────────────────────────────────────────
// Moved to modules/pages/routes.php

// ── Admin: FAQ ────────────────────────────────────────────────────────────────
// Moved to modules/faq/routes.php

// ── Admin: Settings ───────────────────────────────────────────────────────────

// Site settings are superadmin-only. Regular role-based admins can manage
// users, groups, pages, etc., but tuning the site itself (footer, group
// policy, etc.) is reserved for superadmins.
// Settings moved to modules/settings/routes.php

// ── Admin: Integrations ───────────────────────────────────────────────────────

// Integrations are now .env-driven and read-only in the UI. Only index
// (the status dashboard) and test (a live probe of the active provider)
// are routable; create/edit/delete are gone — superadmins edit .env directly.
// Integrations moved to modules/integrations/routes.php

// ── Superadmin Panel ──────────────────────────────────────────────────────────

$router->get('/admin/superadmin',                       'Admin\SuperadminController@dashboard',           [AuthMiddleware::class]);
$router->get('/admin/superadmin/users',                 'Admin\SuperadminController@users',               [AuthMiddleware::class]);
$router->post('/admin/superadmin/users/{id}/superadmin','Admin\SuperadminController@toggleUserSuperadmin',[CsrfMiddleware::class, AuthMiddleware::class]);
$router->get('/admin/superadmin/audit-log',             'Admin\SuperadminController@auditLog',            [AuthMiddleware::class]);
$router->get('/admin/superadmin/message-log',           'Admin\SuperadminController@messageLog',          [AuthMiddleware::class]);
$router->post('/admin/superadmin/message-log/{id}/retry','Admin\SuperadminController@retryMessage',        [CsrfMiddleware::class, AuthMiddleware::class]);

// ── Admin: Modules ────────────────────────────────────────────────────────────
// Roster of every discovered module under modules/, with current state +
// declared dependencies. Read-only in this batch; future passes will add
// admin-disable + repair actions.
$router->get ('/admin/modules',                'ModuleAdminController@index',   [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/modules/{name}/disable', 'ModuleAdminController@disable', [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/modules/{name}/enable',  'ModuleAdminController@enable',  [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);

// ── Admin: System Layouts ─────────────────────────────────────────────────────
// Editor for layouts attached to non-page surfaces (dashboard, etc).
// Placement form shape matches the per-page layout editor; views are
// thin wrappers + reuse modules/pages/Views/admin/_layout_row.php.
$router->get ('/admin/system-layouts',          'SystemLayoutAdminController@index', [AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/system-layouts/{name}',   'SystemLayoutAdminController@edit',  [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/system-layouts/{name}',   'SystemLayoutAdminController@save',  [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);

// ── Content ───────────────────────────────────────────────────────────────────
// Moved to modules/content/routes.php

// ── Public page + SEO catch-all (must be last) ────────────────────────────────
//
// One-segment URLs fall through here after every specific route above has
// declined. We try things in order of likelihood:
//   1. A published Page with this slug — render inline
//   2. An seo_links entry — honor its 301 redirect
//   3. 404
//
// Reserved/overloaded slugs: any string that collides with a specific
// route above (e.g., "dashboard", "login", "admin") never reaches this
// handler because the specific route matches first. That's by design.
$router->get('/{slug}', function (\Core\Request $req) {
    $slug = $req->param(0);
    $auth = \Core\Auth\Auth::getInstance();
    $db   = \Core\Database\Database::getInstance();

    $page = $db->fetchOne(
        "SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1",
        [$slug]
    );
    if ($page && ((int)$page['is_public'] === 1 || !$auth->guest())) {
        return \Core\Response::view('public.page', ['page' => $page, 'user' => $auth->user()]);
    }

    $seo      = new \Core\SEO\SeoManager();
    $resolved = $seo->resolve($slug);
    if (!$resolved) {
        return new \Core\Response('Page not found', 404);
    }
    if ($resolved['redirect']) {
        return \Core\Response::redirect($resolved['redirect_to'], 301);
    }
    // Canonical-path redirect — only fire when the canonical actually
    // differs from the requested URL. Without this guard, a published
    // page with is_public=0 viewed by a guest produces an infinite
    // redirect loop: page row exists, access check fails, SEO has the
    // canonical /<slug> registered, redirect lands on /<slug> again,
    // repeat. When the canonical IS the current URL and the access
    // check above already failed, 404 is the honest answer.
    $currentPath = '/' . ltrim($slug, '/');
    if ($resolved['path'] === $currentPath) {
        return new \Core\Response('Page not found', 404);
    }
    return \Core\Response::redirect($resolved['path'], 301);
});
