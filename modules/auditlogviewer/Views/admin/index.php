<?php $pageTitle = 'Audit log'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<h1 style="margin:0 0 1rem 0">Audit log <span style="color:#9ca3af;font-size:13px">(<?= (int) $total ?>)</span></h1>

<form method="get" style="display:grid;gap:.5rem;grid-template-columns:repeat(4, 1fr);margin-bottom:1rem">
    <input name="action"        value="<?= e((string) ($filters['action'] ?? '')) ?>" placeholder="action (e.g. auth.login or auth.*)" aria-label="action (e.g. auth.login or auth.*)">
    <input name="actor_user_id" value="<?= e((string) ($filters['actor_user_id'] ?? '')) ?>" placeholder="actor user id" type="number" aria-label="actor user id">
    <input name="model"         value="<?= e((string) ($filters['model'] ?? '')) ?>" placeholder="model (e.g. users)" aria-label="model (e.g. users)">
    <input name="model_id"      value="<?= e((string) ($filters['model_id'] ?? '')) ?>" placeholder="model id" type="number" aria-label="model id">
    <input name="date_from"     value="<?= e((string) ($filters['date_from'] ?? '')) ?>" placeholder="from YYYY-MM-DD" aria-label="from YYYY-MM-DD">
    <input name="date_to"       value="<?= e((string) ($filters['date_to']   ?? '')) ?>" placeholder="to YYYY-MM-DD" aria-label="to YYYY-MM-DD">
    <input name="q"             value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="search text" style="grid-column:span 2" aria-label="search text">
    <button class="btn btn-secondary">Filter</button>
    <a href="/admin/audit-log" class="btn btn-secondary">Clear</a>
</form>

<div class="card">
<?php if (empty($items)): ?>
<div class="card-body" style="color:#9ca3af;text-align:center;padding:3rem 1rem">No matching rows.</div>
<?php else: ?>
<table class="table">
    <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Model</th><th>IP</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $r): ?>
    <tr>
        <td style="font-size:12px;white-space:nowrap"><?= e(date('M j H:i:s', strtotime((string) $r['created_at']))) ?></td>
        <td>
            <?php if (!empty($r['actor_username'])): ?>
            @<?= e((string) $r['actor_username']) ?>
            <?php if (!empty($r['emulated_username'])): ?>
            <div style="font-size:11px;color:#b45309">emulated by @<?= e((string) $r['emulated_username']) ?></div>
            <?php endif; ?>
            <?php else: ?>
            <span style="color:#9ca3af">system</span>
            <?php endif; ?>
            <?php if ((int) $r['superadmin_mode'] === 1): ?>
            <span class="badge badge-warning" style="margin-left:.25rem">superadmin</span>
            <?php endif; ?>
        </td>
        <td><code style="font-size:12px"><?= e((string) $r['action']) ?></code></td>
        <td style="font-size:12px;font-family:monospace">
            <?= $r['model'] ? e((string) $r['model']) . ($r['model_id'] ? ' #' . (int) $r['model_id'] : '') : '—' ?>
        </td>
        <td style="font-size:12px;color:#9ca3af"><?= e((string) ($r['ip_address'] ?? '')) ?></td>
        <td><a href="/admin/audit-log/<?= (int) $r['id'] ?>" class="btn btn-sm btn-secondary">Detail</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
