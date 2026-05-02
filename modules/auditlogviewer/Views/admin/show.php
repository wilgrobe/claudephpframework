<?php $pageTitle = 'Audit row #' . (int) $row['id']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<a href="/admin/audit-log" style="color:#6b7280;font-size:13px;text-decoration:none">← Audit log</a>

<div class="card" style="margin-top:.5rem">
    <div class="card-header"><h2 style="margin:0">Audit row #<?= (int) $row['id'] ?></h2></div>
    <div class="card-body">
        <dl style="display:grid;gap:.25rem 1rem;grid-template-columns:150px 1fr">
            <dt style="color:#6b7280">When</dt>
            <dd><?= e(date('M j, Y H:i:s', strtotime((string) $row['created_at']))) ?></dd>
            <dt style="color:#6b7280">Actor</dt>
            <dd><?= !empty($row['actor_username']) ? '@' . e((string) $row['actor_username']) : '<span style="color:#9ca3af">system</span>' ?></dd>
            <?php if (!empty($row['emulated_username'])): ?>
            <dt style="color:#6b7280">Emulated by</dt>
            <dd>@<?= e((string) $row['emulated_username']) ?></dd>
            <?php endif; ?>
            <dt style="color:#6b7280">Superadmin</dt>
            <dd><?= (int) $row['superadmin_mode'] === 1 ? '<strong>yes</strong>' : 'no' ?></dd>
            <dt style="color:#6b7280">Action</dt>
            <dd><code><?= e((string) $row['action']) ?></code></dd>
            <dt style="color:#6b7280">Model</dt>
            <dd>
                <?php if (!empty($row['model'])): ?>
                <code><?= e((string) $row['model']) ?></code>
                <?php if (!empty($row['model_id'])): ?> #<?= (int) $row['model_id'] ?><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </dd>
            <dt style="color:#6b7280">IP</dt>
            <dd><code><?= e((string) ($row['ip_address'] ?? '')) ?></code></dd>
            <dt style="color:#6b7280">User agent</dt>
            <dd style="font-size:12px;color:#6b7280"><?= e((string) ($row['user_agent'] ?? '')) ?></dd>
            <?php if (!empty($row['notes'])): ?>
            <dt style="color:#6b7280">Notes</dt>
            <dd><?= e((string) $row['notes']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<?php if ($values['old'] !== null || $values['new'] !== null): ?>
<div style="display:grid;gap:1rem;grid-template-columns:1fr 1fr;margin-top:1rem">
    <div class="card">
        <div class="card-header"><strong>Old values</strong></div>
        <div class="card-body">
            <?php if ($values['old'] !== null): ?>
            <pre style="margin:0;white-space:pre-wrap;font-size:12px;background:#fef2f2;padding:.5rem;border-radius:4px"><?= e(json_encode($values['old'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php else: ?>
            <div style="color:#9ca3af;font-size:13px">(none)</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><strong>New values</strong></div>
        <div class="card-body">
            <?php if ($values['new'] !== null): ?>
            <pre style="margin:0;white-space:pre-wrap;font-size:12px;background:#f0fdf4;padding:.5rem;border-radius:4px"><?= e(json_encode($values['new'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php else: ?>
            <div style="color:#9ca3af;font-size:13px">(none)</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
