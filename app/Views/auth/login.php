<?php $pageTitle = 'Sign In'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign In — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *,*::before,*::after{box-sizing:border-box}
    :root{--primary:#4f46e5;--primary-dark:#3730a3;--danger:#ef4444;--gray-50:#f9fafb;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-300:#d1d5db;--gray-500:#6b7280;--gray-900:#111827}
    body{margin:0;font-family:'Inter',sans-serif;background:var(--gray-50);display:flex;align-items:center;justify-content:center;min-height:100vh}
    .auth-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;max-width:420px}
    .auth-logo{text-align:center;margin-bottom:2rem}
    .auth-logo h1{font-size:1.5rem;font-weight:700;color:var(--gray-900);margin:.5rem 0 .25rem}
    .auth-logo p{color:var(--gray-500);font-size:14px;margin:0}
    .form-group{margin-bottom:1rem}
    .form-group label{display:block;font-weight:500;font-size:13.5px;margin-bottom:.35rem}
    .form-control{width:100%;padding:.6rem .8rem;border:1px solid var(--gray-300);border-radius:6px;font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s}
    .form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(79,70,229,.15)}
    .form-control.is-invalid{border-color:var(--danger)}
    .form-error{color:var(--danger);font-size:12px;margin-top:.25rem;display:block}
    .btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.7rem;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;border:none;font-family:inherit;text-decoration:none;transition:all .15s}
    .btn-primary{background:var(--primary);color:#fff}
    .btn-primary:hover{background:var(--primary-dark)}
    .btn-oauth{background:#fff;color:var(--gray-900);border:1px solid var(--gray-300);margin-bottom:.5rem;font-weight:500}
    .btn-oauth:hover{background:var(--gray-50)}
    .divider{display:flex;align-items:center;gap:.75rem;margin:1.25rem 0;color:var(--gray-500);font-size:12px}
    .divider::before,.divider::after{content:'';flex:1;border-top:1px solid var(--gray-200)}
    .auth-footer{text-align:center;margin-top:1.5rem;font-size:13.5px;color:var(--gray-500)}
    .auth-footer a{color:var(--primary);text-decoration:none;font-weight:500}
    .alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:13.5px}
    .oauth-icon{width:20px;height:20px}
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-logo">
        <div style="font-size:2rem">🚀</div>
        <h1><?= e(setting('site_name', 'App')) ?></h1>
        <p>Sign in to your account</p>
    </div>

    <?php $errors = \Core\Session::flash('errors'); ?>
    <?php if (!empty($errors['email'])): ?>
        <div class="alert-error"><?= e($errors['email'][0]) ?></div>
    <?php endif; ?>

    <?php $err = \Core\Session::flash('error'); ?>
    <?php if ($err): ?>
        <div class="alert-error"><?= e($err) ?></div>
    <?php endif; ?>

    <?php $success = \Core\Session::flash('success'); ?>
    <?php if ($success): ?>
        <div class="alert-error" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" class="form-control <?= !empty($errors['email']) ? 'is-invalid' : '' ?>"
                   value="<?= old('email') ?>" required autofocus autocomplete="email">
        </div>
        <div class="form-group">
            <label for="password" style="display:flex;justify-content:space-between">
                Password
                <a href="/password/forgot" style="font-weight:400;color:#4f46e5;font-size:12.5px">Forgot password?</a>
            </label>
            <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
        </div>
        <?php
        // CAPTCHA widget — empty string when CAPTCHA_PROVIDER is 'none',
        // so nothing renders for installs that don't need bot protection.
        $__captcha = captcha_widget();
        if ($__captcha): ?>
        <div class="form-group"><?= $__captcha ?></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Sign In</button>
    </form>

    <?php if (!empty($oauth_providers)): ?>
    <div class="divider">or continue with</div>
    <?php
    $providerMeta = [
        'google'    => ['icon' => 'G', 'label' => 'Google',    'color' => '#ea4335'],
        'microsoft' => ['icon' => 'M', 'label' => 'Microsoft', 'color' => '#00a4ef'],
        'apple'     => ['icon' => '🍎','label' => 'Apple',     'color' => '#000'],
        'facebook'  => ['icon' => 'f', 'label' => 'Facebook',  'color' => '#1877f2'],
        'linkedin'  => ['icon' => 'in','label' => 'LinkedIn',  'color' => '#0a66c2'],
    ];
    foreach ($oauth_providers as $provider):
        $meta = $providerMeta[$provider] ?? ['icon' => '?', 'label' => ucfirst($provider), 'color' => '#666'];
    ?>
    <a href="/auth/oauth/<?= e($provider) ?>" class="btn btn-oauth">
        <span style="font-weight:700;color:<?= $meta['color'] ?>"><?= $meta['icon'] ?></span>
        Continue with <?= e($meta['label']) ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($dev_users)): ?>
    <!-- Dev-only one-click login. Rendered only when APP_ENV != production
         AND APP_DEV_LOGIN=1 in .env. Every button POSTs to /dev/login-as with
         a user_id and a CSRF token, and Auth::devLoginAs() refuses in prod as
         a belt-and-suspenders safeguard. -->
    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px dashed #fcd34d">
        <div style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#92400e;margin-bottom:.5rem">
            ⚠ Dev Shortcut — sign in as
        </div>
        <div style="display:flex;flex-direction:column;gap:.35rem">
            <?php foreach ($dev_users as $du): ?>
            <form method="POST" action="/dev/login-as" style="margin:0">
                <input type="hidden" name="_token" value="<?= e($csrf ?? csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$du['id'] ?>">
                <button type="submit" style="width:100%;display:flex;justify-content:space-between;align-items:center;padding:.5rem .75rem;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#78350f;cursor:pointer;text-align:left">
                    <span>
                        <strong><?= e(trim(($du['first_name'] ?? '') . ' ' . ($du['last_name'] ?? ''))) ?: e($du['email']) ?></strong>
                        <span style="color:#a16207;margin-left:.4rem;font-size:12px">&lt;<?= e($du['email']) ?>&gt;</span>
                    </span>
                    <?php if (!empty($du['is_superadmin'])): ?>
                    <span style="background:#fecaca;color:#991b1b;font-size:10.5px;font-weight:700;padding:.1rem .4rem;border-radius:3px">SUPERADMIN</span>
                    <?php endif; ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:#9ca3af;margin-top:.5rem">
            Only visible in non-production environments. Disable with <code>APP_DEV_LOGIN=0</code> in <code>.env</code>.
        </div>
    </div>
    <?php endif; ?>

    <div class="auth-footer">
        Don't have an account? <a href="/register">Create one</a>
    </div>
</div>
</body>
</html>
