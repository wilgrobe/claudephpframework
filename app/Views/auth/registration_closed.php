<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registration Closed — <?= e(setting('site_name','App')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="stylesheet" href="/assets/css/auth.css">
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
</body>
</html>
