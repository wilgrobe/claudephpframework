<?php
// app/Controllers/TwoFactorController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Auth\TwoFactorService;
use Core\Auth\RateLimiter;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Database\Database;

/**
 * TwoFactorController
 *
 * Login flow:
 *   1. User submits email + password → AuthController::login()
 *   2. If 2FA enabled, Auth::attempt() sets $_SESSION['2fa_pending_user_id']
 *      instead of fully logging in, then redirects to /auth/2fa/challenge
 *   3. User submits OTP/TOTP → TwoFactorController::challenge()
 *   4. On success, session is completed and user is redirected
 *
 * Setup flow (authenticated users):
 *   /profile/2fa         → current status + enable/disable options
 *   /profile/2fa/setup   → choose method + enroll
 *   /profile/2fa/confirm → verify first TOTP code to activate
 *   /profile/2fa/disable → turn off 2FA
 *   /profile/2fa/recovery→ view / regenerate recovery codes
 */
class TwoFactorController
{
    private TwoFactorService $twoFactor;
    private Auth             $auth;
    private Database         $db;
    private RateLimiter      $limiter;

    public function __construct()
    {
        $this->twoFactor = new TwoFactorService();
        $this->auth      = Auth::getInstance();
        $this->db        = Database::getInstance();
        $this->limiter   = new RateLimiter();
    }

    // =========================================================================
    // Challenge (called during login)
    // =========================================================================

    /**
     * Show the 2FA challenge page.
     * User must have a valid 2fa_pending_user_id in session.
     */
    public function showChallenge(Request $request): Response
    {
        $userId = (int) Session::get('2fa_pending_user_id', 0);
        if (!$userId) return Response::redirect('/login');

        $user   = $this->db->fetchOne("SELECT id, first_name, email, phone, two_factor_method FROM users WHERE id = ?", [$userId]);
        if (!$user) return Response::redirect('/login');

        $method      = $user['two_factor_method'];
        $challengeId = Session::get('2fa_challenge_id');

        // Auto-send OTP for email/SMS if no challenge active yet
        if (in_array($method, ['email', 'sms'], true) && !$challengeId) {
            $challengeId = $this->twoFactor->sendChallenge($userId, $method);
            Session::set('2fa_challenge_id', $challengeId);
        }

        return Response::view('auth.2fa_challenge', [
            'method'      => $method,
            'user'        => $user,
            'challengeId' => $challengeId,
            'csrf'        => csrf_token(),
            // Masked destination for UX display
            'destination' => $method === 'email'
                ? $this->maskEmail($user['email'] ?? '')
                : ($method === 'sms' ? $this->maskPhone($user['phone'] ?? '') : null),
        ]);
    }

    /**
     * Verify the submitted code during login.
     * SECURITY: challenge_id is read from session only — never from POST data.
     * This prevents an attacker from swapping in another user's challenge_id.
     */
    public function verifyChallenge(Request $request): Response
    {
        $userId = (int) Session::get('2fa_pending_user_id', 0);
        if (!$userId) return Response::redirect('/login');

        $code        = trim($request->post('code', ''));
        $method      = $this->twoFactor->getMethod($userId);
        // SECURITY: Read challenge ID from session, not from the POST body.
        $challengeId = (int) Session::get('2fa_challenge_id', 0);

        // SECURITY: Rate-limit 2FA code attempts by IP to prevent OTP brute-force.
        $ip = $request->ip();
        if ($this->limiter->tooManyAttempts("2fa:$userId", $ip)) {
            $wait = $this->limiter->availableInSeconds("2fa:$userId", $ip);
            Session::flash('error', "Too many attempts. Try again in $wait seconds.");
            return Response::redirect('/auth/2fa/challenge');
        }

        if (!$code) {
            Session::flash('error', 'Please enter the verification code.');
            return Response::redirect('/auth/2fa/challenge');
        }

        $valid = $this->twoFactor->verify($userId, $method, $code, $challengeId ?: null);

        if (!$valid) {
            $this->limiter->hit("2fa:$userId", $request->ip());
            $this->auth->auditLog('2fa.failed', 'users', $userId, null, ['method' => $method]);
            Session::flash('error', 'Invalid or expired code. Please try again.');
            return Response::redirect('/auth/2fa/challenge');
        }

        // Clear 2FA rate limit on success
        $this->limiter->clear("2fa:$userId", $request->ip());

        // Success — complete the login
        $this->completePendingLogin($userId);

        $intended = \Core\Auth\Auth::safeRedirect(Session::get('intended', '/dashboard'));
        Session::forget('intended');

        return Response::redirect($intended)
            ->withFlash('success', 'Verified! Welcome back.');
    }

