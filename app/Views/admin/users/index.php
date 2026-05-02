<?php $pageTitle = 'Users'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card">
    <div class="card-header">
        <h2>All Users</h2>
        <a href="/admin/users/create" class="btn btn-primary btn-sm">+ New User</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Roles</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:.6rem">
                        <span class="avatar"><?= e(strtoupper(substr($u['first_name']??'?',0,1))) ?></span>
                        <div>
                            <div style="font-weight:500"><?= e(($u['first_name']??'').' '.($u['last_name']??'')) ?></div>
                            <div style="font-size:12px;color:#6b7280"><?= e($u['email']) ?></div>
                        </div>
                    </div>
                    <?php if ($u['is_superadmin']): ?><span class="badge badge-danger" style="margin-top:.25rem">Superadmin</span><?php endif; ?>
                </td>
                <td>
                    <?php foreach (($u['roles_list'] ?? []) as $r): ?>
                    <span class="badge badge-primary"><?= e($r['name']) ?></span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if ($u['is_active']): ?>
                    <span class="badge badge-success">Active</span>
                    <?php else: ?>
                    <span class="badge badge-danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="color:#6b7280;font-size:13px">
                    <?= $u['last_login_at'] ? date('M j, Y', strtotime($u['last_login_at'])) : 'Never' ?>
                </td>
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <a href="/admin/users/<?= $u['id'] ?>" class="btn btn-xs btn-secondary">View</a>
                        <a href="/admin/users/<?= $u['id'] ?>/edit" class="btn btn-xs btn-secondary">Edit</a>
                        <?php if (auth()->isSuperadminModeOn()): ?>
                        <form method="POST" action="/admin/users/<?= $u['id'] ?>/emulate">
                            <?= csrf_field() ?><button class="btn btn-xs btn-secondary">Emulate</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($u['id'] !== auth()->id()): ?>
                        <form method="POST" action="/admin/users/<?= $u['id'] ?>/delete"
                              data-confirm="Delete user <?= e(($u['first_name']??'').' '.($u['last_name']??'')) ?>?">
                            <?= csrf_field() ?><button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($pagination)): ?>
    <div style="padding:1rem 1.25rem">
        <?php include BASE_PATH . '/app/Views/layout/pagination.php'; ?>
    </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
