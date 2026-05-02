<?php $pageTitle = 'Disable Two-Factor Authentication'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:480px;margin:0 auto">
<div class="card">
    <div class="card-header">
        <h2>Disable 2FA</h2>
        <a href="/profile/2fa" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:1rem;margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:flex-start">
            <span style="font-size:1.3rem;flex-shrink:0">⚠️</span>
            <div style="font-size:13.5px;color:#991b1b;line-height:1.5">
                Disabling two-factor authentication will make your account less secure. You will no longer need a second step when signing in.
            </div>
        </div>

        <?php $error = \Core\Session::flash('error'); ?>
        <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;padding:.75rem 1rem;border-radius:6px;font-size:13.5px;border:1px solid #fca5a5;margin-bottom:1rem"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" action="/profile/2fa/disable">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="password">Confirm your password to continue</label>
                <input type="password" name="password" class="form-control" required autofocus autocomplete="current-password" placeholder="Your current password" id="password">
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-danger" style="background:#dc2626;color:#fff;border-color:#dc2626">Disable 2FA</button>
                <a href="/profile/2fa" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
