<?php $pageTitle = 'Sign In'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign In — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <style>
    .pw-row {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: .35rem;
    }
    .pw-row label { margin: 0 !important; font-weight: 500; font-size: 13.5px; }
    .pw-row a {
        font-weight: 400; color: var(--color-primary);
        font-size: 12.5px; text-decoration: none;
    }
    .pw-row a:hover { text-decoration: underline; }

    .dev-shortcut {
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px dashed #fcd34d;
    }
    .dev-shortcut-label {
        font-size: 11.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        color: var(--color-warning-fg);
        margin-bottom: .5rem;
    }
    .dev-shortcut-list {
        display: flex; flex-direction: column; gap: .35rem;
    }
    .dev-shortcut-list form { margin: 0; }
    .dev-shortcut-list button {
        width: 100%;
        display: flex; justify-content: space-between; align-items: center;
        padding: .5rem .75rem;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 6px;
        font-size: 13px;
        color: #78350f;
        cursor: pointer; text-align: left;
        font-family: inherit;
    }
    .dev-shortcut-list .email {
        color: #a16207; margin-left: .4rem; font-size: 12px;
    }
    .dev-shortcut-list .sa-badge {
        background: #fecaca; color: var(--color-danger-fg);
        font-size: 10.5px; font-weight: 700;
        padding: .1rem .4rem; border-radius: 3px;
    }
    .dev-shortcut-foot {
        font-size: 11px; color: var(--color-gray-400);
        margin-top: .5rem;
    }
    </style>
</head>
<body class="auth">
<div class="auth-card">
    <div class="auth-logo">
        <div class="auth-emoji">🚀</div>
        <h1><?= e(setting('site_name', 'App')) ?></h1>
        <p>Sign in to your account</p>
    </div>

    <?php $errors = \Core\Session::flash('errors'); ?>
    <?php if (!empty($errors['email'])): ?>
        <div class="alert alert-error"><?= e($errors['email'][0]) ?></div>
    <?php endif; ?>

    <?php $err = \Core\Session::flash('error'); ?>
    <?php if ($err): ?>
        <div class="alert alert-error"><?= e($err) ?></div>
    <?php endif; ?>

    <?php $success = \Core\Session::flash('success'); ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email"
                   value="<?= old('email') ?>" required autofocus autocomplete="email">
        </div>
        <div class="form-row">
            <div class="pw-row">
                <label for="password">Password</label>
                <a href="/password/forgot">Forgot password?</a>
            </div>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <?php
        $__captcha = captcha_widget();
        if ($__captcha): ?>
        <div class="form-row"><?= $__captcha ?></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
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
    <a href="/auth/oauth/<?= e($provider) ?>" class="btn btn-oauth btn-block">
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
    <div class="dev-shortcut">
        <div class="dev-shortcut-label">⚠ Dev Shortcut — sign in as</div>
        <div class="dev-shortcut-list">
            <?php foreach ($dev_users as $du): ?>
            <form method="POST" action="/dev/login-as">
                <input type="hidden" name="_token" value="<?= e($csrf ?? csrf_token()) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$du['id'] ?>">
                <button type="submit">
                    <span>
                        <strong><?= e(trim(($du['first_name'] ?? '') . ' ' . ($du['last_name'] ?? ''))) ?: e($du['email']) ?></strong>
                        <span class="email">&lt;<?= e($du['email']) ?>&gt;</span>
                    </span>
                    <?php if (!empty($du['is_superadmin'])): ?>
                    <span class="sa-badge">SUPERADMIN</span>
                    <?php endif; ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <div class="dev-shortcut-foot">
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
