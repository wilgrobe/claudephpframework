<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;background:#f4f4f5;padding:2rem 1rem}.card{background:#fff;max-width:520px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}.header{background:#4f46e5;padding:1.5rem;text-align:center;color:#fff}.header h1{margin:0;font-size:1.3rem;font-weight:700}.body{padding:2rem}.body p{color:#374151;line-height:1.7;margin:0 0 1rem}.btn{display:inline-block;background:#4f46e5;color:#fff;padding:.8rem 2rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;margin:1rem 0}.footer{padding:1rem 2rem;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center}</style>
</head><body>
<div class="card">
    <div class="header"><h1>Reset Your Password</h1></div>
    <div class="body">
        <p>Hi <?= e($user['first_name'] ?? 'there') ?>,</p>
        <p>We received a request to reset the password for your account. Click the button below to choose a new password.</p>
        <p style="text-align:center"><a href="<?= e($resetUrl) ?>" class="btn">Reset Password</a></p>
        <p style="font-size:13px;color:#6b7280">Or paste this link in your browser: <br><code style="word-break:break-all;background:#f3f4f6;padding:2px 4px;border-radius:3px;font-size:12px"><?= e($resetUrl) ?></code></p>
        <?php
            // $ttlMinutes comes from AuthController::sendPasswordResetEmail.
            // Mirror the admin-page label logic: < 60 shows as minutes,
            // ≥ 60 shows as hours. Default to 2 hours on missing data.
            $ttl = (int) ($ttlMinutes ?? 120);
            if ($ttl < 1) $ttl = 120;
            if ($ttl < 60) {
                $ttlLabel = $ttl . ' minute' . ($ttl === 1 ? '' : 's');
            } else {
                $h = intdiv($ttl, 60);
                $ttlLabel = $h . ' hour' . ($h === 1 ? '' : 's');
            }
        ?>
        <p style="font-size:13px;color:#6b7280">This link expires in <strong><?= e($ttlLabel) ?></strong>.</p>
        <p style="font-size:13px;color:#6b7280">If you didn't request a password reset, ignore this email. Your password won't change.</p>
    </div>
    <div class="footer"><?= e(setting('site_name','App')) ?></div>
</div>
</body></html>
