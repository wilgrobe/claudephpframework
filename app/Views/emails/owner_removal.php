<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;background:#f4f4f5;padding:2rem 1rem}.card{background:#fff;max-width:520px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}.header{background:#dc2626;padding:1.5rem;text-align:center;color:#fff}.header h1{margin:0;font-size:1.3rem;font-weight:700}.body{padding:2rem}.body p{color:#374151;line-height:1.7;margin:0 0 1rem}.btn-approve{display:inline-block;background:#dc2626;color:#fff;padding:.7rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;margin:.5rem .25rem}.btn-reject{display:inline-block;background:#6b7280;color:#fff;padding:.7rem 1.5rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;margin:.5rem .25rem}.notice{background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:.85rem 1rem;font-size:13.5px;color:#92400e;margin:1rem 0}.footer{padding:1rem 2rem;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center}</style>
</head><body>
<div class="card">
    <div class="header"><h1>Group Owner Removal Request</h1></div>
    <div class="body">
        <p>Hi,</p>
        <p>
            <strong><?= e(($requester['first_name'] ?? '').' '.($requester['last_name'] ?? '')) ?></strong>,
            another owner of the group <strong><?= e($group['name'] ?? '') ?></strong>, has requested
            to remove you as a group owner.
        </p>
        <?php
        $outcomeText = isset($outcomeRole) && $outcomeRole
            ? 'your role will change to <strong>' . e($outcomeRole['name']) . '</strong>'
            : 'you will be <strong>removed from the group entirely</strong>';
        ?>
        <div class="notice">
            ⚠️ If you approve, <?= $outcomeText ?>. Reversing this requires another group owner to reassign your previous permissions.
        </div>
        <p>Please choose an action:</p>
        <p style="text-align:center">
            <a href="<?= e($approveUrl) ?>" class="btn-approve">Approve Removal</a>
            <a href="<?= e($rejectUrl) ?>" class="btn-reject">Reject Request</a>
        </p>
        <p style="font-size:13px;color:#6b7280">If you take no action, your ownership remains unchanged.</p>
    </div>
    <div class="footer"><?= e(setting('site_name','App')) ?></div>
</div>
</body></html>
