<?php
// core/Auth/Auth.php
namespace Core\Auth;

use Core\Database\Database;

/**
 * Auth — singleton authentication / authorization engine.
 *
 * Supports:
 *  - Standard email+password login
 *  - OAuth social login (Google, Microsoft, Apple, Facebook, LinkedIn)
 *  - Superadmin toggle + user emulation (fully audited)
 *  - Multi-group membership with per-group roles
 *  - Group-scoped permission checks
 */
class Auth
{
    private static ?Auth $instance = null;
    private Database     $db;

    private ?array $user        = null;
    private ?array $roles       = null;   // global system roles
    private ?array $permissions = null;   // global permissions
    private ?array $groups      = null;   // groups with per-group role info

    /** Original (real) user when emulating someone else */
    private ?array $realUser    = null;

    private function __construct()
    {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->bootFromSession();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Session Boot ──────────────────────────────────────────────────────────

    private function bootFromSession(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->loadUser((int) $_SESSION['user_id']);
        }
        if (!empty($_SESSION['real_user_id']) && !empty($_SESSION['emulating'])) {
            $this->realUser = $this->db->fetchOne(
                "SELECT id, username, email, first_name, last_name, is_superadmin
                 FROM users WHERE id = ?",
                [(int) $_SESSION['real_user_id']]
            );
        }
    }

    // ── Login / Logout ────────────────────────────────────────────────────────