    /**
     * Resend OTP (email/SMS only).
     */
    public function resendCode(Request $request): Response
    {
        $userId = (int) Session::get('2fa_pending_user_id', 0);
        if (!$userId) return Response::redirect('/login');

        $method = $this->twoFactor->getMethod($userId);
        if (!in_array($method, ['email', 'sms'], true)) {
            return Response::redirect('/auth/2fa/challenge');
        }

        $challengeId = $this->twoFactor->sendChallenge($userId, $method);
        Session::set('2fa_challenge_id', $challengeId);

        return Response::redirect('/auth/2fa/challenge')
            ->withFlash('success', 'A new code has been sent.');
    }

    /**
     * Show the recovery code entry form.
     */
    public function showRecoveryForm(Request $request): Response
    {
        $userId = (int) Session::get('2fa_pending_user_id', 0);
        if (!$userId) return Response::redirect('/login');

        return Response::view('auth.2fa_recovery', [
            'csrf' => csrf_token(),
        ]);
    }

    /**
     * Verify a recovery code during login.
     */
    public function verifyRecovery(Request $request): Response
    {
        $userId = (int) Session::get('2fa_pending_user_id', 0);
        if (!$userId) return Response::redirect('/login');

        $code = trim($request->post('recovery_code', ''));
        if (!$code) {
            Session::flash('error', 'Please enter a recovery code.');
            return Response::redirect('/auth/2fa/recovery');
        }

        // Phase 43.190a — rate-limit gate mirroring the `challenge`
        // (TOTP/email/SMS) flow above. Pre-fix the recovery endpoint
        // had no limiter — an attacker who had stolen the primary
        // credentials (phishing, password reuse) + obtained the
        // 2fa_pending_user_id session could fire unlimited recovery
        // submissions. 40-bit codes × 8 active = ~8T search space so
        // brute-force is impractical-but-uncapped; the rate-limit
        // matches the defense-in-depth posture of the other auth
        // endpoints. Key shape "2farec:N" is distinct from the
        // challenge path's "2fa:N" so a separate counter avoids
        // the TOTP flow's failures bleeding into the recovery
        // budget + vice versa.
        $ip = $request->ip();
        $rateKey = "2farec:$userId";
        if ($this->limiter->tooManyAttempts($rateKey, $ip)) {
            $wait = $this->limiter->availableInSeconds($rateKey, $ip);
            Session::flash('error', "Too many recovery attempts. Try again in $wait seconds.");
            return Response::redirect('/auth/2fa/recovery');
        }

        if (!$this->twoFactor->verifyRecoveryCode($userId, $code)) {
            $this->limiter->hit($rateKey, $ip);
            $this->auth->auditLog('2fa.recovery_failed', 'users', $userId);
            Session::flash('error', 'Invalid recovery code.');
            return Response::redirect('/auth/2fa/recovery');
        }

        // Clear the recovery-code limiter on success.
        $this->limiter->clear($rateKey, $ip);
        $this->auth->auditLog('2fa.recovery_used', 'users', $userId);
        $this->completePendingLogin($userId);

        return Response::redirect('/profile/2fa')
            ->withFlash('warning', 'Recovery code used. Please review your 2FA settings.');
    }

    // =========================================================================
    // Setup (authenticated users — /profile/2fa/*)
    // =========================================================================

    /**
     * 2FA status & management page.
     */
    public function settingsPage(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        $info = $this->twoFactor->getUserTwoFactorInfo($this->auth->id());
        return Response::view('auth.2fa_settings', [
            'twofa' => $info,
            'user'  => $this->auth->user(),
        ]);
    }

