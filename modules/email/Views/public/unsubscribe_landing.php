<?php $pageTitle = 'Unsubscribe'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unsubscribe — <?= e(setting('site_name', 'App')) ?></title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f9fafb;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.box{background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2rem;max-width:480px;width:100%;text-align:center}
.box h1{margin:0 0 .5rem;font-size:1.5rem}
.box p{color:#6b7280;font-size:14px;line-height:1.6;margin:.5rem 0}
.email{font-family:ui-monospace,monospace;background:#f3f4f6;padding:.15rem .5rem;border-radius:4px;font-size:13px}
.cat{display:inline-block;background:#fef3c7;color:#92400e;padding:.15rem .5rem;border-radius:999px;font-size:12px;font-weight:500;margin-top:.25rem}
form{margin-top:1.5rem}
button{background:#ef4444;color:#fff;border:0;padding:.7rem 1.25rem;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
button:hover{background:#dc2626}
.cancel{display:block;margin-top:.75rem;color:#6b7280;text-decoration:none;font-size:13px}
.cancel:hover{color:#111827}
</style>
</head>
<body>
<div class="box">
    <h1>Unsubscribe?</h1>
    <p>You're about to unsubscribe <span class="email"><?= e($email) ?></span> from</p>
    <p><span class="cat"><?= e($category['label']) ?></span></p>
    <p>This won't affect transactional emails (order receipts, password resets, etc.).</p>
    <form method="POST" action="/unsubscribe/<?= e($token) ?>">
        <?= csrf_field() ?>
        <button type="submit">Confirm unsubscribe</button>
        <a class="cancel" href="<?= e(setting('app.url') ?? '/') ?>">Never mind, keep me subscribed</a>
    </form>
</div>
</body>
</html>
