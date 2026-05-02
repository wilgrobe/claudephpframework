<?php
// modules/featureflags/Controllers/FeatureFlagAdminController.php
namespace Modules\FeatureFlags\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Modules\FeatureFlags\Services\FeatureFlagService;

/**
 *   GET  /admin/feature-flags                        — list flags
 *   GET  /admin/feature-flags/create                 — new
 *   POST /admin/feature-flags                        — upsert
 *   POST /admin/feature-flags/{key}/delete           — delete
 *   GET  /admin/feature-flags/{key}/overrides        — list + form
 *   POST /admin/feature-flags/{key}/overrides        — set
 *   POST /admin/feature-flags/{key}/overrides/{uid}/clear — clear
 */
class FeatureFlagAdminController
{
    private Auth               $auth;
    private Database           $db;
    private FeatureFlagService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
        $this->svc  = new FeatureFlagService();
    }

    private function gate(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        if (!$this->auth->can('featureflags.manage') && !$this->auth->can('admin.access')) {
            return new Response('Forbidden', 403);
        }
        return null;
    }

    public function index(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        return Response::view('feature_flags::admin.index', [
            'flags'  => $this->svc->allFlags(),
            'groups' => $this->db->fetchAll("SELECT id, name FROM `groups` ORDER BY name ASC"),
        ]);
    }

    public function createForm(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        return Response::view('feature_flags::admin.form', [
            'flag'   => null,
            'groups' => $this->db->fetchAll("SELECT id, name FROM `groups` ORDER BY name ASC"),
        ]);
    }

    public function upsert(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        try {
            $this->svc->upsertFlag([
                'key'             => (string) $request->post('key'),
                'label'           => (string) $request->post('label'),
                'description'     => (string) $request->post('description'),
                'enabled'         => (bool) $request->post('enabled'),
                'rollout_percent' => (int) $request->post('rollout_percent', 100),
                'group_ids'       => (array) ($request->post('group_ids') ?: []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Response::redirect('/admin/feature-flags')->withFlash('error', $e->getMessage());
        }
        return Response::redirect('/admin/feature-flags')->withFlash('success', 'Saved.');
    }

    public function edit(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $key = (string) $request->param(0);
        $flag = $this->svc->findFlag($key);
        if (!$flag) return new Response('Not found', 404);
        return Response::view('feature_flags::admin.form', [
            'flag'   => $flag,
            'groups' => $this->db->fetchAll("SELECT id, name FROM `groups` ORDER BY name ASC"),
        ]);
    }

    public function delete(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $this->svc->deleteFlag((string) $request->param(0));
        return Response::redirect('/admin/feature-flags');
    }

    public function overrides(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $key = (string) $request->param(0);
        $flag = $this->svc->findFlag($key);
        if (!$flag) return new Response('Not found', 404);
        return Response::view('feature_flags::admin.overrides', [
            'flag'      => $flag,
            'overrides' => $this->svc->overridesFor($key),
        ]);
    }

    public function setOverride(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $key    = (string) $request->param(0);
        $userId = (int) $request->post('user_id');
        if ($userId <= 0) {
            return Response::redirect("/admin/feature-flags/$key/overrides")->withFlash('error', 'user_id required.');
        }
        $this->svc->setOverride(
            $key, $userId,
            (bool) $request->post('enabled'),
            (string) $request->post('note') ?: null
        );
        return Response::redirect("/admin/feature-flags/$key/overrides");
    }

    public function clearOverride(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $key    = (string) $request->param(0);
        $userId = (int) $request->param(1);
        $this->svc->clearOverride($key, $userId);
        return Response::redirect("/admin/feature-flags/$key/overrides");
    }
}
