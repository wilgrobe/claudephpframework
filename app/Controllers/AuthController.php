<?php
// app/Controllers/AuthController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Auth\RateLimiter;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Services\MailService;
use Core\Database\Database;
use App\Models\User;

class AuthController
{
    private Auth        $auth;
    private Database    $db;
    private RateLimiter $limiter;

    public function __construct()
    {
        $this->auth    = Auth::getInstance();
        $this->db      = Database::getInstance();
        $this->limiter = new RateLimiter();
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) return Response::redirect('/dashboard');

        // In non-production environments, surface up to 6 active users as
        // one-click sign-in options. Saves the round-trip through email/
        // password or real OAuth every time you test a login-adjacent flow.
        $devUsers = [];
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production'
            && ($_ENV['APP_DEV_LOGIN'] ?? '1') === '1') {
            $devUsers = $this->db->fetchAll(
                "SELECT id, email, first_name, last_name, is_superadmin
                   FROM users
                  WHERE is_active = 1
                  ORDER BY is_superadmin DESC, id ASC
                  LIMIT 6"
            );
        }

        return Response::view('auth.login', [
            'oauth_providers' => $this->enabledOAuthProviders(),
            'csrf'            => csrf_token(),
            'dev_users'       => $devUsers,
        ]);
    }

    public function login(Request $request): Response
    {
        $ip    = $request->ip();
        $email = strtolower(trim($request->post('email', '')));

        // SECURITY: Check rate limit before any credential validation.
        // This blocks brute-force and password-spraying attacks.
        if ($this->limiter->tooManyAttempts($email, $ip)) {
            $wait = $this->limiter->availableInSeconds($email, $ip);
            Session::flash('errors', ['email' => [
                'Too many login attempts. Please wait ' . self::formatDuration($wait) . ' before trying again.'
            ]]);
            return Response::redirect('/login');
        }

        $v = new Validator($request->post());
        $v->validate(['email' => 'required|email', 'password' => 'required']);

        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            Session::flash('old', ['email' => $v->get('email')]);
            return Response::redirect('/login');
        }

        // CAPTCHA gate (no-op when CAPTCHA_PROVIDER is 'none' or unset).
        // Runs BEFORE credential check so bots can't probe valid accounts
        // even when they submit correct passwords.
        $token = \Core\Services\CaptchaService::tokenFromRequest($request->post());

        // Release the session lock before the synchronous HTTP round-trip to
        // Cloudflare/Google/hCaptcha. Otherwise a slow captcha provider
        // blocks every other request on the same session (AJAX polls, other
        // tabs). We restart the session after so Session::flash below and
        // the downstream auth->attempt() can write back normally.
        $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($sessionWasActive) session_write_close();
        $captchaOk = \Core\Services\CaptchaService::verify($token, $ip);
        if ($sessionWasActive) session_start();

        if (!$captchaOk) {
            Session::flash('errors', ['email' => ['Please complete the security challenge and try again.']]);
            Session::flash('old',    ['email' => $v->get('email')]);
            return Response::redirect('/login');
        }

        $result = $this->auth->attempt($v->get('email'), $request->post('password'));

        if ($result === '2fa_required') {
            // Preserve intended destination so we can redirect after 2FA
            if ($intended = Session::get('intended')) {
                Session::set('intended', $intended);
            }
            return Response::redirect('/auth/2fa/challenge');
        }

        // Email-verification gate. Auth::attempt validates the password,
        // checks the require_email_verify site setting, and short-circuits
        // with this sentinel BEFORE running startSession or notifying the
        // new-device email — so by the time we get here, no session has
        // been set up and no side effects need rolling back. The flash
        // survives the redirect because we never destroyed the session.
        // Don't increment the rate limiter — the password was correct;
        // failing the verify gate isn't a brute-force signal.
        if ($result === 'verify_required') {
            Session::flash('errors', ['email' => [
                'Please verify your email before signing in. Check your inbox for the verification link.'
            ]]);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/login');
        }

        if (!$result) {
            // Record failed attempt for rate limiting
            $this->limiter->hit($email, $ip);
            $remaining = $this->limiter->remainingAttempts($email, $ip);
            $message   = $remaining > 0
                ? "Invalid email or password. $remaining attempt(s) remaining."
                : 'Too many failed attempts. Please try again later.';
            Session::flash('errors', ['email' => [$message]]);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/login');
        }

        // Successful credential validation — clear rate limit counter
        $this->limiter->clear($email, $ip);

        // Redirect to intended or dashboard — safeRedirect() prevents open redirect
        $intended = \Core\Auth\Auth::safeRedirect(Session::get('intended', '/dashboard'));
        Session::forget('intended');

        // If user had an invite token pending, process it
        if ($token = Session::get('invite_token')) {
            Session::forget('invite_token');
            return Response::redirect("/join/$token");
        }

        return Response::redirect($intended);
    }

    // ── Register ──────────────────────────────────────────────────────────────

    public function showRegister(Request $request): Response
    {
        if ($this->auth->check()) return Response::redirect('/dashboard');

        // Accept any truthy boolean string ('true', '1', 'on', 'yes',
        // case-insensitive) so we match the admin UI — checkboxes post
        // value="1", which SettingsService also treats as true.
        // filter_var + FILTER_VALIDATE_BOOLEAN returns null for non-bool
        // strings rather than false, so default-to-allowed when no row
        // exists matches the schema seed (allow_registration='true').
        $settings = $this->db->fetchOne("SELECT value FROM settings WHERE `key` = 'allow_registration' AND scope = 'site'");
        $allowed  = $settings === null
            ? true
            : (bool) filter_var($settings['value'], FILTER_VALIDATE_BOOLEAN);
        if (!$allowed) {
            return Response::view('auth.registration_closed', []);
        }

        // Required-acceptance policies that have a published current
        // version. Rendered as checkboxes on the registration form so
        // we capture explicit consent at signup time (GDPR-defensible
        // affirmative act). The blocking modal is a fallback for users
        // who registered before a policy existed or before a version
        // bump.
        $requiredPolicies = [];
        try {
            $requiredPolicies = $this->db->fetchAll("
                SELECT k.id AS kind_id, k.slug AS kind_slug, k.label AS kind_label,
                       p.current_version_id AS version_id, v.version_label
                FROM policy_kinds k
                JOIN policies p        ON p.kind_id = k.id
                JOIN policy_versions v ON v.id = p.current_version_id
                WHERE k.requires_acceptance = 1
                ORDER BY k.sort_order ASC
            ");
        } catch (\Throwable) {
            // Policies module not migrated yet — render the form
            // without checkboxes and let the blocking modal catch
            // acceptance on the user's first post-registration request.
        }

        // COPPA / age gate state for the registration form. When the
        // module is installed AND its toggle is on, the view renders a
        // birthdate field. Otherwise nothing extra appears.
        $coppaEnabled = false;
        $coppaMinAge  = 13;
        if (class_exists(\Modules\Coppa\Services\CoppaService::class)) {
            try {
                $coppaSvc = new \Modules\Coppa\Services\CoppaService();
                $coppaEnabled = $coppaSvc->isEnabled();
                $coppaMinAge  = $coppaSvc->minimumAge();
            } catch (\Throwable) {
                // module installed but settings table missing — treat as off
            }
        }

        return Response::view('auth.register', [
            'oauth_providers'   => $this->enabledOAuthProviders(),
            'csrf'              => csrf_token(),
            'old'               => Session::flash('old'),
            'errors'            => Session::flash('errors'),
            'invite_token'      => Session::get('invite_token'),
            'required_policies' => $requiredPolicies,
            'coppa_enabled'     => $coppaEnabled,
            'coppa_min_age'     => $coppaMinAge,
        ]);
    }

    public function register(Request $request): Response
    {
        // Mirror the showRegister() gate on POST — otherwise an attacker can
        // bypass a closed /register page by posting directly to the handler.
        $regRow  = $this->db->fetchOne(
            "SELECT value FROM settings WHERE `key` = 'allow_registration' AND scope = 'site'"
        );
        $regAllowed = $regRow === null
            ? true
            : (bool) filter_var($regRow['value'], FILTER_VALIDATE_BOOLEAN);
        if (!$regAllowed) {
            return Response::view('auth.registration_closed', []);
        }

        // CAPTCHA gate on registration — prevents automated signups.
        $token = \Core\Services\CaptchaService::tokenFromRequest($request->post());

        // See login() for why we close+restart the session around captcha.
        $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($sessionWasActive) session_write_close();
        $captchaOk = \Core\Services\CaptchaService::verify($token, $request->ip());
        if ($sessionWasActive) session_start();

        if (!$captchaOk) {
            Session::flash('errors', ['email' => ['Please complete the security challenge and try again.']]);
            Session::flash('old', $request->post());
            return Response::redirect('/register');
        }

        $v = new Validator($request->post());
        $v->validate([
            'first_name'        => 'required|min:1|max:100',
            'last_name'         => 'required|min:1|max:100',
            'email'             => 'required|email|max:255',
            'password'          => 'required|min:12|max:255|password_strength',
            'password_confirm'  => 'required|same:password',
        ]);

        // Username validation. Auto-suggest if blank (so the user
        // doesn't get blocked at the gate); if user typed something,
        // validate format + uniqueness via UsernameSuggester. The
        // form's live JS check should catch most mistakes pre-submit,
        // but server-side is the source of truth.
        $usernameSvc   = new \Core\Services\UsernameSuggester($this->db);
        $userTypedName = trim((string) $request->post('username', ''));
        $finalUsername = null;
        if (!$v->fails()) {
            if ($userTypedName === '') {
                // Auto-pick from email + name — race-free because we
                // re-check before insert. If the suggester comes up
                // empty (extremely rare), the user will need to retry.
                $finalUsername = $usernameSvc->suggestOne(
                    (string) $v->get('email'),
                    (string) $v->get('first_name'),
                    (string) $v->get('last_name')
                );
                if ($finalUsername === null) {
                    Session::flash('errors', ['username' => [
                        'Could not auto-generate a username — please choose one.'
                    ]]);
                    Session::flash('old', $v->all());
                    return Response::redirect('/register');
                }
            } else {
                $err = $usernameSvc->validate($userTypedName);
                if ($err !== null) {
                    Session::flash('errors', ['username' => [$err]]);
                    Session::flash('old', $v->all());
                    return Response::redirect('/register');
                }
                if (!$usernameSvc->isAvailable($userTypedName)) {
                    Session::flash('errors', ['username' => [
                        'That username is already taken — pick a different one.'
                    ]]);
                    Session::flash('old', $v->all());
                    return Response::redirect('/register');
                }
                $finalUsername = strtolower($userTypedName);
            }
        }

        // Check email uniqueness
        if (!$v->fails()) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ?", [$v->get('email')]);
            if ($existing) {
                $v->validate([]); // force errors via manual add
                Session::flash('errors', ['email' => ['That email address is already registered.']]);
                Session::flash('old', $v->all());
                return Response::redirect('/register');
            }
        }

        // COPPA / age-gate check. Only fires when the coppa module is
        // installed AND the master toggle is on. Blocks the registration
        // for under-min-age applicants; the user is shown the configured
        // block message. We audit-log every rejection so admins can spot
        // patterns (a flood of under-13 attempts may indicate something
        // off about the site's positioning).
        $coppaDob = null;
        if (!$v->fails() && class_exists(\Modules\Coppa\Services\CoppaService::class)) {
            $coppa = new \Modules\Coppa\Services\CoppaService();
            if ($coppa->isEnabled()) {
                $coppaDob = trim((string) $request->post('date_of_birth', ''));
                if ($coppaDob === '') {
                    Session::flash('errors', ['date_of_birth' => ['Please provide your date of birth.']]);
                    $old = $v->all(); unset($old['password'], $old['password_confirm']);
                    Session::flash('old', $old);
                    return Response::redirect('/register');
                }
                if (!$coppa->passesAgeGate($coppaDob)) {
                    // Audit + block. Don't echo the DOB or compute the
                    // age in the user-visible message — just the policy.
                    $this->db->insert('audit_log', [
                        'actor_user_id' => null,
                        'action'        => 'coppa.registration_blocked',
                        'model'         => 'users',
                        'model_id'      => null,
                        'old_values'    => null,
                        'new_values'    => json_encode([
                            'minimum_age' => $coppa->minimumAge(),
                            'email_hash'  => substr(hash('sha256', strtolower((string) $v->get('email'))), 0, 16),
                        ]),
                        'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent'    => isset($_SERVER['HTTP_USER_AGENT'])
                            ? substr(strip_tags((string) $_SERVER['HTTP_USER_AGENT']), 0, 500)
                            : null,
                        'created_at'    => date('Y-m-d H:i:s'),
                    ]);
                    Session::flash('errors', ['date_of_birth' => [$coppa->blockMessage()]]);
                    $old = $v->all(); unset($old['password'], $old['password_confirm'], $old['date_of_birth']);
                    Session::flash('old', $old);
                    return Response::redirect('/register');
                }
            }
        }

        // Pwned-passwords check via PasswordBreachService (HIBP
        // k-anonymity). Only runs when the security module is
        // installed; failure to reach HIBP fails open. Block-vs-warn
        // is configurable from /admin/settings/security.
        if (!$v->fails() && class_exists(\Modules\Security\Services\PasswordBreachService::class)) {
            $breachErr = (new \Modules\Security\Services\PasswordBreachService())
                ->validateOrError((string) $v->get('password'));
            if ($breachErr !== null) {
                Session::flash('errors', ['password' => [$breachErr]]);
                // Strip password from old() so it doesn't repopulate
                $old = $v->all();
                unset($old['password'], $old['password_confirm']);
                Session::flash('old', $old);
                return Response::redirect('/register');
            }
        }

        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            Session::flash('old', $v->all());
            return Response::redirect('/register');
        }

        // Required-acceptance policy gate. Re-fetch (don't trust the
        // form) — if the policies module is installed and any kind has
        // a published current_version_id with requires_acceptance=1,
        // the corresponding checkbox in `accept_policy[<version_id>]`
        // must be present in the POST. This captures explicit consent
        // at registration time; the blocking modal is the fallback
        // for version bumps later.
        $requiredPolicies = [];
        try {
            $requiredPolicies = $this->db->fetchAll("
                SELECT k.id AS kind_id, p.current_version_id AS version_id, k.label AS kind_label
                FROM policy_kinds k
                JOIN policies p ON p.kind_id = k.id
                WHERE k.requires_acceptance = 1 AND p.current_version_id IS NOT NULL
            ");
        } catch (\Throwable) {
            $requiredPolicies = [];  // module not installed → no gate
        }
        if (!empty($requiredPolicies)) {
            $checked = (array) $request->post('accept_policy', []);
            $missing = [];
            foreach ($requiredPolicies as $rp) {
                if (empty($checked[(string) $rp['version_id']])) {
                    $missing[] = $rp['kind_label'];
                }
            }
            if (!empty($missing)) {
                Session::flash('errors', ['accept_policy' => [
                    'Please confirm acceptance of: ' . implode(', ', $missing) . '.'
                ]]);
                Session::flash('old', $v->all());
                return Response::redirect('/register');
            }
        }

        $userModel = new User();
        $createPayload = [
            'first_name' => $v->get('first_name'),
            'last_name'  => $v->get('last_name'),
            'email'      => strtolower($v->get('email')),
            'password'   => $v->get('password'),
            'is_active'  => 1,
        ];
        // Persist username (validated + uniqueness-checked above; either
        // user-typed or auto-suggested). The column has UNIQUE so a
        // race-condition collision between check + insert would 500;
        // catch + retry once with a numeric suffix.
        if ($finalUsername !== null && $finalUsername !== '') {
            $createPayload['username'] = $finalUsername;
        }
        // Persist DOB when COPPA collected one (it passed the gate above).
        if ($coppaDob !== null && $coppaDob !== '') {
            $createPayload['date_of_birth'] = $coppaDob;
        }
        try {
            $userId = $userModel->create($createPayload);
        } catch (\Throwable $e) {
            // Race-condition retry: another signup may have grabbed
            // the same username between check + insert. Append a 4-hex
            // suffix and try once more. If it still fails, surface
            // the original error.
            if ($finalUsername !== null && str_contains($e->getMessage(), 'Duplicate')) {
                $createPayload['username'] = $finalUsername . bin2hex(random_bytes(2));
                $userId = $userModel->create($createPayload);
            } else {
                throw $e;
            }
        }

        // Record policy acceptance for every required kind that has a
        // published version. The user just clicked the checkboxes
        // affirmatively; we use their freshly-minted user_id and the
        // current request's IP / UA. PolicyService is loaded
        // conditionally so the auth flow doesn't break if the policies
        // module isn't installed.
        if (!empty($requiredPolicies) && class_exists(\Modules\Policies\Services\PolicyService::class)) {
            try {
                $polSvc = new \Modules\Policies\Services\PolicyService();
                foreach ($requiredPolicies as $rp) {
                    $polSvc->recordAcceptance(
                        (int) $userId,
                        (int) $rp['kind_id'],
                        (int) $rp['version_id']
                    );
                }
            } catch (\Throwable $e) {
                // Don't block registration on a policy-recording
                // failure — log and let the blocking modal catch it
                // on next page view.
                error_log('Policy acceptance record at registration failed: ' . $e->getMessage());
            }
        }

        // Assign viewer role by default
        $viewerRole = $this->db->fetchOne("SELECT id FROM roles WHERE slug = 'viewer'");
        if ($viewerRole) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $viewerRole['id']]);
        }

        // Send verification email up front — even if auto-login fails the
        // verify gate, we want the user to receive the link so they can act
        // on the "please verify" message they're about to see.
        (new \Core\Services\EmailVerificationService())->send($userId, strtolower($v->get('email')));

        // Auto-login. Auth::attempt now returns 'verify_required' when the
        // require_email_verify site setting is on and the user (still
        // unverified, as expected for a brand-new account) isn't a
        // superadmin — in which case startSession was never called, so
        // there's no session to log them into and no need to "log out."
        $loginResult = $this->auth->attempt(
            strtolower($v->get('email')),
            $request->post('password')
        );

        if ($loginResult === 'verify_required') {
            // Don't auto-login a user who's about to be blocked anyway —
            // bounce them to /login with a clear "verify first" message.
            // The verification email has already been sent above.
            return Response::redirect('/login')->withFlash(
                'success',
                'Account created. Please check your email and click the verification link before signing in.'
            );
        }

        // Process pending invite (auto-login succeeded → user is signed in)
        if ($token = Session::get('invite_token')) {
            Session::forget('invite_token');
            return Response::redirect("/join/$token");
        }

        return Response::redirect('/dashboard')->withFlash('success', 'Welcome! Please verify your email.');
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(Request $request): Response
    {
        if ($this->auth->isEmulating()) {
            $this->auth->stopEmulating();
            return Response::redirect('/admin/users')->withFlash('info', 'Emulation ended.');
        }
        $this->auth->logout();
        return Response::redirect('/login');
    }

    // ── Dev Login Bypass ──────────────────────────────────────────────────────
    //
    // Intended ONLY for local development. The endpoint refuses outside of
    // local/dev environments; Auth::devLoginAs() also refuses in production
    // as a second line of defense.

    public function devLoginAs(Request $request): Response
    {
        if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
            return new Response('Not found', 404);
        }

        $userId = (int) $request->post('user_id', 0);
        if ($userId <= 0) {
            return Response::redirect('/login')->withFlash('error', 'Pick a user to sign in as.');
        }

        if (!$this->auth->devLoginAs($userId)) {
            return Response::redirect('/login')->withFlash('error', 'Dev login refused.');
        }

        return Response::redirect('/dashboard')->withFlash('success', 'Logged in via dev bypass.');
    }

    // ── OAuth ─────────────────────────────────────────────────────────────────

    public function oauthRedirect(Request $request): Response
    {
        $provider = $request->param(0);
        $cfg = $this->getOAuthConfig($provider);
        if (!$cfg) return Response::redirect('/login')->withFlash('error', 'OAuth provider not configured.');

        $state = bin2hex(random_bytes(16));
        Session::set('oauth_state', $state);
        Session::set('oauth_provider', $provider);

        $redirectUri = config('app.url') . "/auth/oauth/$provider/callback";
        $url = $this->buildOAuthUrl($provider, $cfg, $redirectUri, $state);

        return Response::redirect($url);
    }

    public function oauthCallback(Request $request): Response
    {
        $provider = $request->param(0);
        $code     = $request->query('code', '');
        $state    = $request->query('state', '');

        if ($state !== Session::get('oauth_state')) {
            return Response::redirect('/login')->withFlash('error', 'Invalid OAuth state. Please try again.');
        }
        Session::forget('oauth_state');

        $cfg         = $this->getOAuthConfig($provider);
        $redirectUri = config('app.url') . "/auth/oauth/$provider/callback";

        try {
            $tokenData    = $this->exchangeOAuthCode($provider, $cfg, $code, $redirectUri);
            $providerData = $this->fetchOAuthUser($provider, $tokenData);

            $result = $this->auth->attemptOAuth($provider, $providerData['id'], $providerData);

            if ($result === '2fa_required') {
                return Response::redirect('/auth/2fa/challenge');
            }

            if ($result === true) {
                $intended = \Core\Auth\Auth::safeRedirect(Session::get('intended', '/dashboard'));
                Session::forget('intended');

                if ($token = Session::get('invite_token')) {
                    Session::forget('invite_token');
                    return Response::redirect("/join/$token");
                }
                return Response::redirect($intended);
            }
        } catch (\Throwable $e) {
            error_log("OAuth error ($provider): " . $e->getMessage());
        }

        return Response::redirect('/login')->withFlash('error', 'OAuth login failed. Please try again.');
    }

    // ── Superadmin Toggle ─────────────────────────────────────────────────────

    public function toggleSuperadminMode(Request $request): Response
    {
        // AJAX / XHR callers get the old JSON shape. HTML form posts get a
        // redirect back to wherever they came from so the browser doesn't
        // end up rendering the raw JSON response as a page.
        if (!$this->auth->isSuperAdmin()) {
            return $request->isAjax()
                ? Response::json(['error' => 'Forbidden'], 403)
                : Response::redirect('/dashboard')->withFlash('error', 'Forbidden.');
        }

        $enable = (bool) $request->post('enable', 0);
        $this->auth->toggleSuperadminMode($enable);

        if ($request->isAjax()) {
            return Response::json(['success' => true, 'mode' => $enable]);
        }

        $back = $request->header('Referer') ?: '/dashboard';
        // Don't let Referer be used to redirect offsite.
        if (!preg_match('#^https?://#i', $back) || str_starts_with($back, (string) config('app.url'))) {
            // relative or same-origin — ok
        } else {
            $back = '/dashboard';
        }

        return Response::redirect($back)->withFlash(
            'success',
            $enable ? 'Superadmin mode enabled.' : 'Superadmin mode disabled.'
        );
    }

    // ── User Emulation (superadmin only) ──────────────────────────────────────

    public function startEmulate(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::redirect('/admin/users')->withFlash('error', 'Enable superadmin mode first.');
        }
        $targetId = (int) $request->param(0);
        if (!$this->auth->startEmulating($targetId)) {
            return Response::redirect('/admin/users')->withFlash('error', 'Could not emulate that user.');
        }
        return Response::redirect('/dashboard')->withFlash('warning', 'You are now emulating a user. All actions are logged.');
    }

    public function stopEmulate(Request $request): Response
    {
        $this->auth->stopEmulating();
        return Response::redirect('/admin/users')->withFlash('info', 'Emulation ended.');
    }

    // ── Password Reset ────────────────────────────────────────────────────────

    public function showForgotPassword(Request $request): Response
    {
        return Response::view('auth.forgot_password', ['csrf' => csrf_token()]);
    }

    public function sendPasswordReset(Request $request): Response
    {
        $v = new Validator($request->post());
        $v->validate(['email' => 'required|email']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/password/forgot');
        }

        $email = strtolower($v->get('email'));
        $user  = $this->db->fetchOne("SELECT id, first_name FROM users WHERE email = ?", [$email]);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            // SECURITY: Store as SHA-256 hash (not bcrypt) so comparison is constant-time
            // via hash_equals(). bcrypt has a timing leak when the email lookup fails vs succeeds.
            $this->db->query(
                "REPLACE INTO password_resets (email, token) VALUES (?, ?)",
                [$email, hash('sha256', $token)]
            );
            // Token-only URL — the token is a unique 256-bit secret; we can
            // look up the email from password_resets server-side on redeem.
            // Avoids URL-encoding pitfalls and reduces PII in email bodies.
            $resetUrl = config('app.url') . "/password/reset?token=" . rawurlencode($token);
            // Thread the TTL through the template so the "link expires in X"
            // copy matches the actual window enforced by resetPassword().
            // Same setting drives both sides; see /admin/settings/security.
            $ttlMinutes = (int) setting('password_reset_ttl_minutes', 120);
            if ($ttlMinutes < 1) $ttlMinutes = 120;
            (new MailService())->sendTemplate($email, 'Reset Your Password', 'password_reset', [
                'user'       => $user,
                'resetUrl'   => $resetUrl,
                'ttlMinutes' => $ttlMinutes,
            ]);
        }

        // Always show success to prevent email enumeration
        return Response::redirect('/login')->withFlash('success', 'If that email exists, a reset link has been sent.');
    }

    public function showResetPassword(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        // Validate the token before rendering the form. Otherwise an
        // expired/invalid link still shows the "choose a new password"
        // page; the user fills it out, submits, and only then gets sent
        // to /password/forgot with no explanation — looking exactly like
        // the form silently ate their input. Validating here surfaces
        // the expiry up front with a clear flash.
        //
        // Same TTL setting drives this and resetPassword(); see
        // /admin/settings/security → "Password-reset link lifetime".
        $valid = false;
        if ($token !== '') {
            $ttlMinutes = (int) setting('password_reset_ttl_minutes', 120);
            if ($ttlMinutes < 1) $ttlMinutes = 120;

            $hash  = hash('sha256', $token);
            $valid = (bool) $this->db->fetchOne(
                "SELECT 1 FROM password_resets
                  WHERE token = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$hash, $ttlMinutes]
            );
        }

        if (!$valid) {
            // Token missing, never existed, already used (DELETE on success),
            // or aged past the TTL — same UX outcome either way: ask them
            // to start over. Avoid revealing which case it was so we don't
            // leak whether a token ever existed.
            return Response::redirect('/password/forgot')
                ->withFlash('error', 'That reset link has expired or is invalid. Please request a new one.');
        }

        return Response::view('auth.reset_password', [
            'token' => $token,
            'csrf'  => csrf_token(),
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        $v = new Validator($request->post());
        $v->validate([
            'token'            => 'required',
            'password'         => 'required|min:12|password_strength',
            'password_confirm' => 'required|same:password',
        ]);
        if ($v->fails()) {
            error_log('[resetPassword] validation failed: ' . json_encode($v->errors()));
            Session::flash('errors', $v->errors());
            return Response::redirect('/password/reset?token=' . rawurlencode((string) $v->get('token')));
        }

        // Breach check on the new password too — a user resetting
        // because they were compromised shouldn't pick another known-bad
        // password. Block-vs-warn governed by site setting.
        if (class_exists(\Modules\Security\Services\PasswordBreachService::class)) {
            $breachErr = (new \Modules\Security\Services\PasswordBreachService())
                ->validateOrError((string) $v->get('password'));
            if ($breachErr !== null) {
                Session::flash('errors', ['password' => [$breachErr]]);
                return Response::redirect('/password/reset?token=' . rawurlencode((string) $v->get('token')));
            }
        }

        // Look up the record by token hash only. The token is a 256-bit secret;
        // a match is proof enough. No email in the URL means no URL-encoding
        // pitfalls, and less PII leaking through email clients / logs.
        //
        // NOTE: password_resets has no `id` column — the primary key is email.
        // An earlier version of this query selected `id` and blew up with
        // "Unknown column 'id' in 'field list'". Only fields the downstream
        // code actually uses belong in the SELECT.
        //
        // TTL is driven by the `password_reset_ttl_minutes` site setting
        // (editable at /admin/settings/security). saveSecurity() whitelists
        // the value against a preset list, so this read is trusted integer
        // data — safe to inline in the SQL. We still defensively cast here
        // in case the setting row is missing or holds garbage.
        $ttlMinutes = (int) setting('password_reset_ttl_minutes', 120);
        if ($ttlMinutes < 1) $ttlMinutes = 120;

        $submittedHash = hash('sha256', (string) $v->get('token'));
        $record = $this->db->fetchOne(
            "SELECT email, token, created_at FROM password_resets
              WHERE token = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$submittedHash, $ttlMinutes]
        );

        if (!$record) {
            error_log('[resetPassword] token not found or expired');
            Session::flash('errors', ['token' => ['Invalid or expired reset link. Request a new one.']]);
            return Response::redirect('/password/forgot');
        }

        $userModel = new User();
        $user = $this->db->fetchOne("SELECT id FROM users WHERE email = ?", [strtolower($record['email'])]);
        if ($user) {
            $userModel->update($user['id'], ['password' => $v->get('password')]);
            $this->db->delete('password_resets', 'email = ?', [strtolower($record['email'])]);
        }

        return Response::redirect('/login')->withFlash('success', 'Password reset successfully. Please log in.');
    }

    // ── Email Verification ────────────────────────────────────────────────────

    public function verifyEmail(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        // Failure paths land guests on /login, logged-in users on /dashboard —
        // a guest hitting /dashboard would bounce back through AuthMiddleware
        // and risk eating the flash en route.
        $failRedirect = function (string $msg): Response {
            $back = $this->auth->guest() ? '/login' : '/dashboard';
            return Response::redirect($back)->withFlash('error', $msg);
        };

        // Empty-token hits (browser prefetch, address-bar speculative loads,
        // accidental refresh after the URL has been "cleaned") aren't a
        // user-actionable error — a legitimate visitor always has a token
        // in their URL. Redirect silently without flashing so we don't
        // pollute the next page render with a stray "Invalid link" banner.
        // This used to fire right after a successful verify when the
        // browser re-touched /verify-email tokenless.
        if (!$token) {
            $back = $this->auth->guest() ? '/login' : '/dashboard';
            return Response::redirect($back);
        }

        // Look up by hashed token only. The token is a 256-bit secret, so
        // a match is proof; user_id is derived from the record. This avoids
        // the `?id=X&email=Y` copy-paste pitfall (where `&amp;` from an
        // email client gets pasted into the address bar and splits the query
        // parser's view of the params).
        $tokenHash = hash('sha256', $token);
        $record = $this->db->fetchOne(
            "SELECT id, user_id, token, expires_at
               FROM email_verifications
              WHERE token = ? AND expires_at > NOW() AND used_at IS NULL",
            [$tokenHash]
        );

        if (!$record) {
            return $failRedirect('Invalid or expired verification link.');
        }

        $userId = (int) $record['user_id'];

        // Mark token used and verify email atomically
        $this->db->transaction(function () use ($userId, $record) {
            $this->db->query(
                "UPDATE email_verifications SET used_at = NOW() WHERE id = ?",
                [$record['id']]
            );
            $this->db->query(
                "UPDATE users SET email_verified_at = NOW() WHERE id = ?",
                [$userId]
            );
        });

        $this->auth->refreshUser();

        // Land guests on /login (the route is now public so the typical
        // verify-required flow ends here). Sending them to /dashboard
        // would just bounce back through AuthMiddleware → /login and
        // potentially eat the flash on the way. Logged-in users (e.g.
        // someone who registered without the gate, then verified later)
        // go straight to /dashboard.
        if ($this->auth->guest()) {
            return Response::redirect('/login')->withFlash('success', 'Email verified! You can now sign in.');
        }
        return Response::redirect('/dashboard')->withFlash('success', 'Email verified!');
    }

    /**
     * Resend the verification email for the currently logged-in user.
     * Rate-limited to one send per 60 seconds per user so the endpoint
     * can't be turned into a spam bot against the mail transport.
     */
    public function resendVerification(Request $request): Response
    {
        if ($this->auth->guest()) {
            return Response::redirect('/login');
        }
        $user = $this->auth->user();

        if (!empty($user['email_verified_at'])) {
            return Response::redirect('/dashboard')
                ->withFlash('info', 'Your email is already verified.');
        }

        // 60-second rate limit: refuse if a verification row was updated
        // very recently. The email_verifications row's created_at tracks
        // the initial insert, but ON DUPLICATE KEY UPDATE keeps that
        // timestamp — so we use a short TTL check against the token's
        // expires_at instead (24h − time since last issue ≈ 24h − Δ).
        $existing = $this->db->fetchOne(
            "SELECT expires_at,
                    TIMESTAMPDIFF(SECOND,
                        DATE_SUB(expires_at, INTERVAL 24 HOUR),
                        NOW()) AS seconds_since_last
               FROM email_verifications
              WHERE user_id = ?",
            [$user['id']]
        );
        if ($existing && (int)$existing['seconds_since_last'] < 60) {
            $wait = 60 - (int)$existing['seconds_since_last'];
            return Response::redirect('/dashboard')
                ->withFlash('error', "Please wait {$wait}s before requesting another verification email.");
        }

        (new \Core\Services\EmailVerificationService())->send((int)$user['id'], (string)$user['email']);
        $this->auth->auditLog('auth.verification_resent', 'users', (int)$user['id']);

        return Response::redirect('/dashboard')
            ->withFlash('success', 'Verification email sent — check your inbox.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    //
    // The verification-email helper that used to live here was extracted to
    // Core\Services\EmailVerificationService so the user-admin page can
    // resend without one controller depending on another. See
    // UserController::resendVerification.

    /**
     * Format a duration in seconds as a friendly "X minutes, Y seconds" string.
     * Static so it can be reused anywhere in this controller without state.
     *
     *   5    → "5 seconds"
     *   60   → "1 minute"
     *   61   → "1 minute, 1 second"
     *   896  → "14 minutes, 56 seconds"
     *   3600 → "60 minutes" (we cap at minutes — 15-min lockout never overflows to hours)
     */
    private static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }

        $minutes = intdiv($seconds, 60);
        $rem     = $seconds % 60;

        $minStr = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        if ($rem === 0) return $minStr;

        $secStr = $rem . ' second' . ($rem === 1 ? '' : 's');
        return "$minStr, $secStr";
    }

    private function enabledOAuthProviders(): array
    {
        // OAuth config now lives in .env (OAUTH_GOOGLE_CLIENT_ID, etc.).
        // A provider is "enabled" iff all its required env vars are set.
        $all       = \Core\Services\IntegrationConfig::config('oauth');
        $providers = [];
        foreach (array_keys($all) as $provider) {
            if (\Core\Services\IntegrationConfig::providerConfigured('oauth', $provider)) {
                $providers[] = $provider;
            }
        }
        return $providers;
    }

    private function getOAuthConfig(string $provider): ?array
    {
        $all = \Core\Services\IntegrationConfig::config('oauth');
        $cfg = $all[$provider] ?? null;
        if (!$cfg || empty($cfg['client_id'])) return null;
        return $cfg;
    }

    private function buildOAuthUrl(string $provider, array $cfg, string $redirectUri, string $state): string
    {
        $params = ['client_id' => $cfg['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state];

        $baseUrls = [
            'google'    => 'https://accounts.google.com/o/oauth2/v2/auth',
            'microsoft' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'apple'     => 'https://appleid.apple.com/auth/authorize',
            'facebook'  => 'https://www.facebook.com/v18.0/dialog/oauth',
            'linkedin'  => 'https://www.linkedin.com/oauth/v2/authorization',
        ];

        $scopes = [
            'google'    => 'openid email profile',
            'microsoft' => 'openid email profile',
            'apple'     => 'name email',
            'facebook'  => 'email public_profile',
            'linkedin'  => 'r_emailaddress r_liteprofile',
        ];

        $params['scope'] = $scopes[$provider] ?? 'email';
        return ($baseUrls[$provider] ?? '#') . '?' . http_build_query($params);
    }

    private function exchangeOAuthCode(string $provider, array $cfg, string $code, string $redirectUri): array
    {
        $tokenUrls = [
            'google'    => 'https://oauth2.googleapis.com/token',
            'microsoft' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'facebook'  => 'https://graph.facebook.com/v18.0/oauth/access_token',
            'linkedin'  => 'https://www.linkedin.com/oauth/v2/accessToken',
        ];

        $payload = http_build_query([
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            // Cap OAuth provider latency at 5s. Without an explicit timeout,
            // PHP falls back to default_socket_timeout (typically 60s) and
            // login blocks indefinitely when the provider is slow/unreachable.
            'timeout'       => 5.0,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($tokenUrls[$provider] ?? '', false, $ctx);
        if ($result === false) return [];
        return json_decode($result, true) ?? [];
    }

    private function fetchOAuthUser(string $provider, array $tokenData): array
    {
        $accessToken = $tokenData['access_token'] ?? '';
        $userUrls = [
            'google'    => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'microsoft' => 'https://graph.microsoft.com/oidc/userinfo',
            'facebook'  => 'https://graph.facebook.com/me?fields=id,first_name,last_name,email',
            'linkedin'  => 'https://api.linkedin.com/v2/me',
        ];

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $accessToken\r\n",
            // Same 5s cap as the token exchange — see exchangeOAuthCode.
            'timeout'       => 5.0,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($userUrls[$provider] ?? '', false, $ctx);
        if ($result === false) return ['id' => '', 'email' => '', 'first_name' => '', 'last_name' => '', 'avatar' => null, 'token' => $accessToken];
        $data   = json_decode($result, true) ?? [];

        // Normalize to common format
        return [
            'id'         => $data['sub'] ?? $data['id'] ?? '',
            'email'      => $data['email'] ?? '',
            'first_name' => $data['given_name'] ?? $data['first_name'] ?? $data['localizedFirstName'] ?? '',
            'last_name'  => $data['family_name'] ?? $data['last_name'] ?? $data['localizedLastName'] ?? '',
            'avatar'     => $data['picture'] ?? null,
            'token'      => $accessToken,
        ];
    }
}
