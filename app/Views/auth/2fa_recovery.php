<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recovery Code — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <style>
    .recovery-input {
        width: 100%; padding: .7rem 1rem;
        border: 2px solid var(--color-gray-300);
        border-radius: 8px;
        font-size: 15px;
        font-family: ui-monospace, SFMono-Regular, monospace;
        letter-spacing: .08em;
        text-transform: uppercase;
        transition: border-color .15s, box-shadow .15s;
    }
    .recovery-input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,.15);
    }
    </style>
</head>
<body class="auth">
<div class="auth-card">
    <div class="auth-icon auth-icon--warning">🔑</div>
    <div class="auth-logo">
        <h1>Use a Recovery Code</h1>
        <p>If you've lost access to your authentication device, enter one of your saved recovery codes below. Each code can only be used once.</p>
    </div>

    <?php $error = \Core\Session::flash('error'); ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="POST" action="/auth/2fa/recovery">
        <?= csrf_field() ?>
        <div class="form-row">
            <input type="text" name="recovery_code" class="recovery-input"
                   placeholder="XXXXX-XXXXX" autofocus autocomplete="off"
                   required maxlength="11"
                   oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '')" aria-label="XXXXX-XXXXX">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Verify Recovery Code</button>
    </form>

    <div class="auth-footer">
        <a href="/auth/2fa/challenge">← Back to verification</a>
    </div>
</div>
</body>
</html>
