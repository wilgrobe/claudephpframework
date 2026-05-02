<?php $pageTitle = '🛡️ Superadmin Dashboard'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="grid grid-4" style="margin-bottom:1.5rem">
    <?php foreach ([
        ['label'=>'Total Users',    'value'=>$stats['users'],          'icon'=>'👥'],
        ['label'=>'Total Groups',   'value'=>$stats['groups'],         'icon'=>'🏘️'],
        ['label'=>'Active Sessions','value'=>$stats['active_sessions'], 'icon'=>'🟢'],
        ['label'=>'Audit Events Today','value'=>$stats['audit_today'], 'icon'=>'📋'],
    ] as $s): ?>
    <div class="stat-card">
        <div style="font-size:1.5rem;margin-bottom:.25rem"><?= $s['icon'] ?></div>
        <div class="stat-label"><?= e($s['label']) ?></div>
        <div class="stat-value"><?= number_format($s['value']) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($stats['failed_emails'] > 0): ?>
<div class="alert alert-warning">⚠️ <?= $stats['failed_emails'] ?> email(s) failed to send today. <a href="/admin/superadmin/message-log?status=failed">View →</a></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Recent Audit Events</h2>
        <a href="/admin/superadmin/audit-log" class="btn btn-sm btn-secondary">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Time</th><th>Actor</th><th>Emulating</th><th>Action</th><th>Model</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $row): ?>
            <tr>
                <td style="color:#6b7280;font-size:12px;white-space:nowrap"><?= date('M j H:i', strtotime($row['created_at'])) ?></td>
                <td>
                    <?= e($row['actor_username'] ?? 'System') ?>
                    <?php if ($row['superadmin_mode']): ?><span class="badge badge-danger" style="font-size:9px">SA</span><?php endif; ?>
                </td>
                <td><?= $row['emulated_username'] ? '<span style="color:#dc2626">'.e($row['emulated_username']).'</span>' : '<span style="color:#9ca3af">—</span>' ?></td>
                <td><code style="font-size:12px;background:#f3f4f6;padding:.1rem .35rem;border-radius:4px"><?= e($row['action']) ?></code></td>
                <td style="font-size:12px;color:#6b7280"><?= $row['model'] ? e($row['model']).'#'.$row['model_id'] : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