    /**
     * Attempt password-based login.
     */
    /**
     * Attempt password-based login.
     *
     * Returns:
     *   true             — login fully complete (no 2FA required)
     *   '2fa_required'   — credentials valid but 2FA challenge needed;
     *                      caller should redirect to /auth/2fa/challenge
     *   false            — invalid credentials
     */
    public function attempt(string $email, string $password): bool|string
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = ? AND is_active = 1",
            [strtolower(trim($email))]
        );
        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            return false;
        }

        // Email-verification gate. Runs BEFORE startSession + 2FA so we
        // don't fire the new-device email, audit log, last_login_at update,
        // or 2FA prompt for users who are about to be bounced anyway.
        // Superadmins are exempt so an admin who toggles the site setting
        // on first doesn't lock themselves out. No side effects yet —
        // caller can flash and redirect without unwinding any state.
        $settings = new \Core\Services\SettingsService();
        if ((bool) $settings->get('require_email_verify', false, 'site')
            && empty($user['is_superadmin'])
            && empty($user['email_verified_at'])
        ) {
            return 'verify_required';
        }

        // Check if 2FA is enabled and confirmed for this user
        if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_confirmed'])) {
            // Store pending user in session — NOT logged in yet
            if (session_status() === PHP_SESSION_NONE) session_start();
            session_regenerate_id(true);
            $_SESSION['2fa_pending_user_id'] = $user['id'];
            $this->auditLog('auth.2fa_initiated', 'users', $user['id'], null, [
                'method' => $user['two_factor_method'] ?? 'unknown',
            ]);
            return '2fa_required';
        }

        $this->startSession($user);
        $this->db->query("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
        $this->auditLog('auth.login', 'users', $user['id']);
        return true;
    }

    /**
     * Login or register via OAuth provider.
     * Returns true on success.
     */
    public function attemptOAuth(string $provider, string $providerId, array $providerData): bool
    {
        $allowed = ['google','microsoft','apple','facebook','linkedin'];
        if (!in_array($provider, $allowed, true)) return false;

        // Try existing OAuth link
        $oauth = $this->db->fetchOne(
            "SELECT user_id FROM user_oauth WHERE provider = ? AND provider_id = ?",
            [$provider, $providerId]
        );

        if ($oauth) {
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE id = ? AND is_active = 1",
                [$oauth['user_id']]
            );
        } else {
            // Check by email first
            $user = null;
            if (!empty($providerData['email'])) {
                $user = $this->db->fetchOne(
                    "SELECT * FROM users WHERE email = ?",
                    [$providerData['email']]
                );
            }
            if (!$user) {
                // Auto-register
                $userId = $this->db->insert('users', [
                    'email'      => $providerData['email']  ?? null,
                    'first_name' => $providerData['first_name'] ?? null,
                    'last_name'  => $providerData['last_name']  ?? null,
                    'avatar'     => $providerData['avatar']     ?? null,
                    'is_active'  => 1,
                    'email_verified_at' => date('Y-m-d H:i:s'),
                ]);
                // Assign default viewer role
                $viewerRole = $this->db->fetchOne("SELECT id FROM roles WHERE slug = 'viewer'");
                if ($viewerRole) {
                    $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $viewerRole['id']]);
                }
                $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            }
            // Link provider — encrypt the access token at rest
            $this->db->insert('user_oauth', [
                'user_id'     => $user['id'],
                'provider'    => $provider,
                'provider_id' => $providerId,
                'token'       => $providerData['token'] ? $this->encryptToken($providerData['token']) : null,
            ]);
        }

        if (!$user || !$user['is_active']) return false;

        // SECURITY: OAuth must also respect 2FA — do not bypass it.
        // Fetch full user row (SELECT * earlier may not include 2FA fields).
        $fullUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$user['id']]);
        if ($fullUser && !empty($fullUser['two_factor_enabled']) && !empty($fullUser['two_factor_confirmed'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            session_regenerate_id(true);
            $_SESSION['2fa_pending_user_id'] = $fullUser['id'];
            $_SESSION['2fa_oauth_provider']  = $provider; // for audit on completion
            $this->auditLog('auth.2fa_initiated', 'users', $fullUser['id'], null, [
                'method'   => $fullUser['two_factor_method'] ?? 'unknown',
                'via'      => 'oauth',
                'provider' => $provider,
            ]);
            return '2fa_required';
        }

        $this->startSession($user);
        $this->db->query("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
        $this->auditLog('auth.oauth_login', 'users', $user['id'], null, ['provider' => $provider]);
        return true;
    }

    /**
     * Validate and return a safe internal redirect path.
     * Rejects absolute URLs, protocol-relative URLs, and anything
     * that would take the user off-site.
     *
     * SECURITY: Prevents open-redirect via the 'intended' session value.
     */
    public static function safeRedirect(string $url, string $default = '/dashboard'): string
    {
        // Must start with exactly one slash (internal path), not // or http://
        if (!preg_match('#^/[^/]#', $url) && $url !== '/') {
            return $default;
        }
        // Strip null bytes and whitespace
        $url = trim(str_replace("\0", '', $url));
        // Reject anything that still looks like an absolute URL after trimming
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $url)) {
            return $default;
        }
        return $url;
    }

    public function logout(): void
    {
        if ($this->user) {
            $this->auditLog('auth.logout', 'users', $this->user['id']);
        }
        $this->user = $this->realUser = $this->roles = $this->permissions = $this->groups = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            // Array form of setcookie includes 'samesite', which the
            // positional form can't express. Browsers treat the
            // SameSite attribute as part of cookie identity, so a
            // deletion cookie without it doesn't overwrite a Lax-set
            // original — the browser ends up showing the old cookie
            // as "still there" until it eventually expires on its own.
            // Mirroring every attribute here makes logout visibly
            // clean in devtools.
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    // ── Superadmin Toggle ─────────────────────────────────────────────────────

    /**
     * Toggle superadmin mode.
     *
     * SECURITY: Mode is tracked only in the PHP session, NOT in the database.
     * This ensures that destroying all sessions (e.g. during an incident)
     * automatically revokes any active superadmin mode — no DB state to reset.
     * The DB column superadmin_mode should be dropped via migration.
     */
    public function toggleSuperadminMode(bool $enable): bool
    {
        if (!$this->user || !$this->user['is_superadmin']) return false;
        // Session-only storage — never write to DB
        $_SESSION['superadmin_mode'] = $enable;
        $this->user['_session_superadmin_mode'] = $enable;
        $this->auditLog('superadmin.mode_toggle', 'users', $this->user['id'],
            ['mode' => !$enable], ['mode' => $enable]);
        return true;
    }

    /**
     * Returns true only when:
     *   1. The authenticated user has is_superadmin = 1 (DB capability flag)
     *   2. AND the session toggle is explicitly set to true this session
     *
     * SECURITY: Reading from session only — DB superadmin_mode column is ignored.
     */
    public function isSuperadminModeOn(): bool
    {
        return $this->user
            && $this->user['is_superadmin']
            && !empty($_SESSION['superadmin_mode']);
    }

    // ── User Emulation ────────────────────────────────────────────────────────

    /**
     * Superadmin begins emulating $targetUserId.
     */
    public function startEmulating(int $targetUserId): bool
    {
        if (!$this->isSuperadminModeOn()) return false;
        $target = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$targetUserId]);
        if (!$target) return false;

        // Save real user
        $_SESSION['real_user_id'] = $this->user['id'];
        $_SESSION['emulating']    = true;
        $this->realUser           = $this->user;

        $this->auditLog('superadmin.emulate_start', 'users', $targetUserId);

        $this->startSession($target, false); // don't regenerate session ID to keep real_user_id
        return true;
    }

    public function stopEmulating(): bool
    {
        if (!$this->isEmulating()) return false;
        $this->auditLog('superadmin.emulate_stop', 'users', $this->user['id']);
        $realId = (int) $_SESSION['real_user_id'];
        $_SESSION['emulating']    = false;
        $_SESSION['real_user_id'] = null;
        $this->realUser = null;
        $this->loadUser($realId);
        $_SESSION['user_id'] = $realId;
        return true;
    }

    public function isEmulating(): bool
    {
        return !empty($_SESSION['emulating']) && !empty($_SESSION['real_user_id']);
    }

    public function realUser(): ?array { return $this->realUser; }

    // ── Dev-Only Login Bypass ────────────────────────────────────────────────

    /**
     * Log in as an arbitrary user without credentials. Short-circuits the
     * email/password and OAuth flows entirely — intended for local dev so
     * you don't round-trip through Google/Microsoft for every test login.
     *
     * HARD-GATED: refuses in production or when APP_DEV_LOGIN is not set
     * to '1'. Both guards are belt-and-suspenders: a misconfigured prod
     * env would still need an attacker to hit the /dev/login-as endpoint,
     * which the controller also checks, and the button only renders in
     * non-production.
     */
    public function devLoginAs(int $userId): bool
    {
        if (($_ENV['APP_ENV'] ?? 'production') === 'production') return false;
        if (($_ENV['APP_DEV_LOGIN'] ?? '1') !== '1')             return false;

        $user = $this->db->fetchOne(
            "SELECT id, email, is_active FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user || (int)$user['is_active'] !== 1) return false;

        $this->startSession($user);
        $this->auditLog('auth.dev_login', 'users', (int)$user['id'], null, ['via' => 'dev-bypass']);
        return true;
    }

    // ── Session Helpers ───────────────────────────────────────────────────────

    private function startSession(array $user, bool $regenerate = true): void
    {
        if ($regenerate) session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $this->loadUser($user['id']);

        // New-device detection + email. Runs only during real logins
        // ($regenerate=true), not during emulation start (which calls
        // startSession with regenerate=false — we don't want to email
        // the real user that THEY just logged in from a "new device"
        // when an SA is just emulating them).
        //
        // Detection: query the sessions table for any prior row with
        // this user_id AND this user_agent. If none → new device.
        // Skip entirely for first-ever logins (last_login_at IS NULL)
        // because a fresh account always has zero prior sessions and
        // would spam an email on the welcome-login.
        //
        // Never throw — silent logging on any failure, because login
        // must not be blocked by a mail-server hiccup.
        if ($regenerate) {
            try { $this->notifyOnNewDeviceLogin($user); }
            catch (\Throwable $e) { error_log('[auth] new-device notify failed: ' . $e->getMessage()); }
        }
    }

    /**
     * Send a "new sign-in from unrecognized device" email if:
     *   - site setting new_device_login_email_enabled is true
     *   - the user has prior sessions in the DB (i.e. isn't brand-new)
     *   - no prior session row matches the current User-Agent string
     *
     * User-Agent is stable per browser-install, so the test gives a
     * reasonable "have they signed in from this browser before."
     * Detection runs before the DbSessionHandler persists the current
     * session row, so the query doesn't see the session-in-progress.
     */
    private function notifyOnNewDeviceLogin(array $user): void
    {
        // Respect the site toggle. Default false — admins opt in via
        // /admin/settings/security.
        $settings = new \Core\Services\SettingsService();
        if (!(bool) $settings->get('new_device_login_email_enabled', false, 'site')) return;

        $email = (string) ($user['email'] ?? '');
        if ($email === '') return;

        // Skip first-ever logins — no prior session history means every
        // login looks "new" and would just spam the welcome event.
        if (empty($user['last_login_at'])) return;

        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags((string) $_SERVER['HTTP_USER_AGENT']), 0, 500)
            : '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Has this user had a prior session with this exact UA?
        $known = $this->db->fetchOne(
            "SELECT 1 FROM sessions WHERE user_id = ? AND user_agent = ? LIMIT 1",
            [(int) $user['id'], $ua]
        );
        if ($known) return;

        // New device. Dispatch email — best-effort; failures are
        // swallowed so login isn't interrupted.
        $appName = (string) (config('app.name') ?? 'your account');
        $appUrl  = rtrim((string) (config('app.url') ?? ''), '/');
        $when    = date('Y-m-d H:i:s T');

        $subject = "New sign-in to $appName";
        $html    = $this->newDeviceEmailBody($appName, $appUrl, $when, $ip, $ua);
        $text    = "A new sign-in to $appName was just recorded.\n\n"
                 . "Time: $when\nIP: $ip\nBrowser: $ua\n\n"
                 . "If this was you, nothing to do. If not, change your password "
                 . "and review your active sessions at $appUrl/account/sessions.";

        try {
            (new \Core\Services\MailService())->send($email, $subject, $html, $text);
            $this->auditLog('auth.new_device_email_sent', 'users', (int) $user['id']);
        } catch (\Throwable $e) {
            error_log('[auth] new-device email send failed: ' . $e->getMessage());
        }

        // Anomaly detection — runs on every new-device login when the
        // loginanomaly module is installed AND its master toggle is on.
        // Compares the current login's geo to the user's prior login;
        // sends a separate "Suspicious sign-in" email when impossible
        // travel is detected. Best-effort throughout — never blocks
        // the login, never throws.
        $this->maybeRunLoginAnomalyDetection($user, $ip, $ua, $email, $appName, $appUrl, $when);
    }

    /**
     * Optional follow-up to the new-device email path. Calls into the
     * loginanomaly module when present + enabled; logs an anomaly row
     * + emails the user on impossible-travel / country-jump findings.
     */
    private function maybeRunLoginAnomalyDetection(array $user, string $ip, string $ua, string $email, string $appName, string $appUrl, string $when): void
    {
        if (!class_exists(\Modules\Loginanomaly\Services\LoginAnomalyService::class)) return;

        try {
            $svc = new \Modules\Loginanomaly\Services\LoginAnomalyService();
            $finding = $svc->analyseLogin((int) $user['id'], $ip, $ua);
            if ($finding === null) return;

            // Email the user on warn/alert. info-level findings are
            // logged but don't email — country jumps happen normally
            // for travelers and would generate noise.
            $sendEmail = in_array($finding['severity'], ['warn', 'alert'], true)
                && (bool) (setting('login_anomaly_email_enabled', true) ?? true);

            if ($sendEmail) {
                $subject = "[" . strtoupper($finding['severity']) . "] Suspicious sign-in to $appName";
                $cur = $finding['current_geo'] ?? [];
                $pri = $finding['prior_geo']   ?? [];
                $body = "A sign-in to $appName was just recorded that looks suspicious.\n\n"
                      . "Time: $when\n"
                      . "From: " . ($cur['city'] ?? '?') . ", " . ($cur['country_code'] ?? '?') . " (IP: $ip)\n"
                      . "Previous sign-in: " . ($pri['city'] ?? '?') . ", " . ($pri['country_code'] ?? '?') . "\n"
                      . "Distance: " . (int) ($finding['distance_km'] ?? 0) . " km\n"
                      . "Implied speed: " . (int) ($finding['implied_kmh'] ?? 0) . " km/h\n\n"
                      . "If this was you (e.g. you're using a VPN or just landed somewhere new),\n"
                      . "no action is needed. If not, sign in to $appUrl/account/sessions and\n"
                      . "terminate any sessions you don't recognise, then change your password.";
                $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111">'
                      . '<h2 style="margin:0 0 .5rem 0">⚠️ Suspicious sign-in</h2>'
                      . '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5)) . '</p></div>';

                try {
                    (new \Core\Services\MailService())->send($email, $subject, $html, $body);
                    $svc->markActionTaken((int) $finding['anomaly_id'], 'emailed_user');
                } catch (\Throwable $e) {
                    error_log('[loginanomaly] alert email send failed: ' . $e->getMessage());
                    $svc->markActionTaken((int) $finding['anomaly_id'], 'email_failed');
                }
            }

            $this->auditLog('auth.login_anomaly_detected', 'login_anomalies', (int) $finding['anomaly_id'], null, [
                'severity'    => $finding['severity'],
                'rule'        => $finding['rule'],
                'implied_kmh' => $finding['implied_kmh'],
                'distance_km' => $finding['distance_km'],
            ]);
        } catch (\Throwable $e) {
            error_log('[auth] login anomaly check failed: ' . $e->getMessage());
        }
    }

    private function newDeviceEmailBody(string $appName, string $appUrl, string $when, string $ip, string $ua): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sessionsLink = $appUrl !== '' ? $appUrl . '/account/sessions' : '/account/sessions';
        return '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111">'
             . '<h2 style="margin:0 0 .5rem 0">New sign-in to ' . $esc($appName) . '</h2>'
             . '<p>We noticed a sign-in from a device we haven\'t seen on your account before.</p>'
             . '<table cellpadding="6" style="background:#f9fafb;border-radius:6px;font-size:13px;color:#374151">'
             . '<tr><td><strong>When</strong></td><td>' . $esc($when) . '</td></tr>'
             . '<tr><td><strong>IP address</strong></td><td>' . $esc($ip) . '</td></tr>'
             . '<tr><td><strong>Device</strong></td><td>' . $esc($ua) . '</td></tr>'
             . '</table>'
             . '<p style="margin-top:1rem">If this was you, you can ignore this email.</p>'
             . '<p>If this wasn\'t you, change your password immediately and review your '
             . '<a href="' . $esc($sessionsLink) . '">active sessions</a>.</p>'
             . '</div>';
    }

    private function loadUser(int $userId): void
    {
        $this->user = $this->db->fetchOne(
            "SELECT id, username, email, first_name, last_name, avatar, bio,
                    is_active, is_superadmin,
                    email_verified_at, phone, last_login_at, created_at
             FROM users WHERE id = ?",
            [$userId]
        );
        if (!$this->user) { $this->logout(); return; }

        $this->loadRoles();
        $this->loadPermissions();
        $this->loadGroups();
    }

    private function loadRoles(): void
    {
        $this->roles = $this->db->fetchAll(
            "SELECT r.id, r.name, r.slug, r.description
             FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ?",
            [$this->user['id']]
        );
    }

    private function loadPermissions(): void
    {
        $this->permissions = $this->db->fetchAll(
            "SELECT DISTINCT p.id, p.slug
             FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?",
            [$this->user['id']]
        );
    }

    private function loadGroups(): void
    {
        $this->groups = $this->db->fetchAll(
            "SELECT g.id, g.name, g.slug, gr.slug AS group_role_slug,
                    gr.base_role, gr.name AS group_role_name, gr.id AS group_role_id,
                    ug.joined_at
             FROM `groups` g
             JOIN user_groups ug ON ug.group_id = g.id
             JOIN group_roles gr ON gr.id = ug.group_role_id
             WHERE ug.user_id = ?",
            [$this->user['id']]
        );
    }

    // ── Authorization Checks ──────────────────────────────────────────────────

    public function check(): bool  { return $this->user !== null; }
    public function guest(): bool  { return !$this->check(); }
    public function user(): ?array { return $this->user; }
    public function id(): ?int     { return $this->user ? (int) $this->user['id'] : null; }

    /** Superadmin with mode ON bypasses all permission checks. */
    public function isSuperAdmin(): bool
    {
        return $this->user && $this->user['is_superadmin'];
    }

    public function hasRole(string|array $roles): bool
    {
        if ($this->isSuperadminModeOn()) return true;
        if (!$this->roles) return false;
        $slugs = array_column($this->roles, 'slug');
        foreach ((array) $roles as $r) {
            if (in_array($r, $slugs, true)) return true;
        }
        return false;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperadminModeOn()) return true;
        if (!$this->permissions) return false;
        return in_array($permission, array_column($this->permissions, 'slug'), true);
    }

    public function can(string $permission): bool    { return $this->hasPermission($permission); }
    public function cannot(string $permission): bool { return !$this->can($permission); }

    public function inGroup(string $groupSlug): bool
    {
        // Silent predicate — used by views to toggle member-only UI.
        // SA-mode returns true without logging so repeated view-time
        // checks don't spam audit_log. Gates that let a non-member
        // *through* on the basis of SA-mode log via groupAccessCheck.
        if ($this->canBypassScopes()) return true;
        if (!$this->groups) return false;
        return in_array($groupSlug, array_column($this->groups, 'slug'), true);
    }

    /**
     * Silent group-membership predicate by group id. SA-mode returns
     * true without logging. Suitable for conditional rendering.
     *
     * For HTTP gates that should emit an audit-log row when SA bypass
     * is actually used, call `groupAccessCheck` instead.
     */
    public function isGroupMember(int $groupId): bool
    {
        if ($this->canBypassScopes()) return true;
        return $this->isDirectMemberOfGroup($groupId);
    }

    /** Internal: membership check that ignores SA-mode. */
    private function isDirectMemberOfGroup(int $groupId): bool
    {
        if (!$this->groups) return false;
        foreach ($this->groups as $g) {
            if ((int) $g['id'] === $groupId) return true;
        }
        return false;
    }

    /**
     * Access-gate check for a group. Returns one of:
     *   'member'  — user is directly a member (pass silently)
     *   'bypass'  — user isn't a member but SA-mode granted access;
     *               an audit_log row is written with the $context tag
     *   'denied'  — neither; caller should 404 / redirect
     *
     * Use from controllers that previously did raw `user_groups`
     * SELECTs:
     *
     *   if ($this->auth->groupAccessCheck($gid, 'social.group_feed') === 'denied') {
     *       return Response::redirect('/groups/' . $slug);
     *   }
     *
     * The `bypass` branch logs exactly once per crossed boundary per
     * request — silent helpers don't fire this path even when SA-mode
     * is on, keeping the audit trail forensically useful rather than
     * drowning in noise.
     */
    public function groupAccessCheck(int $groupId, string $context): string
    {
        if ($this->isDirectMemberOfGroup($groupId)) return 'member';
        if ($this->canBypassScopes()) {
            $this->auditBypass($context, 'groups', $groupId);
            return 'bypass';
        }
        return 'denied';
    }

    /**
     * True when the current session should be allowed to step outside
     * normal scope restrictions — group membership, conversation
     * participation, owner-gated private views. Currently this is
     * exclusively superadmin mode; the method exists as a semantic
     * layer so call sites read as "can I bypass scope?" rather than
     * "am I in superadmin mode?" — scope bypass may grow more
     * conditions later (delegated support, break-glass mode, etc.)
     * without touching every consumer.
     */
    public function canBypassScopes(): bool
    {
        return $this->isSuperadminModeOn();
    }

    /**
     * Log a scope-bypass event. Called from every gate that grants
     * access via canBypassScopes() so the audit trail carries a row
     * for each crossed boundary. Keeps bypass usage forensically
     * reviewable without turning on DB-level query logging.
     *
     * Kept lightweight — reuses auditLog for a single row per bypass.
     *
     * @param string $reason  short code identifying the bypassed gate
     *                        (e.g. 'group_membership', 'dm_participant')
     * @param string|null $model   entity the bypass accessed (e.g. 'groups')
     * @param int|null    $modelId entity id (the group_id, conversation_id, etc.)
     * @param array       $extras  optional context merged into notes
     */
    public function auditBypass(string $reason, ?string $model = null, ?int $modelId = null, array $extras = []): void
    {
        // No-op if no authenticated user — no point logging.
        if (!$this->user) return;

        $notes = $reason;
        if (!empty($extras)) {
            $notes .= ' ' . json_encode($extras, JSON_UNESCAPED_SLASHES);
        }
        $this->auditLog('scope.bypass', $model, $modelId, null, null, $notes);
    }

    /**
     * Check if user has a specific base_role within a given group.
     * Hierarchy: group_owner > group_admin > manager > editor > member
     */
    public function hasGroupRole(int $groupId, string $minBaseRole): bool
    {
        if ($this->isSuperadminModeOn()) return true;
        if (!$this->groups) return false;

        $hierarchy = ['group_owner' => 5, 'group_admin' => 4, 'manager' => 3, 'editor' => 2, 'member' => 1];
        $minLevel  = $hierarchy[$minBaseRole] ?? 0;

        foreach ($this->groups as $g) {
            if ((int) $g['id'] === $groupId) {
                $userLevel = $hierarchy[$g['base_role']] ?? 0;
                return $userLevel >= $minLevel;
            }
        }
        return false;
    }

    public function isGroupOwner(int $groupId): bool  { return $this->hasGroupRole($groupId, 'group_owner'); }
    public function isGroupAdmin(int $groupId): bool   { return $this->hasGroupRole($groupId, 'group_admin'); }

    public function roles(): array       { return $this->roles      ?? []; }
    public function permissions(): array { return $this->permissions ?? []; }
    public function groups(): array      { return $this->groups      ?? []; }

    public function refreshUser(): void
    {
        if ($this->user) $this->loadUser($this->user['id']);
    }

    // ── Token Encryption Helpers ──────────────────────────────────────────────

    /**
     * Encrypt a short string (OAuth token) using libsodium.
     * Same scheme as IntegrationService: base64(nonce . ciphertext).
     */
    private function encryptToken(string $token): string
    {
        $key   = $this->deriveKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = sodium_crypto_secretbox($token, $nonce, $key);
        sodium_memzero($key);
        return base64_encode($nonce . $ct);
    }

    public function decryptToken(string $encoded): ?string
    {
        try {
            $raw    = base64_decode($encoded, true);
            $nLen   = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (!$raw || strlen($raw) < $nLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return null;
            $key    = $this->deriveKey();
            $plain  = sodium_crypto_secretbox_open(substr($raw, $nLen), substr($raw, 0, $nLen), $key);
            sodium_memzero($key);
            return $plain === false ? null : $plain;
        } catch (\Throwable) {
            return null;
        }
    }

    private function deriveKey(): string
    {
        $appKey = config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }
        return hash('sha256', $appKey, true);
    }

    // ── Audit Helper ──────────────────────────────────────────────────────────

    public function auditLog(
        string  $action,
        ?string $model    = null,
        ?int    $modelId  = null,
        ?array  $oldVals  = null,
        ?array  $newVals  = null,
        ?string $notes    = null
    ): void {
        // SECURITY: Truncate User-Agent to prevent log injection and storage bloat.
        // A client can send arbitrarily large headers; cap at 500 chars.
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags($_SERVER['HTTP_USER_AGENT']), 0, 500)
            : null;

        $row = [
            'actor_user_id'    => $this->user['id'] ?? null,
            'emulated_user_id' => $this->isEmulating() ? (int) $_SESSION['real_user_id'] : null,
            'superadmin_mode'  => $this->isSuperadminModeOn() ? 1 : 0,
            'action'           => $action,
            'model'            => $model,
            'model_id'         => $modelId,
            'old_values'       => $oldVals ? json_encode($oldVals) : null,
            'new_values'       => $newVals ? json_encode($newVals) : null,
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'       => $userAgent,
            'notes'            => $notes,
        ];

        // Route through AuditChainService when present so the row is
        // sealed with prev_hash + row_hash for tamper detection. The
        // service falls back to a plain insert if its migration hasn't
        // run, so this stays safe on a fresh install.
        if (class_exists(\Modules\Auditchain\Services\AuditChainService::class)) {
            try {
                (new \Modules\Auditchain\Services\AuditChainService($this->db))
                    ->sealAndInsert($this->db, $row);
                return;
            } catch (\Throwable $e) {
                // Chain seal must never block the audit write itself —
                // fall through to plain insert so the row still lands.
                error_log('Auth::auditLog chain seal failed: ' . $e->getMessage());
            }
        }

        $this->db->insert('audit_log', $row);
    }
}
