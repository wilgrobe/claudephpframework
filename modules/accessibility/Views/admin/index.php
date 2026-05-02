<?php $pageTitle = 'Accessibility lint'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Accessibility lint</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px;line-height:1.55">
            Static analysis of templates for WCAG 2.1 AA violations.
            Re-scans every page load (sub-second for typical framework).
            Also available via <code>php artisan a11y:lint</code> for CI.
        </p>
    </div>
    <form method="POST" action="/admin/a11y/rescan" style="display:inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary" style="font-size:12.5px">Re-scan</button>
    </form>
</div>

<!-- Summary -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total findings', (int) ($summary['total']    ?? 0), '#374151'],
            ['Errors',         (int) ($summary['errors']   ?? 0), ($summary['errors'] > 0 ? '#ef4444' : '#10b981')],
            ['Warnings',       (int) ($summary['warnings'] ?? 0), ($summary['warnings'] > 0 ? '#f59e0b' : '#10b981')],
            ['Files scanned',  count($roots), '#6b7280'],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 140px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:#fafafa;border-radius:4px">
                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Top rules -->
<?php if (!empty($summary['by_rule'])): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Findings by rule</strong></div>
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <tbody>
            <?php foreach ($summary['by_rule'] as $rule => $n): ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;font-family:ui-monospace,monospace;font-size:12px"><?= e($rule) ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;font-weight:600"><?= (int) $n ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Findings, grouped by file -->
<?php if (empty($findings)): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:1rem 1.25rem;border-radius:8px;font-size:14px;text-align:center">
    No accessibility issues found. Clean across <?= count($roots) ?> template root(s).
</div>
<?php else: ?>
<?php
$byFile = [];
foreach ($findings as $f) {
    $byFile[$f['file']][] = $f;
}
ksort($byFile);
$basePath = defined('BASE_PATH') ? rtrim(BASE_PATH, '/\\') : '';
?>
<?php foreach ($byFile as $file => $items):
    $relFile = $basePath !== '' && str_starts_with($file, $basePath)
        ? ltrim(substr($file, strlen($basePath)), '/\\')
        : $file;
?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.6rem 1rem;background:#fafafa">
        <strong style="font-size:12.5px;font-family:ui-monospace,monospace"><?= e($relFile) ?></strong>
        <span style="color:#9ca3af;font-size:11.5px;margin-left:.5rem"><?= count($items) ?> finding<?= count($items) === 1 ? '' : 's' ?></span>
    </div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <tbody>
            <?php foreach ($items as $f):
                $sev = $f['severity'];
                $color = $sev === 'error' ? '#ef4444' : '#f59e0b';
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;color:#6b7280;font-family:ui-monospace,monospace;font-size:11px;white-space:nowrap;width:60px;text-align:right">L<?= (int) $f['line'] ?></td>
                    <td style="padding:.4rem .75rem;width:140px">
                        <span style="display:inline-block;padding:.05rem .4rem;border-radius:999px;color:#fff;font-size:10px;background:<?= $color ?>"><?= e($sev) ?></span>
                        <code style="margin-left:.25rem;font-size:11px"><?= e($f['rule']) ?></code>
                    </td>
                    <td style="padding:.4rem .75rem;color:#374151;line-height:1.45"><?= e($f['message']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div style="margin:1.5rem 0;padding:1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12.5px;color:#92400e;line-height:1.6">
    <strong>Note:</strong> This is a template-level static lint, not a full
    runtime accessibility audit. It catches the high-value WCAG 2.1 AA issues
    that show up in HTML source but won't catch dynamic / interaction-based
    problems (focus traps, ARIA live-region semantics, color contrast in
    rendered output). Pair with axe-core or Lighthouse in your browser for
    runtime coverage.
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
