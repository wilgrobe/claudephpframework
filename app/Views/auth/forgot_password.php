<?php /* app/Views/auth/forgot_password.php */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/auth.css')) ?>">
    <?php echo (new \Core\Services\ThemeService(new \Core\Services\SettingsService()))->renderOverrideStyle(); /* site theme — coral apex / tenant brand + light-dark */ ?>
</head>
<body class="auth">
<div class="auth-card">
    <div class="auth-logo">
        <h1>Forgot your password?</h1>
        <p>Enter your email address and we'll send you a reset link.</p>
    </div>

    <?php $err = \Core\Session::flash('error'); ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
    <?php $ok = \Core\Session::flash('success'); ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

    <form method="POST" action="/password/forgot">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="email">Email address</label>
            <input type="email" name="email" required autofocus id="email">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
    </form>

    <div class="auth-footer"><a href="/login">← Back to sign in</a></div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
