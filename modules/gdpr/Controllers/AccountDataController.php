<?php
// modules/gdpr/Controllers/AccountDataController.php
namespace Modules\Gdpr\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Gdpr\Services\DataExporter;
use Modules\Gdpr\Services\DataPurger;
use Modules\Gdpr\Services\DsarService;

/**
 * User-facing GDPR self-service: /account/data
 *
 *   GET  /account/data                — dashboard with export/erase/restrict
 *   POST /account/data/export         — kick off an export (sync for now;
 *                                       use the queued BuildExportJob in
 *                                       prod once the job worker is running)
 *   GET  /account/data/download/{tok} — fetch a built export by token
 *   POST /account/data/erase          — request account erasure (begins
 *                                       30-day grace window)
 *   GET  /account/data/erase/cancel/{tok}
 *                                     — cancel pending erasure (signed link)
 *   POST /account/data/restrict       — toggle GDPR Art. 18 processing
 *                                       restriction
 */
class AccountDataController
{
    private Auth         $auth;
    private Database     $db;
    private DataExporter $exporter;
    private DataPurger   $purger;
    private DsarService  $dsar;

    public function __construct()
    {
        $this->auth     = Auth::getInstance();
        $this->db       = Database::getInstance();
        $this->exporter = new DataExporter();
        $this->purger   = new DataPurger();
        $this->dsar     = new DsarService();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId = (int) $this->auth->id();
        $user   = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);

        $exports = $this->db->fetchAll(
            "SELECT * FROM data_exports WHERE user_id = ? ORDER BY id DESC LIMIT 5",
            [$userId]
        );

        $myDsar = $this->db->fetchAll(
            "SELECT * FROM dsar_requests WHERE user_id = ? ORDER BY id DESC LIMIT 5",
            [$userId]
        );

