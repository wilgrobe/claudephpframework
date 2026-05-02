<?php $pageTitle = 'Dashboard'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<?php
// Dashboard rendering moved to the page composer in Batch 3 of the
// content-blocks rollout. Two stacked layouts:
//
//   dashboard_stats — 4-col strip of stat cards
//   dashboard_main  — content feed (left) + sidebar (right)
//
// If the system_layouts table is missing (fresh install before
// migrations) or the layouts haven't been seeded, each composer call
// no-ops cleanly because the partial returns early on a null/empty
// composer envelope.
//
// The four stats blocks (`groups.my_groups_count`, `notifications.unread_count`,
// `groups.total_users_count`, `groups.total_count`) self-render based on the
// viewer's role; the admin-only ones return '' for non-admins, leaving
// those cells empty — same visual as the pre-composer dashboard.
$__sys = new \Core\Services\SystemLayoutService();
$composerContext = ['viewer' => $user, 'page' => null];

// ── Top stats strip ─────────────────────────────────────────────────────
$composer = $__sys->get('dashboard_stats');
include BASE_PATH . '/app/Views/partials/page_composer.php';

// ── Main grid: content + sidebar ────────────────────────────────────────
$composer = $__sys->get('dashboard_main');
include BASE_PATH . '/app/Views/partials/page_composer.php';
?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
