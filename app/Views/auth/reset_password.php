<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:'Inter',sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;max-width:420px}h1{font-size:1.3rem;font-weight:700;margin:0 0 1.5rem}.form-group{margin-bottom:1rem}.form-group label{display:block;font-weight:500;font-size:13.5px;margin-bottom:.35rem}.form-control{width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit}.form-control:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.15)}.form-error{color:#ef4444;font-size:12px;margin-top:.25rem;display:block}.btn{display:flex;align-items:center;justify-content:center;width:100%;padding:.7rem;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;border:none;font-family:inherit;background:#4f46e5;color:#fff}.btn:hover{background:#3730a3}.back{text-align:center;margin-top:1rem;font-size:13.5px}.back a{color:#4f46e5;text-decoration:none}</style>
</head><body>
<div class="card">
    <h1>Choose a new password</h1>
    <?php $errors = \Core\Session::flash('errors') ?? []; ?>
    <?php if (!empty($errors)): ?>
    <div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.75rem;border-radius:6px;margin-bottom:1rem;font-size:13.5px">
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
        <div class="form-group">
            <label for="password-input">New Password</label>
            <input type="password" id="password-input" name="password" class="form-control <?= !empty($errors['password'])?'is-invalid':'' ?>" required minlength="12" autocomplete="new-password">
            <?php if (!empty($errors['password'])): ?><span class="form-error"><?= e($errors['password'][0]) ?></span><?php endif; ?>
            <?php include BASE_PATH . '/app/Views/layout/password_strength.php'; ?>
        </div>
        <div class="form-group">
            <label for="password_confirm">Confirm Password</label>
            <input type="password" name="password_confirm" class="form-control" required autocomplete="new-password" id="password_confirm">
            <?php if (!empty($errors['password_confirm'])): ?><span class="form-error"><?= e($errors['password_confirm'][0]) ?></span><?php endif; ?>
        </div>
        <button type="submit" class="btn">Reset Password</button>
    </form>
    <div class="back"><a href="/login">← Back to sign in</a></div>
</div>
</body></html>
