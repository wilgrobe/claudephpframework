<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <style>
    .icon-wrap {
        width: 60px; height: 60px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.6rem;
        margin: 0 auto 1.25rem;
    }
    .icon-email { background: var(--color-info-bg); }
    .icon-sms   { background: var(--color-success-bg); }
    .icon-totp  { background: var(--color-purple-bg); }

    .otp-inputs {
        display: flex; gap: .5rem; justify-content: center;
        margin-bottom: 1.5rem;
    }
    .otp-inputs input {
        width: 48px; height: 56px;
        text-align: center; font-size: 1.4rem; font-weight: 700;
        border: 2px solid var(--color-gray-300);
        border-radius: 8px;
        font-family: var(--font);
        transition: border-color .15s, box-shadow .15s;
        caret-color: var(--color-primary);
        outline: none; background: #fff; color: var(--color-gray-900);
    }
    .otp-inputs input:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,.15);
    }
    .otp-inputs input.filled {
        border-color: var(--color-primary);
        background: var(--color-purple-bg);
    }

    .totp-input {
        width: 100%; padding: .75rem 1rem;
        border: 2px solid var(--color-gray-300);
        border-radius: 8px;
        font-size: 1.25rem; font-weight: 700;
        text-align: center; letter-spacing: .25rem;
        font-family: ui-monospace, SFMono-Regular, monospace;
        transition: border-color .15s, box-shadow .15s;
    }
    .totp-input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,.15);
    }

    .btn-ghost {
        background: none; color: var(--color-gray-500);
        border: 1px solid var(--color-gray-200);
        margin-top: .6rem; font-size: 13.5px;
        padding: .6rem;
    }
    .btn-ghost:hover { background: var(--color-gray-50); }

    .footer-links {
        display: flex; flex-direction: column; gap: .4rem;
        margin-top: 1.25rem;
    }
    .footer-links a {
        color: var(--color-primary); text-decoration: none;
        font-size: 13px; text-align: center;
    }
    .footer-links a:hover { text-decoration: underline; }

    .timer {
        font-size: 12px; color: var(--color-gray-400);
        text-align: center; margin-top: .5rem;
    }
    </style>
</head>
<body class="auth">

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

<div class="auth-card">
    <div class="icon-wrap <?= $icon[1] ?>"><?= $icon[0] ?></div>
    <div class="auth-logo">
        <h1><?= $title ?></h1>
        <?php if ($method === 'email'): ?>
        <p>We sent a 6-digit code to <strong><?= e($destination) ?></strong>. Enter it below.</p>
        <?php elseif ($method === 'sms'): ?>
        <p>We sent a 6-digit code to <strong><?= e($destination) ?></strong>. Enter it below.</p>
        <?php else: ?>
        <p>Enter the 6-digit code from your authenticator app (<strong>Google Authenticator, Microsoft Authenticator</strong>, or Authy).</p>
        <?php endif; ?>
    </div>

    <?php $error = \Core\Session::flash('error'); ?>
    <?php $success = \Core\Session::flash('success'); ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <form method="POST" action="/auth/2fa/challenge" id="challenge-form">
        <?= csrf_field() ?>

        <?php if ($method === 'totp'): ?>
        <input type="text" name="code" class="totp-input" id="totp-code"
               inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" placeholder="000000" autofocus aria-label="One-time code">
        <div class="timer" id="totp-timer">Code refreshes every 30 seconds</div>
        <div style="margin-top:1.25rem">
            <button type="submit" class="btn btn-primary btn-block">Verify</button>
        </div>

        <?php else: ?>
        <div class="otp-inputs" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                   autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                   aria-label="Digit <?= $i + 1 ?>"
                   <?= $i === 0 ? 'autofocus' : '' ?>>
            <?php endfor; ?>
        </div>
        <input type="hidden" name="code" id="combined-code">
        <button type="submit" class="btn btn-primary btn-block" id="submit-btn" disabled>Verify Code</button>
        <?php endif; ?>
    </form>

    <?php if (in_array($method, ['email', 'sms'], true)): ?>
    <div class="divider">or</div>
    <form method="POST" action="/auth/2fa/resend">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-block">
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
document.getElementById('totp-code').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) document.getElementById('challenge-form').submit();
});

(function () {
    const el = document.getElementById('totp-timer');
    function tick() {
        const remaining = 30 - (Math.floor(Date.now() / 1000) % 30);
        el.textContent = `Code refreshes in ${remaining}s`;
        el.style.color = remaining <= 5 ? 'var(--color-danger)' : 'var(--color-gray-400)';
    }
    tick();
    setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>
