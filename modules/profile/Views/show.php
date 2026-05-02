<?php $pageTitle = 'My Profile'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto">

<div class="grid grid-2" style="align-items:flex-start;gap:1.25rem">

    <!-- Left: profile info -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">
        <div class="card">
            <div class="card-header">
                <h2>Profile</h2>
                <a href="/profile/edit" class="btn btn-secondary btn-sm">Edit</a>
            </div>
            <div class="card-body">
                <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
                    <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= e($user['avatar']) ?>" alt=""
                         style="width:64px;height:64px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid #e5e7eb">
                    <?php else: ?>
                    <div style="width:64px;height:64px;border-radius:50%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;flex-shrink:0">
                        <?= e(strtoupper(substr($user['first_name']??'?',0,1))) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:1.1rem;font-weight:700"><?= e(($user['first_name']??'').' '.($user['last_name']??'')) ?></div>
                        <div style="color:#6b7280;font-size:13.5px"><?= e($user['email']) ?></div>
                        <?php if ($user['email_verified_at']): ?>
                        <span style="font-size:11px;background:#d1fae5;color:#065f46;padding:.15rem .5rem;border-radius:4px">✓ Verified</span>
                        <?php else: ?>
                        <span style="font-size:11px;background:#fee2e2;color:#991b1b;padding:.15rem .5rem;border-radius:4px">✗ Unverified</span>
                        <?php endif; ?>
                    </div>
                </div>
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:.4rem .85rem;font-size:13.5px;margin:0">
                    <?php if ($user['phone']): ?>
                    <dt style="color:#6b7280;white-space:nowrap">Phone</dt>
                    <dd style="margin:0"><?= e($user['phone']) ?></dd>
                    <?php endif; ?>
                    <?php if ($user['bio']): ?>
                    <dt style="color:#6b7280">Bio</dt>
                    <dd style="margin:0"><?= e($user['bio']) ?></dd>
                    <?php endif; ?>
                    <dt style="color:#6b7280">Member since</dt>
                    <dd style="margin:0"><?= date('M j, Y', strtotime($user['created_at'])) ?></dd>
                    <dt style="color:#6b7280">Last login</dt>
                    <dd style="margin:0"><?= $user['last_login_at'] ? date('M j, Y g:i A', strtotime($user['last_login_at'])) : 'Never' ?></dd>
                </dl>
            </div>
        </div>

        <!-- OAuth providers -->
        <?php if (!empty($oauthProviders)): ?>
        <div class="card">
            <div class="card-header"><h2>Connected Accounts</h2></div>
            <div class="card-body" style="display:flex;flex-wrap:wrap;gap:.5rem">
                <?php
                $icons = ['google'=>'G','microsoft'=>'M','apple'=>'🍎','facebook'=>'f','linkedin'=>'in'];
                foreach ($oauthProviders as $op):
                ?>
                <div style="background:#f3f4f6;border-radius:6px;padding:.4rem .85rem;font-size:13px;display:flex;align-items:center;gap:.4rem">
                    <span style="font-weight:700"><?= $icons[$op['provider']] ?? '?' ?></span>
                    <?= e(ucfirst($op['provider'])) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: security -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">

        <!-- 2FA status card -->
        <?php
        $tfaEnabled = !empty($twofa['two_factor_enabled']) && !empty($twofa['two_factor_confirmed']);
        $tfaMethod  = $twofa['two_factor_method'] ?? null;
        $methodLabels = ['email'=>'Email OTP','sms'=>'SMS OTP','totp'=>'Authenticator App'];
        ?>
        <div class="card" style="border-left:4px solid <?= $tfaEnabled ? '#10b981' : '#f59e0b' ?>">
            <div class="card-header">
                <h2>Two-Factor Authentication</h2>
                <a href="/profile/2fa" class="btn btn-sm <?= $tfaEnabled ? 'btn-secondary' : 'btn-primary' ?>">
                    <?= $tfaEnabled ? 'Manage' : 'Enable' ?>
                </a>
            </div>
            <div class="card-body">
                <?php if ($tfaEnabled): ?>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem">
                    <span style="font-size:1.3rem">🛡️</span>
                    <div>
                        <div style="font-weight:600;color:#065f46;font-size:14px">2FA is active</div>
                        <div style="color:#6b7280;font-size:13px"><?= e($methodLabels[$tfaMethod] ?? ucfirst($tfaMethod ?? '')) ?></div>
                    </div>
                </div>
                <a href="/profile/2fa/recovery-codes" style="font-size:13px;color:#4f46e5;text-decoration:none">View recovery codes →</a>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:.6rem">
                    <span style="font-size:1.3rem">⚠️</span>
                    <div>
                        <div style="font-weight:600;color:#92400e;font-size:14px">2FA not enabled</div>
                        <div style="color:#6b7280;font-size:13px">Add an extra layer of security to your account.</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Password -->
        <div class="card">
            <div class="card-header"><h2>Password</h2></div>
            <div class="card-body">
                <p style="color:#6b7280;font-size:13.5px;margin:0 0 .75rem">To change your password, click Edit Profile.</p>
                <a href="/profile/edit" class="btn btn-secondary btn-sm">Change Password</a>
            </div>
        </div>

    </div>
</div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
