<?php /* app/Views/auth/forgot_password.php */ ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:'Inter',sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;max-width:400px}h1{font-size:1.3rem;font-weight:700;margin:0 0 .5rem}p{color:#6b7280;font-size:14px;margin:0 0 1.5rem}.form-group{margin-bottom:1rem}.form-group label{display:block;font-weight:500;font-size:13.5px;margin-bottom:.35rem}.form-control{width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit}.form-control:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.15)}.btn{display:flex;align-items:center;justify-content:center;width:100%;padding:.7rem;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;border:none;font-family:inherit;background:#4f46e5;color:#fff}.btn:hover{background:#3730a3}.back{text-align:center;margin-top:1rem;font-size:13.5px}.back a{color:#4f46e5;text-decoration:none}.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:13.5px}.alert-success{background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:13.5px}</style>
</head><body>
<div class="card">
    <h1>Forgot your password?</h1>
    <p>Enter your email address and we'll send you a reset link.</p>
    <?php $err = \Core\Session::flash('error'); ?>
    <?php if ($err): ?><div class="alert-error"><?= e($err) ?></div><?php endif; ?>
    <?php $ok = \Core\Session::flash('success'); ?>
    <?php if ($ok): ?><div class="alert-success"><?= e($ok) ?></div><?php endif; ?>
    <form method="POST" action="/password/forgot">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" name="email" class="form-control" required autofocus id="email">
        </div>
        <button type="submit" class="btn">Send Reset Link</button>
    </form>
    <div class="back"><a href="/login">← Back to sign in</a></div>
</div>
</body></html>
