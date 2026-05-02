<?php $pageTitle = 'Audit Log'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<!-- Filters -->
<div class="card" style="padding:1rem 1.25rem;margin-bottom:1rem">
    <form method="GET" action="/admin/superadmin/audit-log" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:160px">
            <label for="user" style="font-size:12px">Actor Username</label>
            <input type="text" name="user" class="form-control" value="<?= e($filter['user'])?>" placeholder="username" aria-label="username">
        </div>
        <div class="form-group" style="margin:0;min-width:200px">
            <label for="action" style="font-size:12px">Action</label>
            <input type="text" name="action" class="form-control" value="<?= e($filter['action'])?>" placeholder="e.g. group.create" aria-label="e.g. group.create">
        </div>
        <div class="form-group" style="margin:0;min-width:140px">
            <label for="model" style="font-size:12px">Model</label>
            <input type="text" name="model" class="form-control" value="<?= e($filter['model'])?>" placeholder="users, groups…" aria-label="users, groups…">
        </div>
        <div class="form-group" style="margin:0">
            <label for="date" style="font-size:12px">Date</label>
            <input type="date" name="date" class="form-control" value="<?= e($filter['date'])?>" aria-label="Date">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="/admin/superadmin/audit-log" class="btn btn-secondary btn-sm">Clear</a>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Audit Log</h2>
        <span style="color:#6b7280;font-size:13px"><?= number_format($log['total']) ?> entries</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Time</th><th>Actor</th><th>Emulating</th><th>SA Mode</th><th>Action</th><th>Target</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php foreach ($log['items'] as $row): ?>
            <tr>
                <td style="white-space:nowrap;font-size:12px;color:#6b7280"><?= date('M j Y H:i:s', strtotime($row['created_at'])) ?></td>
                <td style="font-size:13px"><?= e($row['actor_username'] ?? '—') ?></td>
                <td>
                    <?php if ($row['emulated_username']): ?>
                    <span style="color:#dc2626;font-size:13px">⚠️ <?= e($row['emulated_username']) ?></span>
                    <?php else: ?>
                    <span style="color:#9ca3af">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $row['superadmin_mode'] ? '<span class="badge badge-danger">ON</span>' : '' ?></td>
                <td><code style="font-size:11px;background:#f3f4f6;padding:.1rem .35rem;border-radius:3px"><?= e($row['action']) ?></code></td>
                <td style="font-size:12px;color:#6b7280">
                    <?= $row['model'] ? e($row['model']).' #'.$row['model_id'] : '—' ?>
                    <?php if ($row['notes']): ?><div style="font-size:11px"><?= e($row['notes']) ?></div><?php endif; ?>
                </td>
                <td style="font-size:12px;color:#9ca3af"><?= e($row['ip_address'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <?php if ($log['last_page'] > 1): ?>
    <div style="padding:1rem 1.25rem">
        <div class="pagination">
            <?php for ($p = 1; $p <= $log['last_page']; $p++): ?>
            <a href="?page=<?= $p ?>&user=<?= urlencode($filter['user']) ?>&action=<?= urlencode($filter['action']) ?>"
               class="<?= $p === $log['current_page'] ? 'current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
