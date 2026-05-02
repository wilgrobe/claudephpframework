<?php $pageTitle = 'Feature flags'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="margin:0">Feature flags</h2>
        <a href="/admin/feature-flags/create" class="btn btn-sm btn-primary">New flag</a>
    </div>
    <?php if (empty($flags)): ?>
    <div class="card-body" style="text-align:center;color:#6b7280;padding:3rem 1rem">No flags defined.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Key</th><th>Label</th><th>Global</th><th>Rollout</th><th>Groups</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($flags as $f): $groupIds = !empty($f['groups_json']) ? json_decode((string) $f['groups_json'], true) : []; ?>
        <tr>
            <td><code><?= e((string) $f['key']) ?></code></td>
            <td><?= e((string) $f['label']) ?></td>
            <td><?= (int) $f['enabled'] ? '<span class="badge badge-success">on</span>' : '<span class="badge badge-gray">off</span>' ?></td>
            <td><?= (int) $f['rollout_percent'] ?>%</td>
            <td style="font-size:12px">
                <?php if (!empty($groupIds)): ?>
                <?php
                $names = [];
                foreach ($groups as $g) if (in_array((int) $g['id'], array_map('intval', (array) $groupIds), true)) $names[] = $g['name'];
                echo e(implode(', ', $names));
                ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <a href="/admin/feature-flags/<?= e((string) $f['key']) ?>/edit" class="btn btn-sm btn-secondary">Edit</a>
                <a href="/admin/feature-flags/<?= e((string) $f['key']) ?>/overrides" class="btn btn-sm btn-secondary">Overrides</a>
                <form method="post" action="/admin/feature-flags/<?= e((string) $f['key']) ?>/delete" style="display:inline" onsubmit="return confirm('Delete flag + all overrides?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
