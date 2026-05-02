<?php
// modules/policies/Controllers/AdminPolicyController.php
namespace Modules\Policies\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Policies\Services\PolicyService;

/**
 * Admin endpoints:
 *   GET  /admin/policies                       — list all kinds
 *   GET  /admin/policies/{kindId}              — per-kind detail (history + stats)
 *   POST /admin/policies/{kindId}/source       — set source page
 *   POST /admin/policies/{kindId}/bump         — bump version (snapshot)
 *   POST /admin/policies/kinds                 — create custom kind
 *   POST /admin/policies/kinds/{kindId}/edit   — edit kind metadata
 *   POST /admin/policies/kinds/{kindId}/delete — delete (custom only)
 *   GET  /admin/policies/{kindId}/v/{vid}      — view a stored version
 */
class AdminPolicyController
{
    private Auth          $auth;
    private PolicyService $svc;
    private Database      $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new PolicyService();
        $this->db   = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $kinds = $this->svc->listKinds();

        // Acceptance ratio per kind for the dashboard column.
        foreach ($kinds as &$k) {
            if ($k['current_version_id']) {
                $k['stats'] = $this->svc->acceptanceStats((int) $k['current_version_id']);
            } else {
                $k['stats'] = ['accepted_users' => 0, 'total_users' => 0, 'ratio' => 0.0];
            }
        }
        unset($k);

        return Response::view('policies::admin.index', [
            'kinds' => $kinds,
            'user'  => $this->auth->user(),
        ]);
    }

    public function show(Request $request, int $kindId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $kind = $this->svc->findKind($kindId);
        if (!$kind) return Response::redirect('/admin/policies');

        $versions = $this->svc->listVersions($kindId);
        $stats    = $kind['current_version_id']
            ? $this->svc->acceptanceStats((int) $kind['current_version_id'])
            : null;

        // Page picker — list every published page so the admin can
        // pick the source. Filter to policy-flavoured slugs first;
        // fall back to all pages if the user wants a less standard one.
        $pages = $this->db->fetchAll(
            "SELECT id, title, slug, status FROM pages ORDER BY title ASC LIMIT 200"
        );

        return Response::view('policies::admin.show', [
            'kind'     => $kind,
            'versions' => $versions,
            'stats'    => $stats,
            'pages'    => $pages,
            'user'     => $this->auth->user(),
        ]);
    }

    public function setSource(Request $request, int $kindId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $pageId = (int) $request->post('source_page_id', 0) ?: null;
        $this->svc->setSourcePage($kindId, $pageId);

        $this->auth->auditLog('policies.source_changed', 'policy_kinds', $kindId, null, [
            'source_page_id' => $pageId,
        ]);

        return Response::redirect('/admin/policies/' . $kindId)
            ->withFlash('success', $pageId ? 'Source page assigned.' : 'Source page cleared.');
    }

    public function bumpVersion(Request $request, int $kindId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $label   = trim((string) $request->post('version_label', ''));
        $eff     = trim((string) $request->post('effective_date', '')) ?: null;
        $summary = trim((string) $request->post('summary', '')) ?: null;

        if ($label === '') {
            return Response::redirect('/admin/policies/' . $kindId)
                ->withFlash('error', 'Version label is required.');
        }

        try {
            $vId = $this->svc->bumpVersion($kindId, $label, $eff, $summary, (int) $this->auth->id());
            $this->auth->auditLog('policies.version_bumped', 'policy_versions', $vId, null, [
                'kind_id'        => $kindId,
                'version_label'  => $label,
                'effective_date' => $eff,
            ]);
            return Response::redirect('/admin/policies/' . $kindId)
                ->withFlash('success', "Version {$label} created. All users will see the re-acceptance modal on their next page view.");
        } catch (\Throwable $e) {
            return Response::redirect('/admin/policies/' . $kindId)
                ->withFlash('error', 'Bump failed: ' . $e->getMessage());
        }
    }

    public function showVersion(Request $request, int $kindId, int $versionId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $kind    = $this->svc->findKind($kindId);
        $version = $this->svc->findVersion($versionId);
        if (!$kind || !$version || (int) $version['kind_id'] !== $kindId) {
            return Response::redirect('/admin/policies');
        }

        $stats = $this->svc->acceptanceStats($versionId);

        // Sample of the most recent acceptances (admin reporting)
        $sample = $this->db->fetchAll("
            SELECT a.accepted_at, a.ip_address, a.user_agent,
                   u.id AS user_id, u.username, u.email
            FROM policy_acceptances a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.version_id = ?
            ORDER BY a.id DESC
            LIMIT 100
        ", [$versionId]);

        return Response::view('policies::admin.version_show', [
            'kind'    => $kind,
            'version' => $version,
            'stats'   => $stats,
            'sample'  => $sample,
            'user'    => $this->auth->user(),
        ]);
    }

    public function createKind(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $slug         = trim((string) $request->post('slug', ''));
        $label        = trim((string) $request->post('label', ''));
        $description  = trim((string) $request->post('description', '')) ?: null;
        $requires     = (bool) $request->post('requires_acceptance', false);

        if ($slug === '' || $label === '') {
            return Response::redirect('/admin/policies')
                ->withFlash('error', 'Slug and label are required.');
        }

        try {
            $id = $this->svc->createCustomKind($slug, $label, $description, $requires);
            $this->auth->auditLog('policies.kind_created', 'policy_kinds', $id, null, [
                'slug' => $slug, 'label' => $label,
            ]);
            return Response::redirect('/admin/policies/' . $id)
                ->withFlash('success', 'Policy kind created.');
        } catch (\Throwable $e) {
            return Response::redirect('/admin/policies')
                ->withFlash('error', 'Could not create kind: ' . $e->getMessage());
        }
    }

    public function deleteKind(Request $request, int $kindId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $kind = $this->svc->findKind($kindId);
        if (!$kind) return Response::redirect('/admin/policies');
        if ((int) $kind['is_system'] === 1) {
            return Response::redirect('/admin/policies/' . $kindId)
                ->withFlash('error', 'System policies cannot be deleted.');
        }

        $this->db->query("DELETE FROM policy_kinds WHERE id = ? AND is_system = 0", [$kindId]);
        $this->auth->auditLog('policies.kind_deleted', 'policy_kinds', $kindId);

        return Response::redirect('/admin/policies')
            ->withFlash('success', 'Policy kind deleted.');
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('policies.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage policies.');
        return Response::redirect('/admin');
    }
}
