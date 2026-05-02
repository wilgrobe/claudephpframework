<?php $pageTitle = 'Message Log'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<?php
// Detect which local-capture drivers are active so the admin knows why the
// page suddenly has SMS/webhook rows during development.
$isDev = ($_ENV['APP_ENV'] ?? 'production') !== 'production';

$smsDriver       = $_ENV['SMS_DRIVER']     ?? 'auto';
$webhookDriver   = $_ENV['WEBHOOK_DRIVER'] ?? 'auto';
$smsCapture      = $isDev && ($smsDriver     === '' || in_array($smsDriver,     ['auto','log','capture'], true));
$webhookCapture  = $isDev && ($webhookDriver === '' || in_array($webhookDriver, ['auto','log','capture'], true));
?>
<?php if ($smsCapture || $webhookCapture): ?>
<div style="margin-bottom:1rem;padding:.65rem .9rem;border:1px solid #c7d2fe;background:#eef2ff;border-radius:6px;font-size:13px;color:#3730a3">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.25rem">
        <span>🧪</span><strong>Local-capture mode is active for:</strong>
    </div>
    <ul style="margin:.15rem 0 .15rem 1.5rem;padding:0;font-size:12.5px">
        <?php if ($smsCapture): ?>
        <li><strong>SMS</strong> — rows land here with provider=<code>log</code>; no carrier dispatched. Override via <code>SMS_DRIVER</code>.</li>
        <?php endif; ?>
        <?php if ($webhookCapture): ?>
        <li><strong>Webhooks</strong> — outbound HTTP calls are recorded without being sent. Override via <code>WEBHOOK_DRIVER</code>.</li>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem">
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0">
            <label for="channel" style="font-size:12px">Channel</label>
            <select name="channel" class="form-control" id="channel">
                <option value="">All</option>
                <option value="email"   <?= $channel==='email'  ?'selected':'' ?>>Email</option>
                <option value="sms"     <?= $channel==='sms'    ?'selected':'' ?>>SMS</option>
                <option value="webhook" <?= $channel==='webhook'?'selected':'' ?>>Webhook</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label for="status" style="font-size:12px">Status</label>
            <select name="status" class="form-control" id="status">
                <option value="">All</option>
                <option value="queued" <?= $status==='queued'?'selected':'' ?>>Queued</option>
                <option value="sent"   <?= $status==='sent'  ?'selected':'' ?>>Sent</option>
                <option value="failed" <?= $status==='failed'?'selected':'' ?>>Failed</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="/admin/superadmin/message-log" class="btn btn-secondary btn-sm">Clear</a>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Message Log</h2>
        <span style="color:#6b7280;font-size:13px"><?= number_format($log['total']) ?> entries</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Time</th><th>Channel</th><th>Recipient</th><th>Subject/Body</th><th>Status</th><th>Attempts</th><th>Provider</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($log['items'] as $row): ?>
            <tr>
                <td style="white-space:nowrap;font-size:12px;color:#6b7280"><?= date('M j H:i', strtotime($row['created_at'])) ?></td>
                <td>
                    <?php $chBadge = ['email'=>'badge-primary','sms'=>'badge-gray','webhook'=>'badge-info'][$row['channel']] ?? 'badge-gray'; ?>
                    <span class="badge <?= $chBadge ?>"><?= e($row['channel']) ?></span>
                </td>
                <td style="font-size:13px"><?= e($row['recipient']) ?></td>
                <td style="font-size:13px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= e($row['preview'] ?? ($row['subject'] ?: substr(strip_tags($row['body']),0,80))) ?>
                </td>
                <td>
                    <?php $badge = ['sent'=>'badge-success','failed'=>'badge-danger','queued'=>'badge-warning'][$row['status']] ?? 'badge-gray'; ?>
                    <span class="badge <?= $badge ?>"><?= e($row['status']) ?></span>
                    <?php if ($row['error']): ?>
                    <div style="font-size:11px;color:#dc2626;margin-top:.15rem"><?= e(substr($row['error'],0,60)) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#6b7280;white-space:nowrap">
                    <?= (int)($row['attempts'] ?? 0) ?> / <?= (int)($row['max_attempts'] ?? 3) ?>
                    <?php if (!empty($row['next_attempt_at']) && $row['status'] === 'failed'): ?>
                        <div style="font-size:11px">next: <?= e(date('M j H:i', strtotime($row['next_attempt_at']))) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#6b7280">
                    <?= e($row['provider'] ?? '—') ?>
                    <?php if (($row['provider'] ?? '') === 'log' || ($row['provider'] ?? '') === 'capture'): ?>
                        <span title="Local capture — not actually delivered" style="display:inline-block;margin-left:.2rem;padding:0 .35rem;border-radius:3px;background:#fef3c7;color:#92400e;font-size:10.5px;font-weight:600">CAPTURED</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <?php if ($row['status'] === 'failed'): ?>
                    <form method="POST" action="/admin/superadmin/message-log/<?= (int)$row['id'] ?>/retry" style="margin:0">
                        <?= csrf_field() ?>
                        <button class="btn btn-xs btn-secondary" title="Force re-send now">Retry</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:1rem 1.25rem">
        <?php include BASE_PATH . '/app/Views/layout/pagination.php'; ?>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
