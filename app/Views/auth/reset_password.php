<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body class="auth">
<div class="auth-card">
    <div class="auth-logo">
        <h1>Choose a new password</h1>
    </div>

    <?php $errors = \Core\Session::flash('errors') ?? []; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="display:block">
        <strong>Couldn't reset your password:</strong>
        <ul style="margin:.35rem 0 0;padding-left:1.2rem">
            <?php foreach ($errors as $field => $msgs): ?>
                <?php foreach ((array)$msgs as $m): ?>
                <li><?= e($m) ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="/password/reset">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-row">
            <label for="password-input">New Password</label>
            <input type="password" id="password-input" name="password" required minlength="12" autocomplete="new-password">
            <?php if (!empty($errors['password'])): ?><span class="error"><?= e($errors['password'][0]) ?></span><?php endif; ?>
            <?php include BASE_PATH . '/app/Views/layout/password_strength.php'; ?>
        </div>
        <div class="form-row">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" name="password_confirm" required autocomplete="new-password" id="password_confirm">
            <?php if (!empty($errors['password_confirm'])): ?><span class="error"><?= e($errors['password_confirm'][0]) ?></span><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
    </form>

    <div class="auth-footer"><a href="/login">← Back to sign in</a></div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
