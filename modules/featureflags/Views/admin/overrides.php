<?php $pageTitle = 'Overrides — ' . $flag['key']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<a href="/admin/feature-flags" style="color:#6b7280;font-size:13px;text-decoration:none">← Flags</a>

<h1 style="margin:.5rem 0">Overrides for <code><?= e((string) $flag['key']) ?></code></h1>

<div class="card" style="margin-bottom:1rem">
    <div class="card-header"><strong>Set override</strong></div>
    <form method="post" action="/admin/feature-flags/<?= e((string) $flag['key']) ?>/overrides">
        <?= csrf_field() ?>
        <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:1fr 1fr 2fr auto;align-items:end">
            <label>User id
                <input type="number" name="user_id" required min="1" style="width:100%">
            </label>
            <label>Enabled?
                <select name="enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select>
            </label>
            <label>Note
                <input name="note" placeholder="Bug report #123 — disabled pending fix">
            </label>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><strong>Active overrides</strong></div>
    <?php if (empty($overrides)): ?>
    <div class="card-body" style="color:#9ca3af;text-align:center;padding:2rem 1rem">No per-user overrides.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>User</th><th>Enabled</th><th>Note</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($overrides as $o): ?>
        <tr>
            <td>@<?= e((string) ($o['username'] ?? '?')) ?> <span style="color:#9ca3af">#<?= (int) $o['user_id'] ?></span></td>
            <td><?= (int) $o['enabled'] === 1 ? '✓ on' : '× off' ?></td>
            <td style="font-size:13px;color:#6b7280"><?= e((string) ($o['note'] ?? '')) ?></td>
            <td style="font-size:12px"><?= e(date('M j, Y', strtotime((string) $o['created_at']))) ?></td>
            <td>
                <form method="post" action="/admin/feature-flags/<?= e((string) $flag['key']) ?>/overrides/<?= (int) $o['user_id'] ?>/clear">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-secondary">Clear</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
