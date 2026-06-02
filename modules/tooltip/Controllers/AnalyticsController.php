<?php
// modules/tooltip/Controllers/AnalyticsController.php
namespace Modules\Tooltip\Controllers;

use Core\Database\Database;
use Core\Request;
use Core\Response;

/**
 * Usage rollup (the `analytics` submodule). Route registered only when the
 * submodule is on.
 *   GET /admin/tooltips/analytics
 *
 * The 3-table schema tracks a cumulative `view_count` per tooltip (no per-event
 * log), so this surfaces the top-by-views rollup + totals. A daily-trend chart
 * would need a per-event table — deferred.
 */
class AnalyticsController
{
    public function index(Request $request): Response
    {
        $db = Database::getInstance();
        $top = $db->fetchAll(
            "SELECT t.id, t.slug, t.label, t.view_count, t.is_active, c.name AS category_name
               FROM tooltips t LEFT JOIN tooltip_categories c ON c.id = t.category_id
              ORDER BY t.view_count DESC, t.id ASC LIMIT 50"
        );
        $totals = $db->fetchOne(
            "SELECT COALESCE(SUM(view_count),0) AS total_views, COUNT(*) AS total_tooltips,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
               FROM tooltips"
        ) ?: ['total_views' => 0, 'total_tooltips' => 0, 'active_count' => 0];

        return Response::view('tooltip::admin.analytics', ['top' => $top, 'totals' => $totals]);
    }
}
