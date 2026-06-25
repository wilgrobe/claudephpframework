<?php $pageTitle = 'Set Up Two-Factor Authentication'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:600px;margin:0 auto">

<?php if ($method === 'totp' && $totpData): ?>
<!-- ── TOTP Setup ── -->
<div class="card">
    <div class="card-header">
        <h2>Set up Authenticator App</h2>
        <a href="/profile/2fa" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">

        <!-- Step 1: Install -->
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:var(--color-gray-50);border-radius:8px;margin-bottom:1.25rem">
            <div style="background:var(--color-primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">1</div>
            <div>
                <div style="font-weight:600;font-size:14px;margin-bottom:.3rem">Install an authenticator app</div>
                <div style="font-size:13px;color:var(--color-gray-500);line-height:1.5">
                    If you don't already have one, install one of these:
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
                    <span style="background: var(--bg-panel, #fff);border:1px solid var(--color-gray-200);border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">📱 Google Authenticator</span>
                    <span style="background: var(--bg-panel, #fff);border:1px solid var(--color-gray-200);border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">🪟 Microsoft Authenticator</span>
                    <span style="background: var(--bg-panel, #fff);border:1px solid var(--color-gray-200);border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">🔐 Authy</span>
                </div>
            </div>
        </div>

        <!-- Step 2: Scan QR -->
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:var(--color-gray-50);border-radius:8px;margin-bottom:1.25rem">
            <div style="background:var(--color-primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">2</div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:14px;margin-bottom:.5rem">Scan this QR code with your app</div>
                <div style="text-align:center;margin-bottom:.75rem">
                    <!-- QR rendered client-side from data attribute; keeps the
                         otpauth:// URI out of the rendered HTML's src where a
                         broken CDN would show a visual placeholder. -->
                    <div id="totp-qr"
                         data-uri="<?= e($totpData['provisioning_uri']) ?>"
                         style="display:inline-block;padding:6px;background: var(--bg-panel, #fff);box-shadow:0 2px 8px rgba(0,0,0,.12);border-radius:8px;min-width:200px;min-height:200px"></div>
                    <noscript>
                        <div style="font-size:12.5px;color:#b91c1c;margin-top:.5rem">
                            QR rendering requires JavaScript. Enable JS, or enter the secret manually below.
                        </div>
                    </noscript>
                </div>
                <div style="font-size:12.5px;color:var(--color-gray-500);text-align:center">Can't scan? Enter this key manually:</div>
                <div style="background:var(--accent-subtle);border:1px solid var(--border-strong);border-radius:6px;padding:.6rem 1rem;text-align:center;font-family:monospace;font-size:1rem;font-weight:700;letter-spacing:.15rem;margin-top:.4rem;user-select:all">
                    <?= e($totpData['secret']) ?>
                </div>
            </div>
        </div>

        <!-- Step 3: Enter first code -->
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:var(--color-gray-50);border-radius:8px;margin-bottom:1.25rem">
            <div style="background:var(--color-primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">3</div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:14px;margin-bottom:.5rem">Enter the 6-digit code to confirm</div>
                <?php $error = \Core\Session::flash('error'); ?>
                <?php if ($error): ?><div style="background:var(--color-danger-bg);color:var(--color-danger-fg);border:1px solid #fca5a5;padding:.6rem .85rem;border-radius:6px;font-size:13px;margin-bottom:.75rem"><?= e($error) ?></div><?php endif; ?>
                <form method="POST" action="/profile/2fa/confirm-totp" style="display:flex;flex-direction:column;gap:.6rem;align-items:stretch;max-width:320px">
                    <?= csrf_field() ?>
                    <input type="text" name="code" class="form-control"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus
                           style="text-align:center;font-size:1.15rem;font-family:monospace;letter-spacing:.2rem" aria-label="000000">
                    <!-- AUTH-H1 — password re-auth required to activate TOTP, so a
                         hijacked session can't enable an attacker-controlled second factor. -->
                    <label for="confirm_totp_pw" style="font-weight:600;font-size:13px">Confirm your password</label>
                    <input type="password" id="confirm_totp_pw" name="current_password" class="form-control"
                           autocomplete="current-password" required>
                    <button type="submit" class="btn btn-primary" style="padding:.55rem 1.1rem">Activate</button>
                </form>
            </div>
        </div>

        <div style="background:var(--color-warning-bg);border:1px solid #fcd34d;border-radius:8px;padding:.85rem 1rem;font-size:13px;color:var(--color-warning-fg)">
            ⚠️ <strong>Important:</strong> After activation you'll receive recovery codes. Save them in a safe place — they're the only way to regain access if you lose your device.
        </div>
    </div>
