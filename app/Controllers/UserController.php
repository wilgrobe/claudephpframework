<?php
// app/Controllers/UserController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Database\Database;
use App\Models\{User, Role};

class UserController
{
    private User     $userModel;
    private Role     $roleModel;
    private Auth     $auth;
    private Database $db;

    public function __construct()
    {
        $this->userModel = new User();
        $this->roleModel = new Role();
        $this->auth      = Auth::getInstance();
        $this->db        = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->cannot('users.view')) return $this->denied();

        $search = $request->query('q', '');
        $page   = max(1, (int) $request->query('page', 1));

        $sql = "SELECT u.* FROM users u WHERE 1=1";
        $b   = [];
        if ($search) {
            // SQL-L1 — escape LIKE wildcards (bound value; wildcard hygiene).
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $sql .= " AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
            $b = ["%$search%", "%$search%", "%$search%"];
        }

        $data  = $this->db->paginate("$sql ORDER BY u.created_at DESC", $b, $page, 25);
        $users = $data['items'];

        // Batch-load roles for all users on this page (single query, avoids N+1)
        if (!empty($users)) {
            $userIds      = array_map('intval', array_column($users, 'id'));
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $rows = $this->db->fetchAll(
                "SELECT ur.user_id, r.*
                 FROM roles r
                 JOIN user_roles ur ON ur.role_id = r.id
                 WHERE ur.user_id IN ($placeholders)",
                $userIds
            );
            $rolesByUser = [];
            foreach ($rows as $row) {
                $uid = (int) $row['user_id'];
                unset($row['user_id']);
                $rolesByUser[$uid][] = $row;
            }
            foreach ($users as &$u) {
                $u['roles_list'] = $rolesByUser[(int) $u['id']] ?? [];
            }
            unset($u);
        }