        return Response::view('gdpr::account.index', [
            'user'    => $user,
            'exports' => $exports,
            'dsar'    => $myDsar,
        ]);
    }

    public function exportStart(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId = (int) $this->auth->id();

        // Rate-limit: don't let one user spam exports. Reject if a
        // ready/building export was made in the last hour.
        $recent = $this->db->fetchOne(
            "SELECT id FROM data_exports
             WHERE user_id = ? AND requested_at > NOW() - INTERVAL 1 HOUR
               AND status IN ('building','ready')",
            [$userId]
        );
        if ($recent) {
            return Response::redirect('/account/data')
                ->withFlash('error', 'You already have a recent export. Download it below or wait an hour.');
        }

        try {
            $exportId = $this->exporter->buildForUser($userId);
            $this->auth->auditLog('gdpr.export.requested', 'data_exports', $exportId);
            return Response::redirect('/account/data')
                ->withFlash('success', 'Your data export is ready. Click Download below to fetch it.');
        } catch (\Throwable $e) {
            error_log('GDPR export failed for user ' . $userId . ': ' . $e->getMessage());
            return Response::redirect('/account/data')
                ->withFlash('error', 'Could not build your export right now. Please try again, or contact support if the problem persists.');
        }
    }

    public function download(Request $request, string $token): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $row = $this->exporter->findByToken($token);
        if (!$row || (int) $row['user_id'] !== (int) $this->auth->id()) {
            return Response::view('errors.404', [], 404);
        }

        if (!$row['file_path'] || !file_exists($row['file_path'])) {
            Session::flash('error', 'The export file is no longer available.');
            return Response::redirect('/account/data');
        }

        $this->auth->auditLog('gdpr.export.downloaded', 'data_exports', (int) $row['id']);

        // Stream the zip — sets Content-Disposition so the browser
        // downloads rather than displays. The actual streaming is
        // delegated to the framework's Response::file helper if it
        // exists; otherwise emit headers + readfile().
        $filename = basename((string) $row['file_path']);
        if (method_exists(Response::class, 'file')) {
            return Response::file((string) $row['file_path'], $filename, 'application/zip');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (int) ($row['file_size'] ?? filesize($row['file_path'])));
        readfile((string) $row['file_path']);
        exit;
    }

    public function eraseRequest(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId = (int) $this->auth->id();
        $confirm = (string) $request->post('confirm', '');
        if (strtolower(trim($confirm)) !== 'delete my account') {
            return Response::redirect('/account/data')
                ->withFlash('error', 'Please type "delete my account" exactly to confirm.');
        }

        // Begin grace window. 30 days configurable; an admin can set
        // gdpr_erasure_grace_days = 0 to fire the purge immediately.
        $graceDays = max(0, (int) (setting('gdpr_erasure_grace_days', 30) ?? 30));
        $token     = bin2hex(random_bytes(32));

        $this->db->update('users', [
            'deletion_requested_at' => date('Y-m-d H:i:s'),
            'deletion_grace_until'  => date('Y-m-d H:i:s', time() + $graceDays * 86400),
            'deletion_token'        => $token,
        ], 'id = ?', [$userId]);

        // Open a DSAR record so admins can track the action.
        $user  = $this->db->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
        $dsarId = $this->dsar->create('erasure', (string) ($user['email'] ?? ''), $userId, 'self_service');

        $this->auth->auditLog('gdpr.erasure.requested', 'users', $userId, null, [
            'grace_days' => $graceDays,
            'dsar_id'    => $dsarId,
        ]);

        // For grace_days=0, purge immediately + log out.
        if ($graceDays === 0) {
            try {
                $this->purger->purge($userId, $userId);
                $this->dsar->setStatus($dsarId, 'completed', null, 'Self-service erasure (grace=0).');
                Session::clear();
                return Response::redirect('/')
                    ->withFlash('success', 'Your account has been deleted. We\'re sorry to see you go.');
            } catch (\Throwable $e) {
                error_log('Immediate erasure failed for user ' . $userId . ': ' . $e->getMessage());
                return Response::redirect('/account/data')
                    ->withFlash('error', 'Erasure failed unexpectedly. Please contact support.');
            }
        }

        return Response::redirect('/account/data')
            ->withFlash('success', "Your account is scheduled for deletion in {$graceDays} days. Check your email for a cancel link.");
    }

    public function eraseCancel(Request $request, string $token): Response
    {
        // Cancel via signed link — works whether the user is signed in
        // or not (the token IS the proof of identity). We do still
        // require login to complete it, so an attacker who steals the
        // link can't use it without also having the account password.
        $row = $this->db->fetchOne(
            "SELECT id FROM users
             WHERE deletion_token = ? AND deletion_grace_until > NOW()",
            [$token]
        );
        if (!$row) {
            return Response::redirect('/login')
                ->withFlash('error', 'That cancel link is invalid or has expired.');
        }

        $userId = (int) $row['id'];
        if ($this->auth->guest() || (int) $this->auth->id() !== $userId) {
            return Response::redirect('/login')
                ->withFlash('error', 'Please sign in to cancel your account deletion.');
        }

        $this->db->update('users', [
            'deletion_requested_at' => null,
            'deletion_grace_until'  => null,
            'deletion_token'        => null,
        ], 'id = ?', [$userId]);

        $this->auth->auditLog('gdpr.erasure.cancelled', 'users', $userId);

        return Response::redirect('/account/data')
            ->withFlash('success', 'Your account deletion has been cancelled.');
    }

    public function restrict(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId = (int) $this->auth->id();
        $user   = $this->db->fetchOne("SELECT processing_restricted_at FROM users WHERE id = ?", [$userId]);
        if (!$user) return Response::redirect('/account/data');

        $isRestricted = !empty($user['processing_restricted_at']);
        $newValue     = $isRestricted ? null : date('Y-m-d H:i:s');

        $this->db->update('users', [
            'processing_restricted_at' => $newValue,
        ], 'id = ?', [$userId]);

        $this->auth->auditLog(
            $isRestricted ? 'gdpr.restriction.lifted' : 'gdpr.restriction.applied',
            'users',
            $userId
        );

        $msg = $isRestricted
            ? 'Processing restriction lifted — your account is back to normal.'
            : 'Processing has been restricted on your account. Non-essential writes are now blocked.';
        return Response::redirect('/account/data')->withFlash('success', $msg);
    }
}