</div>

<?php if (empty($totpData['qr_data_uri'])): ?>
<!-- Fallback path: server-side QR library isn't installed. Render
     client-side via cdnjs-hosted qrcode.js. Server-side rendering is the
     default (endroid/qr-code in composer.json); this block stays for
     installs that vendor without dev deps. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var target = document.getElementById('totp-qr');
    if (!target) return;
    var uri = target.dataset.uri || '';
    if (!uri) return;
    target.innerHTML = '';
    new QRCode(target, {
        text: uri,
        width: 200, height: 200,
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>
<?php endif; ?>

<?php else: ?>
<!-- ── Method picker (no method selected yet) ── -->
<div class="card">
    <div class="card-header">
        <h2>Choose Authentication Method</h2>
        <a href="/profile/2fa" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">
        <?php $error = \Core\Session::flash('error'); ?>
        <?php if ($error): ?><div style="background:var(--color-danger-bg);color:var(--color-danger-fg);border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:6px;font-size:13.5px;margin-bottom:1rem"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" action="/profile/2fa/enable">
            <?= csrf_field() ?>
            <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.25rem">
                <?php
                $methods = [
                    ['email', '📧', 'Email OTP',                  'Receive a 6-digit code at your email address each time you sign in.'],
                    ['sms',   '📱', 'SMS OTP',                    'Receive a 6-digit code via text message.'],
                    ['totp',  '🔐', 'Authenticator App (TOTP)',   'Use Google Authenticator, Microsoft Authenticator, or Authy. Most secure — works offline.'],
                ];
                foreach ($methods as [$val, $ico, $label, $desc]): ?>
                <label style="display:flex;align-items:flex-start;gap:.75rem;padding:1rem;border:2px solid var(--color-gray-200);border-radius:8px;cursor:pointer;transition:border-color .15s" class="method-card">
                    <input type="radio" name="method" value="<?= $val ?>" style="margin-top:.15rem;flex-shrink:0" <?= $val === 'totp' ? 'checked' : '' ?>>
                    <span style="font-size:1.3rem;flex-shrink:0"><?= $ico ?></span>
                    <span>
                        <span style="display:block;font-weight:600;font-size:14px"><?= $label ?></span>
                        <span style="display:block;font-size:12.5px;color:var(--color-gray-500);margin-top:.2rem"><?= $desc ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <!-- Phase 43.193a — password re-auth required before 2FA
                 method change. Matches the pattern that disable +
                 regenerateRecoveryCodes already use. -->
            <div style="margin-bottom:1rem">
                <label for="current_password" style="display:block;font-weight:600;font-size:13px;margin-bottom:.35rem">Confirm your password</label>
                <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required>
                <span style="display:block;font-size:12px;color:var(--color-gray-500);margin-top:.3rem">Required to make 2FA changes — defends against session-hijack scenarios.</span>
            </div>
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.method-card').forEach(label => {
    label.addEventListener('click', () => {
        document.querySelectorAll('.method-card').forEach(l => l.style.borderColor = 'var(--color-gray-200)');
        label.style.borderColor = 'var(--color-primary)';
    });
    if (label.querySelector('input').checked) label.style.borderColor = 'var(--color-primary)';
});
</script>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
