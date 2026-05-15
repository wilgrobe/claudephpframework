<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;background:#f4f4f5;padding:2rem 1rem}
.card{background: var(--bg-panel, #fff);max-width:520px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.header{background:var(--color-primary);padding:1.5rem;text-align:center;color:#fff}
.header h1{margin:0;font-size:1.3rem;font-weight:700}
.body{padding:2rem}
.body p{color:var(--color-gray-700);line-height:1.7;margin:0 0 1rem}
.btn{display:inline-block;background:var(--color-primary);color:#fff;padding:.8rem 2rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;margin:1rem 0}
.footer{padding:1rem 2rem;background:var(--color-gray-50);border-top:1px solid var(--color-gray-200);font-size:12px;color:var(--color-gray-400);text-align:center}
</style></head><body>
<div class="card">
    <div class="header"><h1>You're Invited to <?= e($group['name'] ?? 'a group') ?></h1></div>
    <div class="body">
        <p>Hi there,</p>
        <p>
            <strong><?= e(($inviter['first_name'] ?? '').' '.($inviter['last_name'] ?? '')) ?></strong>
            has invited you to join the group <strong><?= e($group['name'] ?? '') ?></strong>.
        </p>
        <p>Click the button below to accept your invitation. This link expires in <strong>7 days</strong>.</p>
        <p style="text-align:center"><a href="<?= e($joinUrl) ?>" class="btn">Accept Invitation</a></p>
        <p style="font-size:13px;color:var(--color-gray-500)">Or paste this link: <a href="<?= e($joinUrl) ?>"><?= e($joinUrl) ?></a></p>
        <p>If you don't know why you received this email, you can safely ignore it.</p>
    </div>
    <div class="footer"><?= e(setting('site_name','App')) ?></div>
</div>
</body></html>
