<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recovery Code — <?= e(setting('site_name', 'App')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *,*::before,*::after{box-sizing:border-box}
    body{margin:0;font-family:'Inter',sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem}
    .card{background:#fff;border-radius:14px;box-shadow:0 4px 28px rgba(0,0,0,.09);padding:2.5rem;width:100%;max-width:420px}
    .icon{font-size:2.5rem;text-align:center;margin-bottom:1rem}
    h1{font-size:1.25rem;font-weight:700;text-align:center;margin:0 0 .4rem}
    p{color:#6b7280;font-size:14px;text-align:center;margin:0 0 1.5rem;line-height:1.6}
    .form-group{margin-bottom:1rem}
    .form-control{width:100%;padding:.7rem 1rem;border:2px solid #d1d5db;border-radius:8px;font-size:15px;font-family:'Courier New',monospace;letter-spacing:.08em;text-transform:uppercase;transition:border-color .15s,box-shadow .15s}
    .form-control:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.15)}
    .btn{display:flex;align-items:center;justify-content:center;width:100%;padding:.8rem;border-radius:8px;font-weight:600;font-size:15px;cursor:pointer;border:none;font-family:inherit;background:#4f46e5;color:#fff}
    .btn:hover{background:#3730a3}
    .alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:8px;font-size:13.5px;margin-bottom:1.25rem}
    .back{text-align:center;margin-top:1rem}
    .back a{color:#4f46e5;text-decoration:none;font-size:13px}
    </style>
</head>
<body>
<div class="card">
    <div class="icon">🔑</div>
    <h1>Use a Recovery Code</h1>
    <p>If you've lost access to your authentication device, enter one of your saved recovery codes below. Each code can only be used once.</p>

    <?php $error = \Core\Session::flash('error'); ?>
    <?php if ($error): ?><div class="alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="POST" action="/auth/2fa/recovery">
        <?= csrf_field() ?>
        <div class="form-group">
            <input type="text" name="recovery_code" class="form-control"
                   placeholder="XXXXX-XXXXX" autofocus autocomplete="off"
                   required maxlength="11"
                   oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '')" aria-label="XXXXX-XXXXX">
        </div>
        <button type="submit" class="btn">Verify Recovery Code</button>
    </form>

    <div class="back">
        <a href="/auth/2fa/challenge">← Back to verification</a>
    </div>
</div>
</body>
</html>
