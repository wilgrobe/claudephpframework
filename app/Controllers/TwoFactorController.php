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

        if (!$this->twoFactor->verifyRecoveryCode($userId, $code)) {
            $this->auth->auditLog('2fa.recovery_failed', 'users', $userId);
            Session::flash('error', 'Invalid recovery code.');
            return Response::redirect('/auth/2fa/recovery');
        }

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
            $totpData = $this->twoFactor->enrollTotp($this->auth->id());
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
     */
    public function enableMethod(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $method = $request->post('method', '');

        if ($method === 'totp') {
            // Redirect to TOTP setup page where they'll scan QR and confirm
            return Response::redirect('/profile/2fa/setup?method=totp');
        }

        if (in_array($method, ['email', 'sms'], true)) {
            // Validate phone present for SMS
            if ($method === 'sms') {
                $user = $this->auth->user();
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
