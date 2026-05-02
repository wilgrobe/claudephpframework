<?php
// app/Views/public/search.php
//
// Page-chrome Batch C: fragment view. The `search` system layout
// (1×1, max-width 1024px) provides the surrounding chrome.
?>
<?php $pageTitle = $q ? 'Search: ' . e($q) : 'Search'; ?>

<div style="max-width:720px;margin:0 auto">

    <form method="GET" action="/search" style="margin-bottom:1.5rem;display:flex;gap:.5rem">
        <input type="text" name="q" class="form-control" value="<?= e($q) ?>"
               placeholder="Search pages, content, FAQ…" autofocus style="flex:1;font-size:15px;padding:.65rem .9rem" aria-label="Search pages, content, FAQ…">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <?php if ($q && strlen($q) < 2): ?>
    <p style="color:#6b7280">Please enter at least 2 characters.</p>
    <?php elseif ($q): ?>

        <?php $total = count($results['content'] ?? []) + count($results['pages'] ?? []) + count($results['faqs'] ?? []); ?>
        <p style="color:#6b7280;font-size:13.5px;margin-bottom:1.25rem">
            <?= $total ?> result<?= $total !== 1 ? 's' : '' ?> for <strong><?= e($q) ?></strong>
        </p>

        <?php if (!empty($results['pages'])): ?>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2 style="font-size:.9rem">Pages</h2></div>
            <?php foreach ($results['pages'] as $p): ?>
            <div style="padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6">
                <a href="/page/<?= e($p['slug']) ?>" style="font-weight:500;font-size:14.5px"><?= e($p['title']) ?></a>
                <div style="font-size:12px;color:#9ca3af">/page/<?= e($p['slug']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($results['content'])): ?>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2 style="font-size:.9rem">Content</h2></div>
            <?php foreach ($results['content'] as $c): ?>
            <div style="padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6">
                <a href="/content/<?= e($c['slug']) ?>" style="font-weight:500;font-size:14.5px"><?= e($c['title']) ?></a>
                <div style="font-size:12px;color:#9ca3af">
                    <span class="badge badge-gray"><?= e($c['type']) ?></span>
                    <?= $c['published_at'] ? ' · ' . date('M j, Y', strtotime($c['published_at'])) : '' ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($results['faqs'])): ?>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2 style="font-size:.9rem">FAQ</h2></div>
            <?php foreach ($results['faqs'] as $f): ?>
            <div style="padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6">
                <a href="/faq#faq-<?= $f['id'] ?>" style="font-weight:500;font-size:14.5px"><?= e($f['question']) ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($total === 0): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:2.5rem">
                <div style="font-size:2rem;margin-bottom:.75rem">🔍</div>
                <p style="color:#6b7280;margin:0">No results found for <strong><?= e($q) ?></strong>.</p>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