    /**
     * Show setup form — user picks method.
     */
    public function setupForm(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $method = $request->query('method', '');

        // If TOTP method requested, generate secret and QR now
        $totpData = null;
        if ($method === 'totp') {
            // Phase 43.193a — enrollTotp now throws when TOTP is already
            // confirmed for this user (prevents silent overwrite via
            // session-hijack or CSRF on the GET). Surface as a clear
            // flash + redirect to settings page rather than 500.
            try {
                $totpData = $this->twoFactor->enrollTotp($this->auth->id());
            } catch (\RuntimeException $e) {
                Session::flash('warning', $e->getMessage());
                return Response::redirect('/profile/2fa');
            }
            // Server-side QR via the `twofactorqr` premium module — outputs
            // a data: URI we can inline. class_exists guard so framework-
            // only installs (or builder installs without the module
            // enabled) gracefully fall back to client-side qrcode.js
            // rendering in the view.
            $totpData['qr_data_uri'] = null;
            if (class_exists(\Modules\TwoFactorQr\Services\TwoFactorQrService::class)) {
                $totpData['qr_data_uri'] = (new \Modules\TwoFactorQr\Services\TwoFactorQrService())
                    ->render((string) $totpData['provisioning_uri'], 220);
            }
        }

        return Response::view('auth.2fa_setup', [
            'method'   => $method,
            'totpData' => $totpData,
            'user'     => $this->auth->user(),
            'csrf'     => csrf_token(),
        ]);
    }

    /**
     * Process method selection / enable email or SMS 2FA.
     *
     * Phase 43.193a — require password re-auth. Pre-fix only the
     * session was checked; `disable` + `regenerateRecoveryCodes`
     * correctly required password confirm, but `enableMethod`
     * didn't. Session-hijack → attacker could silently downgrade
     * the victim's 2FA method (e.g. TOTP → SMS, gaining control
     * if they could also change the phone), OR enable a method
     * the victim didn't choose. Re-auth here matches the sibling
     * disable/regenerate flows.
     */
    public function enableMethod(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $method = $request->post('method', '');

        // Password re-auth before any 2FA change.
        //
        // Phase 43.201a C4 — pre-fix this read `$user['password']` from
        // `$this->auth->user()`, but `Auth::loadUser` deliberately omits
        // the password column from the session-cached user shape. So
        // `$user['password']` was always undefined → password_verify
        // against empty string → every call to enableMethod failed
        // unconditionally with "Please enter your current password",
        // making the entire 2FA enable flow user-facing BROKEN. The
        // sibling `disable()` already does the right thing (line ~345)
        // — mirror that pattern.
        $password = (string) $request->post('current_password', '');
        $user     = $this->auth->user();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }
        $row = \Core\Database\Database::getInstance()->fetchOne(
            'SELECT password FROM users WHERE id = ?',
            [$this->auth->id()]
        );
        if (!$row || empty($row['password']) || !password_verify($password, (string) $row['password'])) {
            Session::flash('error', 'Please enter your current password to change 2FA settings.');
            return Response::redirect('/profile/2fa/setup');
        }

        if ($method === 'totp') {
            // Redirect to TOTP setup page where they'll scan QR and confirm
            return Response::redirect('/profile/2fa/setup?method=totp');
        }

        if (in_array($method, ['email', 'sms'], true)) {
            // Validate phone present for SMS
            if ($method === 'sms') {
                if (empty($user['phone'])) {
                    Session::flash('error', 'You must add a phone number to your profile before enabling SMS 2FA.');
                    return Response::redirect('/profile/2fa/setup');
                }
            }

            $recoveryCodes = $this->twoFactor->enableOtpMethod($this->auth->id(), $method);
            $this->auth->auditLog('2fa.enabled', 'users', $this->auth->id(), null, ['method' => $method]);

            Session::set('2fa_new_recovery_codes', $recoveryCodes);
            return Response::redirect('/profile/2fa/recovery-codes?new=1');
        }

