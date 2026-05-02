<?php
// app/Controllers/ModuleAdminController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Module\ModuleRegistry;
use Core\Request;
use Core\Response;

/**
 * /admin/modules — superadmin-facing roster of every discovered module
 * with its current runtime state. This is the surface SAs land on after
 * receiving the "Module X disabled" notification: it shows what's broken,
 * what dependency is missing, and (later batches) lets them admin-disable
 * a working module deliberately.
 *
 * Read-only in this batch. Write actions (enable / disable / repair) come
 * in a future pass once the module-status table has more lifecycle hooks.
 */
class ModuleAdminController
{
    private Auth           $auth;
    private ModuleRegistry $modules;
    private Database       $db;

    public function __construct()
    {
        $this->auth    = Auth::getInstance();
        $this->modules = \Core\Container\Container::global()->get(ModuleRegistry::class);
        $this->db      = Database::getInstance();
    }

    /**
     * Roster page. Joins the in-memory registry (every discovered
     * module + its declared `requires()`) against the persisted
     * module_status table (last-known state + last_status_change).
     * Modules that are "active" + never previously disabled simply
     * show up with no status row — that's normal.
     */
    public function index(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) {
            return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
        }

        $statusRows = $this->safeStatusFetch();
        $statusByName = [];
        foreach ($statusRows as $r) {
            $statusByName[$r['module_name']] = $r;
        }

        $skipped     = $this->modules->skippedModules();
        $unlicensed  = $this->modules->unlicensedModules();

        $list = [];
        foreach ($this->modules->all() as $name => $provider) {
            $row             = $statusByName[$name] ?? null;
            $isSkipped       = isset($skipped[$name]);
            $isUnlicensed    = isset($unlicensed[$name]);
            $derivedState    = $isUnlicensed
                ? 'disabled_unlicensed'
                : ($isSkipped ? 'disabled_dependency' : 'active');
            $missing         = $isSkipped
                ? $skipped[$name]['missing']
                : ($isUnlicensed ? $unlicensed[$name]['missing'] : []);
            $list[] = [
                'name'        => $name,
                'tier'        => $provider->tier(),
                'state'       => $row['state'] ?? $derivedState,
                'requires'    => $provider->requires(),
                'missing'     => $missing,
                'has_blocks'  => !empty($provider->blocks()),
                'updated_at'  => $row['updated_at'] ?? null,
                'notice'      => $row['notice'] ?? null,
            ];
        }
        usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));

        return Response::view('admin.modules.index', [
            'modules' => $list,
            'roots'   => $this->modules->roots(),
            'user'    => $this->auth->user(),
        ]);
    }

    /**
     * POST /admin/modules/{name}/disable
     *
     * Admin-disable lifecycle: marks the module 'disabled_admin' in
     * module_status. The dep checker on the next request filters it
     * out of the candidates set, which means any module that requires
     * it cascades into 'disabled_dependency' automatically.
     *
     * Doesn't refuse on "but I disabled the module that's currently
     * rendering this admin page" — `/admin/modules` is in core, not
     * in a module, so it stays reachable. Worst case, a future
     * "disable everything" experiment leaves an SA on a clean blank
     * dashboard with the modules page still working.
     */
    public function disable(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) {
            return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
        }
        $name = (string) $request->param(0);

        $changed = $this->modules->disableByAdmin($name, (string) $request->post('note', ''));
        if (!$changed) {
            return Response::redirect('/admin/modules')
                ->withFlash('info', "\"$name\" was already disabled or isn't installed.");
        }
        return Response::redirect('/admin/modules')
            ->withFlash('success', "Module \"$name\" disabled. Modules that depend on it will cascade to disabled_dependency on the next request.");
    }

    /**
     * POST /admin/modules/{name}/enable
     *
     * Reverses an admin-disable. The module returns to 'active' in
     * module_status; the dep checker may immediately promote it to
     * 'disabled_dependency' on the next request if its requires() now
     * point at something that isn't installed.
     */
    public function enable(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) {
            return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
        }
        $name = (string) $request->param(0);

        $changed = $this->modules->enableByAdmin($name);
        if (!$changed) {
            return Response::redirect('/admin/modules')
                ->withFlash('info', "\"$name\" wasn't admin-disabled. Use the dependency-fix path for module dependency issues.");
        }
        return Response::redirect('/admin/modules')
            ->withFlash('success', "Module \"$name\" re-enabled.");
    }

    /**
     * Read module_status defensively: a fresh install before migrations
     * have run won't have the table yet. We want the page to still load
     * (showing "active" for everything based on the in-memory registry)
     * rather than 500 on a missing table.
     */
    private function safeStatusFetch(): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT module_name, state, missing_deps, notice, updated_at
                   FROM module_status
                  ORDER BY module_name"
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
