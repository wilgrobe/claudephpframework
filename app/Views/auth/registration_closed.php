<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registration Closed — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/auth.css')) ?>">
    <?php echo (new \Core\Services\ThemeService(new \Core\Services\SettingsService()))->renderOverrideStyle(); /* site theme — light-dark */ ?>
</head>
<body class="auth">
<div class="auth-card" style="text-align:center">
    <div class="auth-icon auth-icon--warning">🔒</div>
    <div class="auth-logo">
        <h1>Registration is closed</h1>
        <p>New accounts are not currently being accepted. Please contact an administrator if you need access.</p>
    </div>
    <a href="/login" class="btn btn-primary">← Back to sign in</a>
</div>
<?php /* asset() appends ?v=<mtime>. Without it Cloudflare serves this from
         its edge cache for weeks - measured 2026-09-11: the auth pages were
         getting a copy 10.4 hours stale (cf-cache-status HIT, age 37504),
         which is why the CSRF refresher shipped but never actually ran on the
         sign-in page. The app layout has always used asset(); these standalone
         auth documents did not. */ ?>
<script src="<?= e(asset("/assets/js/app.js")) ?>"></script>
<?php include BASE_PATH . '/app/Views/partials/_cookie_banner.php'; ?>
</body>
</html>
