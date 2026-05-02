<?php $pageTitle = 'Unsubscribed'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unsubscribed — <?= e(setting('site_name', 'App')) ?></title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f9fafb;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.box{background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2rem;max-width:480px;width:100%;text-align:center}
.check{display:inline-flex;width:48px;height:48px;border-radius:50%;background:#d1fae5;color:#10b981;font-size:24px;font-weight:700;align-items:center;justify-content:center;margin-bottom:1rem}
.box h1{margin:0 0 .5rem;font-size:1.4rem}
.box p{color:#6b7280;font-size:14px;line-height:1.6;margin:.5rem 0}
.box a{color:#4f46e5;text-decoration:none}
</style>
</head>
<body>
<div class="box">
    <div class="check">✓</div>
    <h1>You've been unsubscribed</h1>
    <p>You won't receive any more <?= e($category) ?> emails from us.</p>
    <p>If you change your mind, you can <a href="/account/email-preferences">manage your preferences</a> any time after signing in.</p>
</div>
</body>
</html>
