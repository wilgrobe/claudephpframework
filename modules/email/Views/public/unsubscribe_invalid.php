<?php $pageTitle = 'Link expired'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Link expired — <?= e(setting('site_name', 'App')) ?></title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f9fafb;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.box{background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2rem;max-width:480px;width:100%;text-align:center}
.x{display:inline-flex;width:48px;height:48px;border-radius:50%;background:#fee2e2;color:#ef4444;font-size:28px;font-weight:700;align-items:center;justify-content:center;margin-bottom:1rem}
.box h1{margin:0 0 .5rem;font-size:1.4rem}
.box p{color:#6b7280;font-size:14px;line-height:1.6;margin:.5rem 0}
.box a{color:#4f46e5;text-decoration:none}
</style>
</head>
<body>
<div class="box">
    <div class="x">!</div>
    <h1>Unsubscribe link expired</h1>
    <p>This unsubscribe link has expired or wasn't recognised. You can manage your email preferences from your account.</p>
    <p><a href="/account/email-preferences">Sign in to manage preferences</a></p>
</div>
</body>
</html>
