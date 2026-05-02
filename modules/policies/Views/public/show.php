<?php
$pageTitle = ($kind['label'] ?? 'Policy')
    . ($version ? ' — v' . $version['version_label'] : '');
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto;padding:0 1rem;font-size:15px;line-height:1.7">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/policies/<?= htmlspecialchars((string) $kind['slug'], ENT_QUOTES) ?>" style="color:#4f46e5;text-decoration:none">
        ← <?= htmlspecialchars((string) $kind['label'], ENT_QUOTES) ?> (current)
    </a>
</div>
<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700">
    <?= htmlspecialchars((string) $kind['label'], ENT_QUOTES) ?>
</h1>

<?php if ($version): ?>
    <div style="color:#6b7280;font-size:13px;margin-bottom:1.5rem">
        Version <strong>v<?= htmlspecialchars((string) $version['version_label'], ENT_QUOTES) ?></strong>
        — effective <?= htmlspecialchars(date('F j, Y', strtotime((string) $version['effective_date'])), ENT_QUOTES) ?>
    </div>

    <?php if (!empty($version['summary'])): ?>
        <div style="background:#fafafa;border-left:3px solid #4f46e5;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:13.5px">
            <strong>What changed in this version:</strong>
            <div style="margin-top:.25rem;color:#374151"><?= htmlspecialchars((string) $version['summary'], ENT_QUOTES) ?></div>
        </div>
    <?php endif; ?>

    <article class="page-body" style="line-height:1.8">
        <?php
        // Body was sanitised when first stored on the source page; we
        // re-sanitise on render in case the storage was bypassed.
        echo \Core\Validation\Validator::sanitizeHtml((string) ($version['body_html'] ?? ''));
        ?>
    </article>
<?php else: ?>
    <p style="margin:1rem 0;color:#6b7280">
        This policy has not been published yet. Please check back later.
    </p>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