        return Response::view('admin.users.index', [
            'users'      => $users,
            'pagination' => $data,
            'search'     => $search,
            'user'       => $this->auth->user(),
        ]);
    }

    public function show(Request $request): Response
    {
        if ($this->auth->cannot('users.view')) return $this->denied();
        $id          = (int) $request->param(0);
        $user_detail = $this->userModel->findById($id);
        if (!$user_detail) return new Response('User not found', 404);
        $user_detail = $this->userModel->withDetails($user_detail);
        return Response::view('admin.users.show', [
            'user_detail' => $user_detail,
            'user'        => $this->auth->user(),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($this->auth->cannot('users.create')) return $this->denied();
        return Response::view('admin.users.form', [
            'user_edit'      => null,
            'all_roles'      => $this->roleModel->all(),
            'assigned_roles' => [],
            'user'           => $this->auth->user(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($this->auth->cannot('users.create')) return $this->denied();

        $v = new Validator($request->post());
        $v->validate([
            'first_name' => 'required|min:1|max:100',
            'last_name'  => 'required|min:1|max:100',
            'email'      => 'required|email|max:255',
            'password'   => 'required|min:12|password_strength',
        ]);

        if (!$v->fails()) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ?", [$v->get('email')]);
            if ($existing) {
                Session::flash('errors', ['email' => ['Email already in use.']]);
                Session::flash('old', $v->all());
                return Response::redirect('/admin/users/create');
            }
        }

        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            Session::flash('old', $v->all());
            return Response::redirect('/admin/users/create');
        }

        // HIBP breach check on the new user's password — even when an
        // admin is creating the account, the user will inherit the
        // password and shouldn't be saddled with a breached one.
        if (class_exists(\Modules\Security\Services\PasswordBreachService::class)) {
            $breachErr = (new \Modules\Security\Services\PasswordBreachService())
                ->validateOrError((string) $v->get('password'));
            if ($breachErr !== null) {
                Session::flash('errors', ['password' => [$breachErr]]);
                $old = $v->all();
                unset($old['password']);
                Session::flash('old', $old);
                return Response::redirect('/admin/users/create');
            }
        }

        // Username: accept a typed one (validated + unique) or auto-derive
        // from email/name. Admin-created users previously had a NULL
        // username, which surfaces as "[deleted]" on forum / social /
        // comment bylines — so always assign one here, mirroring what
        // self-registration does via the same UsernameSuggester service.
        $suggester     = new \Core\Services\UsernameSuggester($this->db);
        $typedUsername = trim((string) $request->post('username', ''));
        if ($typedUsername !== '') {
            $unameErr = $suggester->validate($typedUsername);
            if ($unameErr === null && !$suggester->isAvailable($typedUsername)) {
                $unameErr = 'That username is already taken.';
            }
            if ($unameErr !== null) {
                Session::flash('errors', ['username' => [$unameErr]]);
                Session::flash('old', $v->all());
                return Response::redirect('/admin/users/create');
            }
            $username = strtolower($typedUsername);
        } else {
            $username = $suggester->suggestOne(
                $v->get('email'),
                $v->get('first_name'),
                $v->get('last_name')
            ) ?? ('user' . substr((string) time(), -6));
        }

        // Email verification: an admin creating a user is vouching for them,
        // so default to verified. Without this, when `require_email_verify` is
        // on, every admin-created account (typically a non-deliverable seed /
        // demo / test address) is locked out of login forever — there's no
        // inbox to click the link in. The form ships the checkbox checked; the
        // paired hidden input makes an unchecked box post "0" reliably.
        $emailVerified = (bool) $request->post('email_verified', '1');

        $userId = $this->userModel->create([
            'username'    => $username,
            'first_name'  => $v->get('first_name'),
            'last_name'   => $v->get('last_name'),
            'email'       => strtolower($v->get('email')),
            'password'    => $request->post('password'),
            'phone'       => $v->get('phone'),
            'is_active'   => (int) $request->post('is_active', 1),
            'is_superadmin' => $this->auth->isSuperadminModeOn() ? (int) $request->post('is_superadmin', 0) : 0,
            'email_verified_at' => $emailVerified ? date('Y-m-d H:i:s') : null,
        ]);

        $roleIds = array_filter((array) $request->post('roles', []));
        if ($roleIds) $this->userModel->syncRoles($userId, $roleIds);

        $this->auth->auditLog('user.create', 'users', $userId);
        return Response::redirect('/admin/users')->withFlash('success', 'User created.');
    }

    public function edit(Request $request): Response
    {
        if ($this->auth->cannot('users.edit')) return $this->denied();
        $id        = (int) $request->param(0);
        $user_edit = $this->userModel->findById($id);
        if (!$user_edit) return new Response('User not found', 404);

        return Response::view('admin.users.form', [
            'user_edit'      => $user_edit,
            'all_roles'      => $this->roleModel->all(),
            'assigned_roles' => $this->userModel->getRoles($id),
            'user'           => $this->auth->user(),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($this->auth->cannot('users.edit')) return $this->denied();
        $id = (int) $request->param(0);

        $v = new Validator($request->post());
        $v->validate([
            'first_name' => 'required|min:1|max:100',
            'last_name'  => 'required|min:1|max:100',
            'email'      => 'required|email',
        ]);

        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect("/admin/users/$id/edit");
        }

        $updateData = [
            'first_name' => $v->get('first_name'),
            'last_name'  => $v->get('last_name'),
            'email'      => strtolower($v->get('email')),
            'phone'      => $v->get('phone'),
            'is_active'  => (int) $request->post('is_active', 0),
        ];

        // Email-verified toggle — lets an admin mark a manually-created /
        // broken-mailbox account as verified (so it can log in under
        // `require_email_verify`) or revoke it. Preserve the existing
        // timestamp when it's already verified + stays checked so we don't
        // churn the audit-meaningful "when verified" value; stamp NOW() on a
        // false→true flip; clear to NULL when unchecked.
        $wantVerified = (bool) $request->post('email_verified', '0');
        $currentVerifiedAt = $this->userModel->findById($id)['email_verified_at'] ?? null;
        $updateData['email_verified_at'] = $wantVerified
            ? ($currentVerifiedAt ?: date('Y-m-d H:i:s'))
            : null;

        if ($pass = $request->post('password')) {
            // Breach check on the proposed new password. Same fail-open
            // policy as registration; setting controls block-vs-warn.
            if (class_exists(\Modules\Security\Services\PasswordBreachService::class)) {
                $breachErr = (new \Modules\Security\Services\PasswordBreachService())
                    ->validateOrError((string) $pass);
                if ($breachErr !== null) {
                    Session::flash('errors', ['password' => [$breachErr]]);
                    return Response::redirect("/admin/users/$id/edit");
                }
            }
            $updateData['password'] = $pass;
        }

        if ($this->auth->isSuperadminModeOn()) {
            $updateData['is_superadmin'] = (int) $request->post('is_superadmin', 0);
        }

        // Username (optional on edit): only change it when the admin typed a
        // value. Blank leaves the existing username untouched — so this also
        // lets an admin assign a username to a legacy account that never had
        // one (e.g. fixing a "[deleted]" forum byline).
        $typedUsername = trim((string) $request->post('username', ''));
        if ($typedUsername !== '') {
            $suggester = new \Core\Services\UsernameSuggester($this->db);
            $unameErr  = $suggester->validate($typedUsername);
            if ($unameErr === null && !$suggester->isAvailable($typedUsername, $id)) {
                $unameErr = 'That username is already taken.';
            }
            if ($unameErr !== null) {
                Session::flash('errors', ['username' => [$unameErr]]);
                return Response::redirect("/admin/users/$id/edit");
            }
            $updateData['username'] = strtolower($typedUsername);
        }

        $old = $this->userModel->findById($id);
        $this->userModel->update($id, $updateData);

        $roleIds = array_filter((array) $request->post('roles', []));
        $this->userModel->syncRoles($id, $roleIds);

        // SA promotion side-effect: if is_superadmin flipped 0→1
        // through this form, run the block-cleanup + notification
        // dispatch. Mirrors the same hook fired from
        // Admin\SuperadminController::toggleUserSuperadmin so both
        // paths into "you are now a System Admin" stay consistent.
        if (array_key_exists('is_superadmin', $updateData)
            && (int) ($old['is_superadmin'] ?? 0) === 0
            && (int) $updateData['is_superadmin'] === 1
            && class_exists(\Modules\Block\Services\BlockService::class)) {
            (new \Modules\Block\Services\BlockService())->handlePromotionToSA($id);
        }

        $this->auth->auditLog('user.update', 'users', $id, $old, $updateData);
        return Response::redirect("/admin/users/$id")->withFlash('success', 'User updated.');
    }

    public function delete(Request $request): Response
    {
        if ($this->auth->cannot('users.delete')) return $this->denied();
        $id = (int) $request->param(0);
        if ($id === $this->auth->id()) {
            return Response::redirect('/admin/users')->withFlash('error', 'Cannot delete your own account.');
        }
        $this->userModel->delete($id);
        $this->auth->auditLog('user.delete', 'users', $id);
        return Response::redirect('/admin/users')->withFlash('success', 'User deleted.');
    }

    /**
     * Superadmin action: stamp email_verified_at = NOW() directly,
     * bypassing the email-click flow entirely. The route is also gated
     * by RequireSuperadmin middleware; this duplicate check is the
     * controller's belt-and-suspenders. Useful for trusted manually-
     * created users, recovering accounts whose mail address is broken,
     * and as a faster path during QA than the SQL workaround it
     * replaces. Audit-logged so there's always a trail of who bypassed.
     */
    public function markEmailVerified(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $id   = (int) $request->param(0);
        $back = "/admin/users/$id";

        $user = $this->userModel->findById($id);
        if (!$user) {
            return Response::redirect('/admin/users')->withFlash('error', 'User not found.');
        }
        if (!empty($user['email_verified_at'])) {
            return Response::redirect($back)
                ->withFlash('info', 'This user is already verified.');
        }

        $this->db->query(
            "UPDATE users SET email_verified_at = NOW() WHERE id = ?",
            [(int) $user['id']]
        );

        // Also mark any outstanding verification token as used so the
        // emailed link (if it's still in their inbox) can't be redeemed
        // later — keeps the email_verifications table consistent with
        // "this address is already verified" rather than leaving a live
        // token hanging around.
        $this->db->query(
            "UPDATE email_verifications
                SET used_at = NOW()
              WHERE user_id = ? AND used_at IS NULL",
            [(int) $user['id']]
        );

        $this->auth->auditLog('auth.verification_marked_by_admin', 'users', (int) $user['id'], null, [
            'admin_id' => $this->auth->id(),
        ]);

        return Response::redirect($back)
            ->withFlash('success', 'Email verified for ' . $user['email'] . '.');
    }

    /**
     * Admin action: re-issue a verification email for a user whose
     * email_verified_at is still NULL. Useful when require_email_verify
     * is on and the original link expired (24h TTL) without being clicked.
     * Refuses for already-verified users so a stray button click can't
     * accidentally invalidate a clean record.
     */
    public function resendVerification(Request $request): Response
    {
        if ($this->auth->cannot('users.manage')) return $this->denied();
        $id   = (int) $request->param(0);
        $back = "/admin/users/$id";

        $user = $this->userModel->findById($id);
        if (!$user) {
            return Response::redirect('/admin/users')->withFlash('error', 'User not found.');
        }

        if (!empty($user['email_verified_at'])) {
            return Response::redirect($back)
                ->withFlash('info', 'This user is already verified.');
        }

        if (empty($user['email'])) {
            return Response::redirect($back)
                ->withFlash('error', 'This user has no email on file.');
        }

        try {
            (new \Core\Services\EmailVerificationService())->send((int) $user['id'], (string) $user['email']);
            $this->auth->auditLog('auth.verification_resent_by_admin', 'users', (int) $user['id'], null, [
                'admin_id' => $this->auth->id(),
            ]);
            return Response::redirect($back)
                ->withFlash('success', 'Verification email sent to ' . $user['email'] . '.');
        } catch (\Throwable $e) {
            error_log('[admin.resend_verification] failed: ' . $e->getMessage());
            return Response::redirect($back)
                ->withFlash('error', 'Could not send the verification email — check the mail driver config.');
        }
    }

    private function denied(): Response
    {
        return Response::redirect('/dashboard')->withFlash('error', 'Access denied.');
    }
}
