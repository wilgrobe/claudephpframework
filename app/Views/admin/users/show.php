<?php $pageTitle = 'User: ' . e(($user_detail['first_name']??'').' '.($user_detail['last_name']??'')); ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:700px;margin:0 auto">
<div class="card" style="margin-bottom:1rem">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:.75rem">
            <span class="avatar" style="width:48px;height:48px;font-size:1.1rem">
                <?= e(strtoupper(substr($user_detail['first_name']??'?',0,1))) ?>
            </span>
            <div>
                <h2 style="margin:0"><?= e(($user_detail['first_name']??'').' '.($user_detail['last_name']??'')) ?></h2>
                <div style="color:#6b7280;font-size:13.5px"><?= e($user_detail['email']) ?></div>
            </div>
        </div>
        <div style="display:flex;gap:.5rem">
            <a href="/admin/users/<?= $user_detail['id'] ?>/edit" class="btn btn-sm btn-secondary">Edit</a>
            <?php if (auth()->isSuperadminModeOn()): ?>
            <form method="POST" action="/admin/users/<?= $user_detail['id'] ?>/emulate">
                <?= csrf_field() ?><button class="btn btn-sm btn-secondary">Emulate</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <div style="font-size:12px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem">Account Details</div>
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;font-size:13.5px;margin:0">
                    <dt style="color:#6b7280">Status</dt>
                    <dd style="margin:0"><?= $user_detail['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></dd>
                    <dt style="color:#6b7280">Superadmin</dt>
                    <dd style="margin:0"><?= $user_detail['is_superadmin'] ? '<span class="badge badge-danger">Yes</span>' : 'No' ?></dd>
                    <dt style="color:#6b7280">Email Verified</dt>
                    <dd style="margin:0">
                        <?php if ($user_detail['email_verified_at']): ?>
                            ✅ <?= date('M j, Y', strtotime($user_detail['email_verified_at'])) ?>
                        <?php else: ?>
                            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                                <span>❌ Not verified</span>
                                <?php if (!empty($user_detail['email'])): ?>
                                <form method="POST"
                                      action="/admin/users/<?= (int) $user_detail['id'] ?>/resend-verification"
                                      style="margin:0"
                                      onsubmit="return confirm('Send a fresh verification email to <?= e($user_detail['email']) ?>? This invalidates any previous link.')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-xs btn-secondary"
                                            title="Issue a new 24-hour verification link and email it">
                                        Resend link
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (auth()->isSuperAdmin()): ?>
                                <form method="POST"
                                      action="/admin/users/<?= (int) $user_detail['id'] ?>/mark-verified"
                                      style="margin:0"
                                      onsubmit="return confirm('Mark <?= e($user_detail['email']) ?> as verified without an email round-trip? The action is audit-logged.')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-xs btn-warning"
                                            title="Superadmin-only: bypass email click and stamp email_verified_at = NOW()">
                                        Mark verified
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </dd>
                    <dt style="color:#6b7280">Last Login</dt>
                    <dd style="margin:0"><?= $user_detail['last_login_at'] ? date('M j, Y g:i A', strtotime($user_detail['last_login_at'])) : 'Never' ?></dd>
                    <dt style="color:#6b7280">Joined</dt>
                    <dd style="margin:0"><?= date('M j, Y', strtotime($user_detail['created_at'])) ?></dd>
                </dl>
            </div>
            <div>
                <div style="font-size:12px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem">System Roles</div>
                <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                    <?php foreach ($user_detail['roles'] ?? [] as $r): ?>
                    <span class="badge badge-primary"><?= e($r['name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($user_detail['roles'])): ?><span style="color:#6b7280;font-size:13px">No roles</span><?php endif; ?>
                </div>

                <div style="font-size:12px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;margin-top:1rem">OAuth Providers</div>
                <div style="display:flex;flex-wrap:wrap;gap:.35rem">
                    <?php foreach ($user_detail['oauth_providers'] ?? [] as $op): ?>
                    <span class="badge badge-gray"><?= e(ucfirst($op['provider'])) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($user_detail['oauth_providers'])): ?><span style="color:#6b7280;font-size:13px">None</span><?php endif; ?>
                </div>
            </div>
        </div>

        <div style="margin-top:1.25rem">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem">Group Memberships</div>
            <?php if (empty($user_detail['groups'])): ?>
            <p style="color:#6b7280;font-size:13.5px;margin:0">Not a member of any groups.</p>
            <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                <?php foreach ($user_detail['groups'] as $g): ?>
                <div style="background:#f3f4f6;border-radius:6px;padding:.4rem .75rem;font-size:13px">
                    <?= e($g['name']) ?> <span class="badge badge-gray"><?= e($g['role_name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
