<?php
/**
 * "Check your email" — after registering on a site that requires a verified address, or after signing in
 * with one that is not verified yet. AuthController::verifySent only renders it for the account the session
 * remembered, so naming the address here tells nobody anything they did not just type.
 *
 * @var string $email
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check your email — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/auth.css')) ?>">
    <?php echo (new \Core\Services\ThemeService(new \Core\Services\SettingsService()))->renderOverrideStyle(); /* site theme — light-dark */ ?>
<style>
  .ce-steps { text-align:left; margin:1.1rem 0 1.3rem; padding-left:1.2rem; line-height:1.6; font-size:.92rem; }
  .ce-steps li { margin:0 0 .4rem; }
  .ce-email { font-weight:700; overflow-wrap:anywhere; }
  .ce-actions { display:flex; flex-direction:column; gap:.6rem; align-items:stretch; }
  .ce-actions form { margin:0; }
  .ce-actions .btn { width:100%; justify-content:center; text-align:center; }
  .ce-small { font-size:.84rem; opacity:.8; margin:1rem 0 0; }
</style>
</head>
<body class="auth">
<div class="auth-card" style="text-align:center">
    <div class="auth-icon auth-icon--success">📧</div>
    <div class="auth-logo">
        <h1>Check your email</h1>
        <p>We sent a verification link to <span class="ce-email"><?= e($email) ?></span>.</p>
    </div>

    <?php $err = \Core\Session::flash('error'); ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
    <?php $success = \Core\Session::flash('success'); ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <ol class="ce-steps">
        <li>Open the email from <?= e(setting('site_name','App')) ?>.</li>
        <li>Click the verification link. It works for 24 hours.</li>
        <li>Then sign in with the email and password you just used.</li>
    </ol>

    <div class="ce-actions">
        <form method="post" action="/verify-email/resend">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">Send the email again</button>
        </form>
        <a href="/login" class="btn btn-primary">I've verified — sign in</a>
    </div>

    <p class="ce-small">Not there after a few minutes? Check your spam or promotions folder.
        Wrong address? <a href="/register">Register again</a> with the right one.</p>
</div>
<?php /* asset() appends ?v=<mtime> so Cloudflare's edge cache cannot serve a stale copy (see registration_closed.php). */ ?>
<script src="<?= e(asset("/assets/js/app.js")) ?>"></script>
<?php include BASE_PATH . '/app/Views/partials/_cookie_banner.php'; ?>
</body>
</html>