        Session::flash('error', 'Invalid method selected.');
        return Response::redirect('/profile/2fa/setup');
    }

    /**
     * Confirm TOTP enrollment — user types first code from their authenticator app.
     */
    public function confirmTotp(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        // AUTH-H1 — require password re-auth to ACTIVATE TOTP. The enroll GET
        // (setupForm) is reachable directly, bypassing enableMethod's password
        // gate; this is the chokepoint where an unconfirmed secret becomes the
        // live second factor, so a hijacked session must prove the password
        // here (mirrors disable()/regenerateRecoveryCodes()). enrollTotp()
        // separately blocks overwriting an already-confirmed secret.
        $password = (string) $request->post('current_password', '');
        $row = \Core\Database\Database::getInstance()->fetchOne(
            'SELECT password FROM users WHERE id = ?',
            [$this->auth->id()]
        );
        if (!$row || empty($row['password']) || !password_verify($password, (string) $row['password'])) {
            Session::flash('error', 'Please enter your current password to enable two-factor authentication.');
            return Response::redirect('/profile/2fa/setup?method=totp');
        }

        $code = trim($request->post('code', ''));
        if (!$code) {
            Session::flash('error', 'Please enter the 6-digit code from your authenticator app.');
            return Response::redirect('/profile/2fa/setup?method=totp');
        }

        if (!$this->twoFactor->confirmTotpEnrollment($this->auth->id(), $code)) {
            Session::flash('error', 'Code did not match. Please ensure your app clock is synced and try again.');
            return Response::redirect('/profile/2fa/setup?method=totp');
        }

        $this->auth->auditLog('2fa.enabled', 'users', $this->auth->id(), null, ['method' => 'totp']);

        // Generate recovery codes now that enrollment is confirmed
        $recoveryCodes = $this->twoFactor->regenerateRecoveryCodes($this->auth->id());
        Session::set('2fa_new_recovery_codes', $recoveryCodes);

        return Response::redirect('/profile/2fa/recovery-codes?new=1');
    }

    /**
     * Disable 2FA — requires current password confirmation.
     */
    public function disableForm(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        return Response::view('auth.2fa_disable', [
            'user' => $this->auth->user(),
            'csrf' => csrf_token(),
        ]);
    }

    public function disable(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        // Require password confirmation before disabling
        $password = $request->post('password', '');
        $userRow  = $this->db->fetchOne("SELECT password FROM users WHERE id = ?", [$this->auth->id()]);

        if (!$userRow || !$password || !password_verify($password, $userRow['password'])) {
            Session::flash('error', 'Incorrect password. 2FA was not disabled.');
            return Response::redirect('/profile/2fa/disable');
        }

        $this->twoFactor->disable($this->auth->id());
        $this->auth->auditLog('2fa.disabled', 'users', $this->auth->id());

        return Response::redirect('/profile/2fa')
            ->withFlash('success', 'Two-factor authentication has been disabled.');
    }

    /**
     * View / regenerate recovery codes.
     */
    public function recoveryCodes(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $isNew = $request->query('new') === '1';
        $codes = $isNew ? Session::get('2fa_new_recovery_codes', []) : [];

        if ($isNew) Session::forget('2fa_new_recovery_codes');

        return Response::view('auth.2fa_recovery_codes', [
            'codes' => $codes,
            'isNew' => $isNew,
            'user'  => $this->auth->user(),
            'csrf'  => csrf_token(),
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        // Require password confirmation
        $password = $request->post('password', '');
        $userRow  = $this->db->fetchOne("SELECT password FROM users WHERE id = ?", [$this->auth->id()]);

        if (!$userRow || !$password || !password_verify($password, $userRow['password'])) {
            Session::flash('error', 'Incorrect password.');
            return Response::redirect('/profile/2fa/recovery-codes');
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($this->auth->id());
        $this->auth->auditLog('2fa.recovery_codes_regenerated', 'users', $this->auth->id());
        Session::set('2fa_new_recovery_codes', $codes);

        return Response::redirect('/profile/2fa/recovery-codes?new=1');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Complete a pending 2FA login: set user_id in session, remove pending markers.
     */
    private function completePendingLogin(int $userId): void
    {
        $user = $this->db->fetchOne(
            "SELECT id, two_factor_method FROM users WHERE id = ?",
            [$userId]
        );
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        Session::forget('2fa_pending_user_id');
        Session::forget('2fa_challenge_id');

        $this->db->query("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$userId]);
        $this->auth->refreshUser();
        $this->auth->auditLog('2fa.success', 'users', $userId, null, [
            'method' => $user['two_factor_method'] ?? 'unknown',
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email . '@', 2);
        $masked = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));
        return $masked . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }
}
