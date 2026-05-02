<?php
// modules/retention/Controllers/AdminRetentionController.php
namespace Modules\Retention\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Retention\Services\RetentionService;

/**
 * Admin endpoints for /admin/retention:
 *
 *   GET  /admin/retention                         — list of rules + stats
 *   POST /admin/retention/sync                    — re-discover rules from modules
 *   GET  /admin/retention/{id}                    — per-rule detail + run history
 *   POST /admin/retention/{id}/edit               — update days_keep, action, enabled
 *   POST /admin/retention/{id}/preview            — count rows that WOULD be affected
 *   POST /admin/retention/{id}/run                — run this rule now
 *   POST /admin/retention/run-all                 — run every enabled rule
 */
class AdminRetentionController
{
    private Auth             $auth;
    private RetentionService $svc;
    private Database         $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new RetentionService();
        $this->db   = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        // Sync on first visit so a fresh install populates the rules
        // list from the module declarations without an extra step.
        $this->svc->sync();

        $rules = $this->svc->listRules();

        // Backtick `purge` because it's a MySQL reserved word
        // (PURGE BINARY LOGS / PURGE MASTER LOGS) — bare alias parses
        // as a keyword and 1064s.
        $totals = $this->db->fetchOne("
            SELECT
              COUNT(*) AS total,
              SUM(is_enabled = 1) AS enabled,
              SUM(action = 'anonymize') AS anonymize,
              SUM(action = 'purge') AS `purge`
            FROM retention_rules
        ") ?: [];

        $recent = $this->db->fetchAll("
            SELECT r.*, rule.label AS rule_label, rule.module AS rule_module
            FROM retention_runs r
            JOIN retention_rules rule ON rule.id = r.rule_id
            ORDER BY r.id DESC
            LIMIT 25
        ");

        return Response::view('retention::admin.index', [
            'rules'  => $rules,
            'totals' => $totals,
            'recent' => $recent,
            'user'   => $this->auth->user(),
        ]);
    }

    public function sync(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();
        $n = $this->svc->sync();
        $this->auth->auditLog('retention.synced', null, null, null, ['inserted' => $n]);
        return Response::redirect('/admin/retention')
            ->withFlash('success', $n > 0 ? "Discovered {$n} new rule(s) from modules." : "No new rules — all module declarations already in the registry.");
    }

    public function show(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $rule = $this->svc->findRule($id);
        if (!$rule) return Response::redirect('/admin/retention');

        $runs = $this->svc->recentRuns($id, 100);

        return Response::view('retention::admin.show', [
            'rule' => $rule,
            'runs' => $runs,
            'user' => $this->auth->user(),
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $days     = (int) $request->post('days_keep', 0);
        $action   = (string) $request->post('action', 'purge');
        $enabled  = (bool) $request->post('is_enabled', false);

        if ($days < 0) $days = 0;
        $this->svc->updateRule($id, $days, $action, $enabled);

        $this->auth->auditLog('retention.rule_updated', 'retention_rules', $id, null, [
            'days_keep'  => $days,
            'action'     => $action,
            'is_enabled' => $enabled,
        ]);

        return Response::redirect('/admin/retention/' . $id)
            ->withFlash('success', 'Rule updated.');
    }

    public function preview(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        try {
            $count = $this->svc->preview($id);
            return Response::redirect('/admin/retention/' . $id)
                ->withFlash('info', "Preview: {$count} row(s) would be affected if this rule ran now.");
        } catch (\Throwable $e) {
            return Response::redirect('/admin/retention/' . $id)
                ->withFlash('error', 'Preview failed: ' . $e->getMessage());
        }
    }

    public function run(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        try {
            $stats = $this->svc->runOne($id, (int) $this->auth->id(), false);
            $this->auth->auditLog('retention.rule_run', 'retention_rules', $id, null, $stats);
            return Response::redirect('/admin/retention/' . $id)
                ->withFlash('success', "Rule executed. {$stats['rows_affected']} row(s) affected in {$stats['duration_ms']}ms.");
        } catch (\Throwable $e) {
            return Response::redirect('/admin/retention/' . $id)
                ->withFlash('error', 'Run failed: ' . $e->getMessage());
        }
    }

    public function runAll(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        try {
            $stats = $this->svc->runAll((int) $this->auth->id(), false);
            $this->auth->auditLog('retention.run_all', null, null, null, $stats);
            return Response::redirect('/admin/retention')
                ->withFlash('success', sprintf(
                    'Run complete: %d rules processed, %d rows affected, %d errors.',
                    $stats['rules_run'], $stats['rows_affected'], $stats['errors']
                ));
        } catch (\Throwable $e) {
            return Response::redirect('/admin/retention')
                ->withFlash('error', 'Run failed: ' . $e->getMessage());
        }
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('retention.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage retention.');
        return Response::redirect('/admin');
    }
}
