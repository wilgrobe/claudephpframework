<?php
// modules/faq/Views/public.php
//
// Page-chrome Batch C: fragment view. The `faq` system layout
// (1×1, max-width 800px) provides the surrounding header/footer.
?>
<?php $pageTitle = 'Frequently Asked Questions'; ?>

<div style="max-width:800px;margin:0 auto">
    <h1 style="font-size:1.75rem;font-weight:700;margin-bottom:.5rem">FAQ</h1>
    <p style="color:var(--color-gray-500);margin-bottom:2rem">Find answers to common questions below.</p>

    <!-- Search -->
    <div style="margin-bottom:1.5rem">
        <input type="text" id="faq-search" class="form-control" placeholder="Search questions…" aria-label="Search FAQs" style="max-width:400px" oninput="filterFaq(this.value)">
    </div>

    <?php foreach ($categories as $cat):
        $catFaqs = $cat['faqs_list'] ?? [];
        if (empty($catFaqs)) continue;
    ?>
    <div class="faq-category" style="margin-bottom:2rem">
        <h2 style="font-size:1.1rem;font-weight:700;border-bottom:2px solid var(--color-gray-200);padding-bottom:.5rem;margin-bottom:1rem">
            <?= e($cat['name']) ?>
        </h2>
        <?php foreach ($catFaqs as $faq): ?>
        <div class="faq-item" style="border:1px solid var(--color-gray-200);border-radius:8px;margin-bottom:.5rem;overflow:hidden">
            <button type="button" onclick="toggleFaq(this)"
                    style="width:100%;text-align:left;padding:.85rem 1.1rem;background: var(--bg-panel, var(--bg-panel));color:var(--text-default, var(--text-default));border:none;cursor:pointer;font-size:14.5px;font-weight:500;font-family:inherit;display:flex;justify-content:space-between;align-items:center">
                <span class="faq-q"><?= e($faq['question']) ?></span>
                <span style="font-size:1.1rem;color:var(--color-gray-400);flex-shrink:0;margin-left:.5rem">+</span>
            </button>
            <div class="faq-answer" style="display:none;padding:.75rem 1.1rem 1rem;border-top:1px solid var(--color-gray-100);color:var(--color-gray-700);font-size:14px;line-height:1.7">
                <?= $faq['answer'] /* already sanitized on store */ ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($uncategorized)): ?>
    <div class="faq-category" style="margin-bottom:2rem">
        <h2 style="font-size:1.1rem;font-weight:700;border-bottom:2px solid var(--color-gray-200);padding-bottom:.5rem;margin-bottom:1rem">General</h2>
        <?php foreach ($uncategorized as $faq): ?>
        <div class="faq-item" style="border:1px solid var(--color-gray-200);border-radius:8px;margin-bottom:.5rem;overflow:hidden">
            <button type="button" onclick="toggleFaq(this)" style="width:100%;text-align:left;padding:.85rem 1.1rem;background: var(--bg-panel, var(--bg-panel));color:var(--text-default, var(--text-default));border:none;cursor:pointer;font-size:14.5px;font-weight:500;font-family:inherit;display:flex;justify-content:space-between;align-items:center">
                <span class="faq-q"><?= e($faq['question']) ?></span>
                <span style="font-size:1.1rem;color:var(--color-gray-400);flex-shrink:0;margin-left:.5rem">+</span>
            </button>
            <div class="faq-answer" style="display:none;padding:.75rem 1.1rem 1rem;border-top:1px solid var(--color-gray-100);color:var(--color-gray-700);font-size:14px;line-height:1.7">
                <?= $faq['answer'] ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($categories) && empty($uncategorized)): ?>
    <div style="text-align:center;padding:3rem;color:var(--color-gray-500)">
        <div style="font-size:2.5rem;margin-bottom:.75rem">❓</div>
        <p>No FAQ entries have been added yet.</p>
    </div>
    <?php endif; ?>
</div>

<style>
/* Stable anchor for scrollIntoView so the clicked question doesn't
 * dock right under the page's chrome (header / sticky nav). 80px
 * approximates the topbar / page-hero height across surfaces. */
.faq-item { scroll-margin-top: 80px; }
</style>
<script>
function toggleFaq(btn) {
    const item   = btn.closest('.faq-item');
    const answer = btn.nextElementSibling;
    const icon   = btn.querySelector('span:last-child');
    const wasOpen = answer.style.display !== 'none';

    if (wasOpen) {
        // Closing: just collapse. No scroll movement so the user's
        // current viewing position stays put.
        answer.style.display = 'none';
        icon.textContent = '+';
        return;
    }

    // Opening: collapse every OTHER open answer first so the page
    // doesn't accumulate height under multiple expansions. This is
    // what stops the "questions jumping around" feeling on wide
    // screens — at most one answer is open at a time, so the only
    // height change is the diff between the previous open and this
    // one, not the cumulative growth.
    document.querySelectorAll('.faq-answer').forEach(a => {
        if (a !== answer && a.style.display !== 'none') {
            a.style.display = 'none';
            const sib = a.previousElementSibling?.querySelector('span:last-child');
            if (sib) sib.textContent = '+';
        }
    });

    // Now open the clicked one.
    answer.style.display = 'block';
    icon.textContent = '−';

    // Anchor the clicked question to the top of the viewport so the
    // answer fills the space below it instead of pushing whatever was
    // below into new positions. scrollIntoView respects the
    // scroll-margin-top above so the question lands below any chrome.
    item.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

