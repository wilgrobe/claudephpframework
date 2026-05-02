<?php $pageTitle = 'Please review our updated terms'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
.policy-accept-overlay {
    position: fixed; inset: 0;
    background: rgba(17,24,39,.65);
    z-index: 9500;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
}
.policy-accept-panel {
    background: var(--bg-card, #fff);
    color: var(--text-default, #111827);
    width: min(720px, 100%);
    max-height: calc(100vh - 2rem);
    border-radius: 10px;
    box-shadow: 0 24px 60px rgba(0,0,0,.30);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.policy-accept-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-default, #e5e7eb);
}
.policy-accept-header h1 { margin: 0 0 .35rem; font-size: 1.25rem; }
.policy-accept-header p  { margin: 0; color: var(--text-muted, #6b7280); font-size: 13.5px; line-height: 1.55; }
.policy-accept-body {
    padding: 1rem 1.5rem;
    overflow-y: auto;
    flex: 1 1 auto;
}
.policy-accept-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-default, #e5e7eb);
    background: var(--bg-page, #f9fafb);
    display: flex; gap: .75rem; justify-content: flex-end; align-items: center;
}
.policy-row {
    padding: .9rem 0;
    border-bottom: 1px solid var(--border-default, #f3f4f6);
}
.policy-row:last-of-type { border-bottom: 0; }
.policy-row__title { font-weight: 600; font-size: 14px; margin-bottom: .25rem; }
.policy-row__meta  { font-size: 12px; color: var(--text-muted, #6b7280); }
.policy-row__summary {
    margin-top: .35rem;
    background: var(--bg-page, #fafafa);
    padding: .5rem .75rem;
    border-left: 3px solid var(--color-primary, #4f46e5);
    border-radius: 4px;
    font-size: 13px;
    color: var(--text-default, #374151);
}
.policy-row__check {
    margin-top: .55rem;
    display: flex; align-items: flex-start; gap: .5rem;
    font-size: 13px;
}
.policy-row__check input { margin-top: .2rem; }
.policy-row__check a { color: var(--color-primary, #4f46e5); text-decoration: none; }
.policy-row__check a:hover { text-decoration: underline; }
</style>

<div class="policy-accept-overlay" role="dialog" aria-modal="true" aria-labelledby="policy-accept-title">
    <div class="policy-accept-panel">
        <header class="policy-accept-header">
            <h1 id="policy-accept-title">Please review our updated terms</h1>
            <p>
                We've made changes to the policies that govern your use of this site.
                Please review each one and confirm before continuing.
            </p>
        </header>

        <form method="POST" action="/policies/accept" id="policy-accept-form" style="display:contents">
            <?= csrf_field() ?>

            <div class="policy-accept-body">
                <?php foreach ($unaccepted as $u):
                    $kindLabel    = htmlspecialchars((string) $u['kind_label'], ENT_QUOTES);
                    $kindSlug     = htmlspecialchars((string) $u['kind_slug'], ENT_QUOTES);
                    $versionLabel = htmlspecialchars((string) $u['version_label'], ENT_QUOTES);
                    $effective    = htmlspecialchars(date('M j, Y', strtotime((string) $u['effective_date'])), ENT_QUOTES);
                ?>
                <div class="policy-row">
                    <div class="policy-row__title"><?= $kindLabel ?> <span style="font-weight:400;color:#6b7280">— v<?= $versionLabel ?></span></div>
                    <div class="policy-row__meta">Effective <?= $effective ?></div>

                    <?php if (!empty($u['summary'])): ?>
                        <div class="policy-row__summary">
                            <strong style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em">What changed:</strong>
                            <div style="margin-top:.15rem"><?= htmlspecialchars((string) $u['summary'], ENT_QUOTES) ?></div>
                        </div>
                    <?php endif; ?>

                    <label class="policy-row__check">
                        <input type="checkbox" name="accept_versions[]" value="<?= (int) $u['version_id'] ?>" required>
                        <span>
                            I have read and agree to the
                            <a href="/policies/<?= $kindSlug ?>" target="_blank" rel="noopener">
                                <?= $kindLabel ?>
                            </a>.
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <footer class="policy-accept-footer">
                <a href="/logout" style="font-size:12.5px;color:#6b7280;text-decoration:none;margin-right:auto">
                    Sign out instead
                </a>
                <button type="submit" class="btn btn-primary" style="font-size:13.5px">
                    I agree — continue
                </button>
            </footer>
        </form>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
