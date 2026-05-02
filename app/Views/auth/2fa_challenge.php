<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
    .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 28px rgba(0,0,0,.09); padding: 2.5rem; width: 100%; max-width: 420px; }
    .icon-wrap { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto 1.25rem; }
    .icon-email { background: #dbeafe; }
    .icon-sms   { background: #d1fae5; }
    .icon-totp  { background: #ede9fe; }
    h1 { font-size: 1.35rem; font-weight: 700; text-align: center; margin: 0 0 .4rem; color: #111827; }
    .subtitle { text-align: center; color: #6b7280; font-size: 14px; margin: 0 0 2rem; line-height: 1.6; }
    .subtitle strong { color: #374151; }
    .otp-inputs { display: flex; gap: .5rem; justify-content: center; margin-bottom: 1.5rem; }
    .otp-inputs input {
        width: 48px; height: 56px; text-align: center; font-size: 1.4rem; font-weight: 700;
        border: 2px solid #d1d5db; border-radius: 8px; font-family: 'Inter', monospace;
        transition: border-color .15s, box-shadow .15s; caret-color: #4f46e5;
        outline: none; background: #fff; color: #111827;
    }
    .otp-inputs input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
    .otp-inputs input.filled { border-color: #4f46e5; background: #eef2ff; }
    .totp-input { width: 100%; padding: .75rem 1rem; border: 2px solid #d1d5db; border-radius: 8px; font-size: 1.25rem; font-weight: 700; text-align: center; letter-spacing: .25rem; font-family: monospace; transition: border-color .15s, box-shadow .15s; }
    .totp-input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
    .btn { display: flex; align-items: center; justify-content: center; width: 100%; padding: .8rem; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; border: none; font-family: inherit; transition: all .15s; }
    .btn-primary { background: #4f46e5; color: #fff; }
    .btn-primary:hover { background: #3730a3; }
    .btn-ghost { background: none; color: #6b7280; border: 1px solid #e5e7eb; margin-top: .6rem; font-size: 13.5px; padding: .6rem; }
    .btn-ghost:hover { background: #f9fafb; }
    .alert { padding: .75rem 1rem; border-radius: 8px; font-size: 13.5px; margin-bottom: 1.25rem; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0; color: #9ca3af; font-size: 12px; }
    .divider::before, .divider::after { content: ''; flex: 1; border-top: 1px solid #e5e7eb; }
    .footer-links { display: flex; flex-direction: column; gap: .4rem; margin-top: 1.25rem; }
    .footer-links a { color: #4f46e5; text-decoration: none; font-size: 13px; text-align: center; }
    .footer-links a:hover { text-decoration: underline; }
    .timer { font-size: 12px; color: #9ca3af; text-align: center; margin-top: .5rem; }
    </style>
</head>
<body>

<?php
$icons = [
    'email' => ['🔐', 'icon-email'],
    'sms'   => ['📱', 'icon-sms'],
    'totp'  => ['🔑', 'icon-totp'],
];
$icon = $icons[$method] ?? ['🔒', 'icon-email'];

$titles = [
    'email' => 'Check your email',
    'sms'   => 'Check your phone',
    'totp'  => 'Authenticator app',
];
$title = $titles[$method] ?? 'Verify your identity';
?>

<div class="card">
    <div class="icon-wrap <?= $icon[1] ?>"><?= $icon[0] ?></div>
    <h1><?= $title ?></h1>

    <?php if ($method === 'email'): ?>
    <p class="subtitle">We sent a 6-digit code to <strong><?= e($destination) ?></strong>. Enter it below.</p>
    <?php elseif ($method === 'sms'): ?>
    <p class="subtitle">We sent a 6-digit code to <strong><?= e($destination) ?></strong>. Enter it below.</p>
    <?php else: ?>
    <p class="subtitle">Enter the 6-digit code from your authenticator app (<strong>Google Authenticator, Microsoft Authenticator</strong>, or Authy).</p>
    <?php endif; ?>

    <?php $error = \Core\Session::flash('error'); ?>
    <?php $success = \Core\Session::flash('success'); ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <form method="POST" action="/auth/2fa/challenge" id="challenge-form">
        <?= csrf_field() ?>
        <?php /* SECURITY: challenge_id is NOT in the form — it is read from session server-side.
                  Exposing it in HTML would allow an attacker to swap challenge IDs. */ ?>

        <?php if ($method === 'totp'): ?>
        <!-- TOTP: single text input with auto-submit on 6 digits -->
        <input type="text" name="code" class="totp-input" id="totp-code"
               inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" placeholder="000000" autofocus aria-label="One-time code">
        <div class="timer" id="totp-timer">Code refreshes every 30 seconds</div>
        <div style="margin-top:1.25rem">
            <button type="submit" class="btn btn-primary">Verify</button>
        </div>

        <?php else: ?>
        <!-- Email / SMS: 6 individual digit boxes -->
        <div class="otp-inputs" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                   autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                   aria-label="Digit <?= $i + 1 ?>"
                   <?= $i === 0 ? 'autofocus' : '' ?>>
            <?php endfor; ?>
        </div>
        <input type="hidden" name="code" id="combined-code">
        <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Verify Code</button>
        <?php endif; ?>
    </form>

    <?php if (in_array($method, ['email', 'sms'], true)): ?>
    <div class="divider">or</div>
    <form method="POST" action="/auth/2fa/resend">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost">
            Resend code via <?= $method === 'email' ? 'email' : 'SMS' ?>
        </button>
    </form>
    <?php endif; ?>

    <div class="footer-links">
        <a href="/auth/2fa/recovery">Use a recovery code instead</a>
        <a href="/login">← Back to sign in</a>
    </div>
</div>

<script>
<?php if ($method !== 'totp'): ?>
// OTP digit-box behaviour
(function () {
    const boxes    = [...document.querySelectorAll('#otp-boxes input')];
    const hidden   = document.getElementById('combined-code');
    const submitBtn= document.getElementById('submit-btn');

    function update() {
        const val = boxes.map(b => b.value).join('');
        hidden.value = val;
        submitBtn.disabled = val.length < 6;
        boxes.forEach(b => b.classList.toggle('filled', b.value !== ''));
    }

    boxes.forEach((box, i) => {
        box.addEventListener('input', e => {
            // Handle paste of full code into first box
            const pasted = e.target.value;
            if (pasted.length > 1) {
                const digits = pasted.replace(/\D/g, '').slice(0, 6);
                digits.split('').forEach((d, j) => { if (boxes[j]) boxes[j].value = d; });
                (boxes[Math.min(digits.length, 5)] || boxes[5]).focus();
                update();
                return;
            }
            box.value = box.value.replace(/\D/g, '').slice(0, 1);
            if (box.value && i < 5) boxes[i + 1].focus();
            update();
        });

        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                boxes[i - 1].value = '';
                boxes[i - 1].focus();
                update();
            }
            if (e.key === 'ArrowLeft'  && i > 0) boxes[i - 1].focus();
            if (e.key === 'ArrowRight' && i < 5) boxes[i + 1].focus();
        });

        // Support paste anywhere
        box.addEventListener('paste', e => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, j) => { if (boxes[j]) boxes[j].value = d; });
            update();
            if (digits.length >= 6) document.getElementById('challenge-form').submit();
        });
    });
})();
<?php else: ?>
// TOTP auto-submit when 6 digits entered
document.getElementById('totp-code').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) document.getElementById('challenge-form').submit();
});

// Countdown timer
(function () {
    const el = document.getElementById('totp-timer');
    function tick() {
        const remaining = 30 - (Math.floor(Date.now() / 1000) % 30);
        el.textContent = `Code refreshes in ${remaining}s`;
        if (remaining <= 5) el.style.color = '#ef4444';
        else el.style.color = '#9ca3af';
    }
    tick();
    setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>
