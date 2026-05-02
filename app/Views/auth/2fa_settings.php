<?php $pageTitle = 'Two-Factor Authentication'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:680px;margin:0 auto">

<?php $enabled = !empty($twofa['two_factor_enabled']) && !empty($twofa['two_factor_confirmed']); ?>
<?php $method  = $twofa['two_factor_method'] ?? null; ?>

<!-- Status banner -->
<div class="card" style="margin-bottom:1.25rem;padding:1.5rem;display:flex;align-items:center;gap:1rem;border-left:4px solid <?= $enabled ? '#10b981' : '#e5e7eb' ?>">
    <div style="font-size:2rem;flex-shrink:0"><?= $enabled ? '🛡️' : '🔓' ?></div>
    <div style="flex:1">
        <div style="font-weight:700;font-size:1rem;color:<?= $enabled ? '#065f46' : '#374151' ?>">
            Two-factor authentication is <?= $enabled ? 'enabled' : 'disabled' ?>
        </div>
        <?php if ($enabled): ?>
        <div style="color:#6b7280;font-size:13.5px;margin-top:.2rem">
            Active method:
            <?php $labels = ['email'=>'Email OTP','sms'=>'SMS OTP','totp'=>'Authenticator App (TOTP)']; ?>
            <strong><?= e($labels[$method] ?? ucfirst($method ?? '')) ?></strong>
        </div>
        <?php else: ?>
        <div style="color:#6b7280;font-size:13.5px;margin-top:.2rem">Add an extra layer of security to your account.</div>
        <?php endif; ?>
    </div>
    <?php if ($enabled): ?>
    <a href="/profile/2fa/disable" class="btn btn-secondary btn-sm" style="flex-shrink:0">Disable 2FA</a>
    <?php endif; ?>
</div>

<?php if (!$enabled): ?>
<!-- Method selection cards -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h2>Choose a method</h2></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">

            <!-- Email -->
            <form method="POST" action="/profile/2fa/enable">
                <?= csrf_field() ?>
                <input type="hidden" name="method" value="email">
                <button type="submit" style="width:100%;background:#fff;border:2px solid #e5e7eb;border-radius:10px;padding:1.25rem;text-align:left;cursor:pointer;font-family:inherit;transition:border-color .15s,box-shadow .15s" onmouseover="this.style.borderColor='#4f46e5'" onmouseout="this.style.borderColor='#e5e7eb'">
                    <div style="font-size:1.5rem;margin-bottom:.5rem">📧</div>
                    <div style="font-weight:600;font-size:14px">Email OTP</div>
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem;line-height:1.4">Receive a one-time code by email each time you sign in.</div>
                </button>
            </form>

            <!-- SMS -->
            <form method="POST" action="/profile/2fa/enable">
                <?= csrf_field() ?>
                <input type="hidden" name="method" value="sms">
                <button type="submit" style="width:100%;background:#fff;border:2px solid #e5e7eb;border-radius:10px;padding:1.25rem;text-align:left;cursor:pointer;font-family:inherit;transition:border-color .15s" onmouseover="this.style.borderColor='#10b981'" onmouseout="this.style.borderColor='#e5e7eb'">
                    <div style="font-size:1.5rem;margin-bottom:.5rem">📱</div>
                    <div style="font-weight:600;font-size:14px">SMS OTP</div>
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem;line-height:1.4">Receive a one-time code via text message.</div>
                    <?php if (empty(auth()->user()['phone'])): ?>
                    <div style="font-size:11px;color:#f59e0b;margin-top:.35rem">⚠️ Requires a phone number on your profile.</div>
                    <?php endif; ?>
                </button>
            </form>

            <!-- Google Authenticator / TOTP -->
            <a href="/profile/2fa/setup?method=totp" style="text-decoration:none">
                <div style="background:#fff;border:2px solid #e5e7eb;border-radius:10px;padding:1.25rem;cursor:pointer;transition:border-color .15s" onmouseover="this.style.borderColor='#7c3aed'" onmouseout="this.style.borderColor='#e5e7eb'">
                    <div style="font-size:1.5rem;margin-bottom:.5rem">🔐</div>
                    <div style="font-weight:600;font-size:14px">Authenticator App (TOTP)</div>
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem;line-height:1.4">Use Google Authenticator, Microsoft Authenticator, or Authy.</div>
                    <div style="display:flex;gap:.35rem;margin-top:.5rem;flex-wrap:wrap">
                        <span style="font-size:10px;background:#ede9fe;color:#4c1d95;border-radius:4px;padding:.15rem .4rem;font-weight:600">Most secure</span>
                        <span style="font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:.15rem .4rem">Works offline</span>
                    </div>
                </div>
            </a>

        </div>
    </div>
</div>
<?php else: ?>
<!-- Management actions when 2FA is enabled -->
<div class="card">
    <div class="card-header"><h2>Manage 2FA</h2></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:.75rem">
        <a href="/profile/2fa/recovery-codes" class="btn btn-secondary" style="justify-content:flex-start;gap:.6rem">
            🔑 View Recovery Codes
        </a>
        <a href="/profile/2fa/setup" class="btn btn-secondary" style="justify-content:flex-start;gap:.6rem">
            🔄 Switch Authentication Method
        </a>
        <a href="/profile/2fa/disable" class="btn btn-secondary" style="justify-content:flex-start;gap:.6rem;color:#dc2626;border-color:#fca5a5">
            🚫 Disable Two-Factor Authentication
        </a>
    </div>
</div>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
