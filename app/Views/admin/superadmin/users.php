<?php $pageTitle = 'All Users — Superadmin'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem">
    <form method="GET" style="display:flex;gap:.75rem">
        <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Search by name or email…" style="max-width:340px" aria-label="Search by name or email…">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search): ?><a href="/admin/superadmin/users" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>User List</h2>
        <span style="color:#6b7280;font-size:13px"><?= number_format($users['total']) ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>User</th><th>Roles</th><th>SA</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users['items'] as $u): ?>
            <tr>
                <td>
                    <div style="font-weight:500"><?= e(($u['first_name']??'').' '.($u['last_name']??'')) ?></div>
                    <div style="font-size:12px;color:#6b7280"><?= e($u['email']) ?></div>
                </td>
                <td style="font-size:12px;color:#6b7280"><?= e($u['roles'] ?? '—') ?></td>
                <td>
                    <?php if ($u['is_superadmin']): ?>
                    <span class="badge badge-danger">SA</span>
                    <?php else: ?>
                    <form method="POST" action="/admin/superadmin/users/<?= $u['id'] ?>/superadmin" style="display:inline">
                        <?= csrf_field() ?><input type="hidden" name="enable" value="1">
                        <button class="btn btn-xs btn-secondary" onclick="return confirm('Grant superadmin to this user?')">Grant SA</button>
                    </form>
                    <?php endif; ?>
                </td>
                <td><?= $u['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></td>
                <td style="font-size:12px;color:#6b7280"><?= $u['last_login_at'] ? date('M j Y', strtotime($u['last_login_at'])) : 'Never' ?></td>
                <td>
                    <div style="display:flex;gap:.3rem">
                        <a href="/admin/users/<?= $u['id'] ?>" class="btn btn-xs btn-secondary">View</a>
                        <?php if ($u['id'] !== auth()->id()): ?>
                        <form method="POST" action="/admin/users/<?= $u['id'] ?>/emulate">
                            <?= csrf_field() ?><button class="btn btn-xs btn-secondary">Emulate</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
