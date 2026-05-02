<?php
// modules/profile/Controllers/ProfileController.php
namespace Modules\Profile\Controllers;

use Core\Auth\Auth;
use Core\Auth\TwoFactorService;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Database\Database;
use Core\Services\FileUploadService;

/**
 * Ported from App\Controllers\ProfileController. Behavior unchanged. Only
 * namespace moved and view names use 'profile::' prefix. The show view
 * still links to /profile/2fa/* which stays in routes/web.php.
 */
class ProfileController
{
    private Auth             $auth;
    private Database         $db;
    private TwoFactorService $twoFactor;

    public function __construct()
    {
        $this->auth      = Auth::getInstance();
        $this->db        = Database::getInstance();
        $this->twoFactor = new TwoFactorService();
    }

    public function show(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId = $this->auth->id();
        $user   = $this->db->fetchOne(
            "SELECT id, username, email, first_name, last_name, avatar, bio, phone,
                    is_active, email_verified_at, last_login_at, created_at,
                    two_factor_enabled, two_factor_method, two_factor_confirmed
             FROM users WHERE id = ?",
            [$userId]
        );

        $oauthProviders = $this->db->fetchAll(
            "SELECT provider, created_at FROM user_oauth WHERE user_id = ?",
            [$userId]
        );

        $twofa = $this->twoFactor->getUserTwoFactorInfo($userId);

        return Response::view('profile::show', [
            'user'           => $user,
            'oauthProviders' => $oauthProviders,
            'twofa'          => $twofa,
        ]);
    }

    public function editForm(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        $user = $this->db->fetchOne(
            "SELECT id, username, email, first_name, last_name, avatar, bio, phone
               FROM users WHERE id = ?",
            [$this->auth->id()]
        );
        return Response::view('profile::edit', ['user' => $user]);
    }

    public function update(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $v = new Validator($request->post());
        $v->validate([
            'first_name' => 'required|min:1|max:100',
            'last_name'  => 'required|min:1|max:100',
            'phone'      => 'nullable|max:30',
            'bio'        => 'nullable|max:1000',
        ]);

        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/profile/edit');
        }

        $data = [
            'first_name' => $v->get('first_name'),
            'last_name'  => $v->get('last_name'),
            'phone'      => $v->get('phone') ?: null,
            'bio'        => $v->get('bio') ?: null,
        ];

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            try {
                $uploader = new FileUploadService();
                $existing = $this->db->fetchOne("SELECT avatar FROM users WHERE id = ?", [$this->auth->id()]);

                $relativePath = $uploader->uploadImage(
                    $request->file('avatar'),
                    'avatars',
                    2_097_152 // 2 MB limit for avatars
                );

                $data['avatar'] = $uploader->url($relativePath);

                // Delete the previous avatar. delete() tolerates either a bare
                // relative key or a full URL left over from the old URL-in-DB
                // convention — it resolves both back to a storage key before
                // issuing the delete.
                if (!empty($existing['avatar'])) {
                    $uploader->delete($existing['avatar']);
                }
            } catch (\Throwable $e) {
                error_log('[ProfileController] avatar upload failed: ' . $e::class . ': ' . $e->getMessage());
                Session::flash('errors', ['avatar' => [
                    'Upload failed: ' . $e->getMessage() . ' (' . $e::class . ')'
                ]]);
                return Response::redirect('/profile/edit');
            }
        }

        // Password change
        if ($newPass = $request->post('password')) {
            $v2 = new Validator($request->post());
            $v2->validate([
                'password'         => 'min:12|password_strength',
                'password_confirm' => 'same:password',
            ]);
            if ($v2->fails()) {
                Session::flash('errors', $v2->errors());
                return Response::redirect('/profile/edit');
            }
            $data['password'] = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $this->db->update('users', $data, 'id = ?', [$this->auth->id()]);
        $this->auth->refreshUser();
        $this->auth->auditLog('profile.update', 'users', $this->auth->id());

        return Response::redirect('/profile')->withFlash('success', 'Profile updated.');
    }

    /**
     * Update the user's theme preference (light / dark / system).
     *
     * Writes the chosen preference both to the users.theme_preference
     * column AND to a `theme_pref` cookie. Cookie carries the preference
     * for guests (not logged in) and acts as a server-readable hint at
     * the next request so the body class can be applied before paint -
     * avoiding the flash of wrong theme that would happen if the class
     * were only applied client-side.
     *
     * Cookie is intentionally NOT httponly (must be readable from JS for
     * any future client-side toggle that wants to update without a full
     * page navigation) and Lax SameSite (followed cross-site links should
     * preserve the visitor's choice).
     *
     * Returns to the referrer so the user lands back where they were
     * after the toggle. Falls back to /profile if the referrer is missing
     * or cross-origin.
     */
    public function updateTheme(Request $request): Response
    {
        $pref = (string) $request->post('theme_preference', 'system');
        if (!in_array($pref, ['system', 'light', 'dark'], true)) {
            $pref = 'system';
        }

        // Persist on the user row when logged in. Guests get cookie only.
        // Wrapped in try/catch so a missing theme_preference column (e.g.
        // the migration `2026_04_28_500000_add_theme_preference_to_users`
        // hasn't been run yet) doesn't break the toggle - the cookie
        // path keeps working until the migration lands, at which point
        // the DB write starts persisting too with no further changes.
        $userId = $this->auth->id();
        if ($userId) {
            try {
                $this->db->update('users', ['theme_preference' => $pref], 'id = ?', [$userId]);
            } catch (\Throwable $e) {
                error_log('[profile] theme_preference update failed (run the migration?): ' . $e->getMessage());
            }
        }

        // Cookie: 1 year, root path, Lax. Not httponly so JS can read it
        // (useful for future client-side toggle without page reload).
        setcookie('theme_pref', $pref, [
            'expires'  => time() + 365 * 24 * 3600,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);

        // Redirect back to where the user was. The referer is sanitised
        // through the same same-origin check used for form submissions.
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '/profile');
        $hostNow = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $parts   = @parse_url($back);
        if (!$parts || strtolower((string) ($parts['host'] ?? $hostNow)) !== $hostNow) {
            $back = '/profile';
        }
        return Response::redirect($back);
    }

}
