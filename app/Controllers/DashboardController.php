<?php
// app/Controllers/DashboardController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;

/**
 * /dashboard — landing page for authenticated users.
 *
 * As of Batch 3 of the content-blocks rollout, all of this surface's
 * tiles are module-declared blocks rendered through the page composer
 * with two seeded system layouts (dashboard_stats + dashboard_main).
 * That means every block fetches its own data on demand — the
 * controller no longer needs to know about content tables, group
 * memberships, notification counts, or transfer requests.
 *
 * If you need to add a tile to the dashboard, declare a block in the
 * owning module's `blocks()` and place it on `dashboard_main` (or
 * dashboard_stats) via the page composer admin (or, for now, via SQL).
 */
class DashboardController
{
    public function index(Request $request): Response
    {
        $auth = Auth::getInstance();
        return Response::view('dashboard.index', [
            'user' => $auth->user(),
        ]);
    }
}
