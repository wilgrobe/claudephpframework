<?php $pageTitle = 'Audit chain breaks'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/audit-chain" style="color:#4f46e5;text-decoration:none">← Audit chain</a>
</div>
<h1 style="margin:0 0 .25rem;font-size:1.3rem;font-weight:700">Chain breaks</h1>
<p style="margin:0 0 1rem;color:#6b7280;font-size:13.5px;line-height:1.55">
    Every detected mismatch between an audit_log row's stored hash and
    the recomputed value. Cause is usually one of:
    (1) a row was edited after insert (tampering),
    (2) a row was inserted out-of-order during a chain transition (rare false positive),
    (3) APP_KEY changed mid-deployment (false positive — re-seal needed).
    Investigate promptly; acknowledge once you've confirmed cause.
</p>

<div class="card">
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">When</th>
                <th style="text-align:left;padding:.4rem .75rem">Day</th>
                <th style="text-align:left;padding:.4rem .75rem">Audit row</th>
                <th style="text-align:left;padding:.4rem .75rem">Reason</th>
                <th style="text-align:left;padding:.4rem .75rem">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#10b981">
                    No breaks detected. The chain is intact.
                </td></tr>
            <?php else: foreach ($rows as $r):
                $isAck = !empty($r['acknowledged_at']);
                $reasonColors = [
                    'hash_mismatch'  => '#ef4444',
                    'prev_mismatch'  => '#f59e0b',
                    'missing_hash'   => '#6b7280',
                    'row_missing'    => '#dc2626',
                    'tampered_field' => '#dc2626',
                ];
                $color = $reasonColors[$r['reason']] ?? '#6b7280';
            ?>
                <tr style="border-top:1px solid #f3f4f6;<?= !$isAck ? 'background:#fef2f2' : '' ?>">
                    <td style="padding:.4rem .75rem;color:#6b7280;font-size:11.5px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['detected_at']))) ?></td>
                    <td style="padding:.4rem .75rem;color:#6b7280"><?= e($r['day_anchor']) ?></td>
                    <td style="padding:.4rem .75rem">
                        <a href="/admin/audit-log/<?= (int) $r['audit_log_id'] ?>" style="color:#4f46e5;text-decoration:none">
                            #<?= (int) $r['audit_log_id'] ?>
                        </a>
                    </td>
                    <td style="padding:.4rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= e($r['reason']) ?></span>
                    </td>
                    <td style="padding:.4rem .75rem;color:#6b7280">
                        <?php if ($isAck): ?>
                            <span style="font-size:11px">acknowledged by <?= e($r['ack_username'] ?? '?') ?></span>
                        <?php else: ?>
                            <span style="color:#ef4444;font-size:11px;font-weight:600">unack</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.4rem .75rem;text-align:right">
                        <?php if (!$isAck): ?>
                            <form method="POST" action="/admin/audit-chain/breaks/<?= (int) $r['id'] ?>/ack" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="text" name="notes" placeholder="cause / notes" style="font-size:11px;width:160px;margin-right:.25rem" aria-label="cause / notes">
                                <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:.15rem .5rem">Ack</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($r['expected_hash']) || !empty($r['expected_prev'])): ?>
                <tr style="border-top:1px solid #fafafa;background:#fafafa">
                    <td colspan="6" style="padding:.35rem .75rem;font-family:ui-monospace,monospace;font-size:11px;color:#6b7280">
                        <?php if ($r['expected_prev']): ?>
                            expected_prev = <?= e(substr((string) $r['expected_prev'], 0, 16)) ?>… · observed_prev = <?= e(substr((string) ($r['observed_prev'] ?? ''), 0, 16)) ?>…
                        <?php endif; ?>
                        <?php if ($r['expected_hash']): ?>
                            expected_hash = <?= e(substr((string) $r['expected_hash'], 0, 16)) ?>… · observed_hash = <?= e(substr((string) ($r['observed_hash'] ?? ''), 0, 16)) ?>…
                        <?php endif; ?>
                        <?php if ($r['notes']): ?>
                            · <?= e($r['notes']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
