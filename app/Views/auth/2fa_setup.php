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
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:#f9fafb;border-radius:8px;margin-bottom:1.25rem">
            <div style="background:#4f46e5;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">1</div>
            <div>
                <div style="font-weight:600;font-size:14px;margin-bottom:.3rem">Install an authenticator app</div>
                <div style="font-size:13px;color:#6b7280;line-height:1.5">
                    If you don't already have one, install one of these:
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
                    <span style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">📱 Google Authenticator</span>
                    <span style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">🪟 Microsoft Authenticator</span>
                    <span style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:.3rem .65rem;font-size:12.5px;font-weight:500">🔐 Authy</span>
                </div>
            </div>
        </div>

        <!-- Step 2: Scan QR -->
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:#f9fafb;border-radius:8px;margin-bottom:1.25rem">
            <div style="background:#4f46e5;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">2</div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:14px;margin-bottom:.5rem">Scan this QR code with your app</div>
                <div style="text-align:center;margin-bottom:.75rem">
                    <!-- QR rendered client-side from data attribute; keeps the
                         otpauth:// URI out of the rendered HTML's src where a
                         broken CDN would show a visual placeholder. -->
                    <div id="totp-qr"
                         data-uri="<?= e($totpData['provisioning_uri']) ?>"
                         style="display:inline-block;padding:6px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.12);border-radius:8px;min-width:200px;min-height:200px"></div>
                    <noscript>
                        <div style="font-size:12.5px;color:#b91c1c;margin-top:.5rem">
                            QR rendering requires JavaScript. Enable JS, or enter the secret manually below.
                        </div>
                    </noscript>
                </div>
                <div style="font-size:12.5px;color:#6b7280;text-align:center">Can't scan? Enter this key manually:</div>
                <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:.6rem 1rem;text-align:center;font-family:monospace;font-size:1rem;font-weight:700;letter-spacing:.15rem;margin-top:.4rem;user-select:all">
                    <?= e($totpData['secret']) ?>
                </div>
            </div>
        </div>

        <!-- Step 3: Enter first code -->
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:1rem;background:#f9fafb;border-radius:8px;margin-bottom:1.25rem">
            <div style="background:#4f46e5;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:13px">3</div>
            <div style="flex:1">
                <div style="font-weight:600;font-size:14px;margin-bottom:.5rem">Enter the 6-digit code to confirm</div>
                <?php $error = \Core\Session::flash('error'); ?>
                <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.6rem .85rem;border-radius:6px;font-size:13px;margin-bottom:.75rem"><?= e($error) ?></div><?php endif; ?>
                <form method="POST" action="/profile/2fa/confirm-totp" style="display:flex;gap:.6rem;align-items:flex-start">
                    <?= csrf_field() ?>
                    <input type="text" name="code" class="form-control"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus
                           style="text-align:center;font-size:1.15rem;font-family:monospace;letter-spacing:.2rem;max-width:160px" aria-label="000000">
                    <button type="submit" class="btn btn-primary" style="flex-shrink:0;padding:.55rem 1.1rem">Activate</button>
                </form>
            </div>
        </div>

        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.85rem 1rem;font-size:13px;color:#92400e">
            ⚠️ <strong>Important:</strong> After activation you'll receive recovery codes. Save them in a safe place — they're the only way to regain access if you lose your device.
        </div>
    </div>
</div>

<!-- Render the QR client-side. qrcodejs is ~5KB gzipped and cdnjs is already
     in the CSP script-src allowlist (see public/index.php). If this CDN ever
     becomes unavailable, the "Can't scan? Enter this key manually" fallback
     below still gets users through setup.
     NOTE: consider adding SRI (integrity=) once you've pinned the hash, or
     vendor this file into /public/js/ to drop the external dependency. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var target = document.getElementById('totp-qr');
    if (!target) return;
    var uri = target.dataset.uri || '';
    if (!uri) return;
    // Clear any fallback content, then render.
    target.innerHTML = '';
    new QRCode(target, {
        text: uri,
        width: 200, height: 200,
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>

<?php else: ?>
<!-- ── Method picker (no method selected yet) ── -->
<div class="card">
    <div class="card-header">
        <h2>Choose Authentication Method</h2>
        <a href="/profile/2fa" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">
        <?php $error = \Core\Session::flash('error'); ?>
        <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:6px;font-size:13.5px;margin-bottom:1rem"><?= e($error) ?></div><?php endif; ?>

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
                <label style="display:flex;align-items:flex-start;gap:.75rem;padding:1rem;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:border-color .15s" class="method-card">
                    <input type="radio" name="method" value="<?= $val ?>" style="margin-top:.15rem;flex-shrink:0" <?= $val === 'totp' ? 'checked' : '' ?>>
                    <span style="font-size:1.3rem;flex-shrink:0"><?= $ico ?></span>
                    <span>
                        <span style="display:block;font-weight:600;font-size:14px"><?= $label ?></span>
                        <span style="display:block;font-size:12.5px;color:#6b7280;margin-top:.2rem"><?= $desc ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">Continue</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.method-card').forEach(label => {
    label.addEventListener('click', () => {
        document.querySelectorAll('.method-card').forEach(l => l.style.borderColor = '#e5e7eb');
        label.style.borderColor = '#4f46e5';
    });
    if (label.querySelector('input').checked) label.style.borderColor = '#4f46e5';
});
</script>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
