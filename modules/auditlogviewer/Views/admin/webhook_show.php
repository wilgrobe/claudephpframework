<?php
/** @var string $source 'email' | 'stripe' */
/** @var array  $detail shaped row from fetchOne() */
/** @var string $csrf */
$pageTitle = 'Webhook #' . (int) $detail['id'];
include BASE_PATH . '/app/Views/layout/header.php';

$pretty = $detail['payload_decoded'] !== null
    ? json_encode($detail['payload_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : ($detail['payload_raw'] !== '' ? $detail['payload_raw'] : '(no payload stored)');
?>
<style>
.whs-shell  { max-width:1000px; margin:0 auto; }
.whs-nav    { display:flex; justify-content:space-between; align-items:center; margin-bottom:.85rem; font-size:13px; }
.whs-nav a  { color:var(--color-primary); text-decoration:none; font-weight:600; }
.whs-h1     { margin:0 0 .85rem; font-size:1.4rem; font-weight:700; }
.whs-meta   { background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem; }
.whs-grid   { display:grid; gap:.4rem 1rem; grid-template-columns:160px 1fr; font-size:13px; }
.whs-grid dt { color:var(--color-gray-500); font-weight:500; }
.whs-grid dd { margin:0; word-break:break-word; }
.whs-pill   { display:inline-block; padding:.1rem .45rem; border-radius:3px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.whs-pill.ok      { background:var(--color-success-bg); color:var(--color-success-fg); }
.whs-pill.warn    { background:var(--color-warning-bg); color:var(--color-warning-fg); }
.whs-pill.fail    { background:var(--color-danger-bg); color:var(--color-danger-fg); }

.whs-payload { background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; overflow:hidden; margin-bottom:1rem; }
.whs-payload h2 { margin:0; padding:.7rem 1rem; background:var(--bg-page); font-size:13px; font-weight:700; color:var(--color-gray-700); border-bottom:1px solid var(--color-gray-200); display:flex; justify-content:space-between; align-items:center; }
.whs-payload pre { margin:0; padding:1rem 1.25rem; font-size:11.5px; line-height:1.55; white-space:pre-wrap; word-break:break-word; font-family:ui-monospace, Menlo, Consolas, monospace; max-height:560px; overflow:auto; }

.whs-actions { display:flex; gap:.5rem; justify-content:flex-end; align-items:center; margin-top:1rem; padding:.85rem 1rem; background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; }
.btn-primary { background:var(--color-primary); color:var(--bg-panel); border:1px solid var(--color-primary); padding:.5rem 1rem; border-radius:6px; font-weight:600; cursor:pointer; font-family:inherit; font-size:13px; }
.btn-primary:disabled { background:var(--color-gray-400); border-color:var(--color-gray-400); cursor:not-allowed; }
.btn-primary:hover:not(:disabled) { background:var(--color-primary-dark); }
.btn-secondary { background: var(--bg-panel, var(--bg-panel)); color:var(--color-gray-700); border:1px solid var(--color-gray-300); padding:.5rem 1rem; border-radius:6px; font-weight:600; text-decoration:none; font-size:13px; }

.whs-debug { background:var(--bg-page); border:1px solid var(--color-gray-200); border-radius:8px; padding:.75rem 1rem; font-size:12.5px; margin-bottom:1rem; }
.whs-debug h3 { margin:0 0 .5rem; font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:var(--color-gray-500); font-weight:700; }
.whs-debug ul { margin:0; padding:0 0 0 1.25rem; color:var(--color-gray-700); line-height:1.6; }
.whs-debug code { background: var(--bg-panel, var(--bg-panel)); padding:.05rem .3rem; border-radius:3px; font-size:11.5px; }
</style>

<div class="whs-shell">
    <div class="whs-nav">
        <a href="/admin/webhooks">← All webhook deliveries</a>
        <div style="font-size:11.5px;color:var(--color-gray-400)">
            <?= htmlspecialchars($source, ENT_QUOTES) ?> · #<?= (int) $detail['id'] ?>
        </div>
    </div>

    <h1 class="whs-h1">
        <?= $source === 'stripe' ? '⚡ Stripe' : '📫 ' . htmlspecialchars(strtoupper(str_replace('email:', '', $detail['provider'])), ENT_QUOTES) ?>
        · <code style="background:var(--color-gray-100);padding:.15rem .4rem;border-radius:3px"><?= e($detail['event_type']) ?></code>
    </h1>

    <div class="whs-meta">
        <dl class="whs-grid">
            <dt>Received at</dt>
            <dd><?= e(date('M j, Y H:i:s', strtotime((string) $detail['ts']))) ?> <span style="color:var(--color-gray-400);font-size:11.5px">· <?= e((string) $detail['ts']) ?></span></dd>
            <dt>Source</dt>
            <dd><?= e(ucfirst($source)) ?> (<code><?= e($detail['provider']) ?></code>)</dd>
            <dt>Event type</dt>
            <dd><code><?= e($detail['event_type']) ?></code></dd>
            <?php if (!empty($detail['subject_label'])): ?>
            <dt>Subject</dt>
            <dd><code style="font-size:12px;word-break:break-all"><?= e((string) $detail['subject_label']) ?></code></dd>
            <?php endif; ?>
            <dt>Processed</dt>
            <dd>
                <?php if ($detail['processed']): ?>
                    <span class="whs-pill ok">✓ processed</span>
                <?php else: ?>
                    <span class="whs-pill warn">unprocessed</span>
                <?php endif; ?>
            </dd>
            <?php if (isset($detail['subscription_id'])): ?>
            <dt>Subscription</dt>
            <dd>
                <?php if ($detail['subscription_id']): ?>
                    Local id <code>#<?= (int) $detail['subscription_id'] ?></code>
                <?php else: ?>
                    <span style="color:var(--color-gray-400)">not linked locally</span>
                <?php endif; ?>
            </dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="whs-debug">
        <h3>Signature debug</h3>
        <ul>
            <?php if ($source === 'stripe'): ?>
                <li>Verification: <code>Stripe-Signature</code> header HMAC-SHA256 over <code>timestamp + '.' + payload</code> against <code>STRIPE_WEBHOOK_SECRET</code>. Tolerance 300s.</li>
                <li>Storage path: receipt → verify → <code>SubscriptionService::applyWebhookEvent</code> → <code>INSERT IGNORE INTO subscription_events (...)</code> (UNIQUE on <code>(gateway, event_id)</code> guards against double-process).</li>
                <li>Replay is safe — the per-event handlers (cancel/upgrade/etc.) are designed to be idempotent.</li>
            <?php else: ?>
                <li>Verification: <code>MAIL_WEBHOOK_SECRET</code> via Authorization Bearer / <code>X-Webhook-Secret</code> / <code>?secret=</code> query.</li>
                <li>Mailgun additionally requires <code>MAILGUN_SIGNING_KEY</code> HMAC-SHA256 over <code>timestamp + token</code>.</li>
                <li>Storage path: receipt → verify → parse → <code>INSERT INTO mail_bounce_events</code> + <code>mail_suppressions</code> if hard bounce/complaint.</li>
                <li>Replay disabled — the suppression triggered at receipt has already updated <code>mail_suppressions</code>.</li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="whs-payload">
        <h2>
            Payload
            <span style="font-size:11px;font-weight:500;color:var(--color-gray-400)"><?= number_format(strlen($detail['payload_raw'])) ?> bytes<?= $detail['payload_decoded'] === null ? ' (raw — JSON parse failed)' : '' ?></span>
        </h2>
        <pre><?= e($pretty) ?></pre>
    </div>

    <div class="whs-actions">
        <?php if ($detail['replay_supported']): ?>
            <form method="post" action="/admin/webhooks/<?= e($source) ?>/<?= (int) $detail['id'] ?>/replay">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <button type="submit" class="btn-primary"
                        onclick="return confirm('Replay this Stripe event? Idempotent on event_id — a re-run is safe even on a fully-processed event, but it will fire any side-effect listeners again.');">
                    🔄 Replay event
                </button>
            </form>
        <?php else: ?>
            <span style="color:var(--color-gray-400);font-size:12.5px;font-style:italic"><?= e((string) ($detail['replay_disabled_reason'] ?? 'Replay not supported for this source.')) ?></span>
            <button class="btn-primary" disabled>🔄 Replay event</button>
        <?php endif; ?>
        <a href="/admin/webhooks" class="btn-secondary">← Back to list</a>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
