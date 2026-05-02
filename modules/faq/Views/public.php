<?php $pageTitle = 'Frequently Asked Questions'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:800px;margin:0 auto">
    <h1 style="font-size:1.75rem;font-weight:700;margin-bottom:.5rem">FAQ</h1>
    <p style="color:#6b7280;margin-bottom:2rem">Find answers to common questions below.</p>

    <!-- Search -->
    <div style="margin-bottom:1.5rem">
        <input type="text" id="faq-search" class="form-control" placeholder="Search questions…" aria-label="Search FAQs" style="max-width:400px" oninput="filterFaq(this.value)">
    </div>

    <?php foreach ($categories as $cat):
        $catFaqs = $cat['faqs_list'] ?? [];
        if (empty($catFaqs)) continue;
    ?>
    <div class="faq-category" style="margin-bottom:2rem">
        <h2 style="font-size:1.1rem;font-weight:700;border-bottom:2px solid #e5e7eb;padding-bottom:.5rem;margin-bottom:1rem">
            <?= e($cat['name']) ?>
        </h2>
        <?php foreach ($catFaqs as $faq): ?>
        <div class="faq-item" style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:.5rem;overflow:hidden">
            <button onclick="toggleFaq(this)"
                    style="width:100%;text-align:left;padding:.85rem 1.1rem;background:#fff;border:none;cursor:pointer;font-size:14.5px;font-weight:500;font-family:inherit;display:flex;justify-content:space-between;align-items:center">
                <span class="faq-q"><?= e($faq['question']) ?></span>
                <span style="font-size:1.1rem;color:#9ca3af;flex-shrink:0;margin-left:.5rem">+</span>
            </button>
            <div class="faq-answer" style="display:none;padding:.75rem 1.1rem 1rem;border-top:1px solid #f3f4f6;color:#374151;font-size:14px;line-height:1.7">
                <?= $faq['answer'] /* already sanitized on store */ ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($uncategorized)): ?>
    <div class="faq-category" style="margin-bottom:2rem">
        <h2 style="font-size:1.1rem;font-weight:700;border-bottom:2px solid #e5e7eb;padding-bottom:.5rem;margin-bottom:1rem">General</h2>
        <?php foreach ($uncategorized as $faq): ?>
        <div class="faq-item" style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:.5rem;overflow:hidden">
            <button onclick="toggleFaq(this)" style="width:100%;text-align:left;padding:.85rem 1.1rem;background:#fff;border:none;cursor:pointer;font-size:14.5px;font-weight:500;font-family:inherit;display:flex;justify-content:space-between;align-items:center">
                <span class="faq-q"><?= e($faq['question']) ?></span>
                <span style="font-size:1.1rem;color:#9ca3af;flex-shrink:0;margin-left:.5rem">+</span>
            </button>
            <div class="faq-answer" style="display:none;padding:.75rem 1.1rem 1rem;border-top:1px solid #f3f4f6;color:#374151;font-size:14px;line-height:1.7">
                <?= $faq['answer'] ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($categories) && empty($uncategorized)): ?>
    <div style="text-align:center;padding:3rem;color:#6b7280">
        <div style="font-size:2.5rem;margin-bottom:.75rem">❓</div>
        <p>No FAQ entries have been added yet.</p>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleFaq(btn) {
    const answer = btn.nextElementSibling;
    const icon   = btn.querySelector('span:last-child');
    const open   = answer.style.display !== 'none';
    answer.style.display = open ? 'none' : 'block';
    icon.textContent = open ? '+' : '−';
}
function filterFaq(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.faq-item').forEach(item => {
        const text = item.querySelector('.faq-q')?.textContent.toLowerCase() || '';
        item.style.display = text.includes(q) ? '' : 'none';
    });
    document.querySelectorAll('.faq-category').forEach(cat => {
        const visible = [...cat.querySelectorAll('.faq-item')].some(i => i.style.display !== 'none');
        cat.style.display = visible ? '' : 'none';
    });
}
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
