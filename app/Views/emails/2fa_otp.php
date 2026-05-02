<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;background:#f4f4f5;padding:2rem 1rem}
.card{background:#fff;max-width:480px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.header{background:#4f46e5;padding:1.5rem;text-align:center;color:#fff}
.header h1{margin:0;font-size:1.2rem;font-weight:700}
.body{padding:2rem;text-align:center}
.body p{color:#374151;line-height:1.7;margin:0 0 1rem;text-align:left;font-size:14px}
.code-box{
    background:#eef2ff;border:2px solid #c7d2fe;border-radius:10px;
    padding:1.25rem 2rem;margin:1.5rem auto;display:inline-block;
    font-family:'Courier New',monospace;font-size:2.2rem;font-weight:900;
    letter-spacing:.35rem;color:#3730a3;text-align:center;
}
.expiry{font-size:13px;color:#6b7280;margin-top:-.5rem;margin-bottom:1rem}
.divider{border-top:1px solid #e5e7eb;margin:1.5rem 0}
.footer{padding:1rem 2rem;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center}
.warning{background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:.75rem 1rem;font-size:12.5px;color:#92400e;text-align:left}
</style>
</head><body>
<div class="card">
    <div class="header">
        <div style="font-size:1.8rem;margin-bottom:.35rem">🔐</div>
        <h1>Your Verification Code</h1>
    </div>
    <div class="body">
        <p>Hi <?= e($first_name) ?>,</p>
        <p>Use the code below to complete your sign-in to <strong><?= e(setting('site_name','App')) ?></strong>.</p>
        <div class="code-box"><?= e($code) ?></div>
        <div class="expiry">Expires in <?= (int) $expiry ?> minutes</div>
        <div class="warning">
            <strong>Never share this code.</strong> We will never ask for it by phone, email, or chat. If you didn't request this, you can safely ignore this message.
        </div>
    </div>
    <div class="footer"><?= e(setting('site_name','App')) ?> · Two-Factor Authentication</div>
</div>
</body></html>
