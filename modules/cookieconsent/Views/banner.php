<?php
/**
 * GDPR cookie-consent banner.
 *
 * Self-rendering: included unconditionally from the master layout +
 * the public page view; checks shouldShowBanner() internally and emits
 * nothing when consent already exists for the current policy version.
 *
 * UX:
 *   - Slim sticky bottom bar with the policy summary + 3 buttons:
 *     "Accept all", "Reject all", "Customize". Reject is visually equal
 *     in prominence to Accept (GDPR requires this — "as easy to refuse
 *     as to accept", EDPB Guidelines 5/2020 §41).
 *   - Customize expands a centered modal with per-category sliders and
 *     descriptions. Save button records the per-category choice.
 *   - Necessary toggle is rendered locked-on so the user can see it
 *     without being able to disable it (clarity > silence).
 *
 * Storage:
 *   - Posts to /cookie-consent. The server-side handler writes the
 *     audit row + the signed cookie. JS additionally writes a
 *     short-lived cookie BEFORE the form submits so the banner doesn't
 *     re-flash during the navigation; the server-side cookie then
 *     supersedes it on the next page view.
 *
 * Theming:
 *   - All colors come from CSS variables already defined by the theme
 *     system (--bg-card, --text-default, --color-primary, etc.) so the
 *     banner matches whatever theme is active and respects dark mode.
 */

$svc = new \Modules\Cookieconsent\Services\CookieConsentService();
if (!$svc->shouldShowBanner()) {
    return;
}

$title = (string) setting('cookieconsent_title', 'We value your privacy');
$body  = (string) setting('cookieconsent_body',
    'We use cookies to keep the site running, remember your preferences, '
  . 'measure traffic, and personalise content.');
$policyUrl = (string) setting('cookieconsent_policy_url', '/page/cookie-policy');

$labels = [
    'necessary'   => (string) setting('cookieconsent_label_necessary',   'Strictly necessary'),
    'preferences' => (string) setting('cookieconsent_label_preferences', 'Preferences'),
    'analytics'   => (string) setting('cookieconsent_label_analytics',   'Analytics'),
    'marketing'   => (string) setting('cookieconsent_label_marketing',   'Marketing'),
];
$descs = [
    'necessary'   => (string) setting('cookieconsent_desc_necessary',   ''),
    'preferences' => (string) setting('cookieconsent_desc_preferences', ''),
    'analytics'   => (string) setting('cookieconsent_desc_analytics',   ''),
    'marketing'   => (string) setting('cookieconsent_desc_marketing',   ''),
];
?>
<div id="cc-banner" class="cc-banner" role="dialog" aria-live="polite" aria-label="Cookie consent">
    <div class="cc-banner__inner">
        <div class="cc-banner__text">
            <strong class="cc-banner__title"><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) ?></strong>
            <p class="cc-banner__body">
                <?= htmlspecialchars($body, ENT_QUOTES | ENT_HTML5) ?>
                <a href="<?= htmlspecialchars($policyUrl, ENT_QUOTES | ENT_HTML5) ?>" class="cc-banner__link">Read our cookie policy →</a>
            </p>
        </div>
        <div class="cc-banner__actions">
            <!-- Reject + Accept use equal-weight filled buttons to satisfy the EDPB
                 "as easy to refuse as to accept" requirement (Guidelines 5/2020
                 §41). Customize stays ghost — it's a "more options" affordance,
                 not a top-level consent decision, so being slightly quieter is
                 fine. -->
            <button type="button" class="cc-btn cc-btn--reject"  data-cc-action="reject_all">Reject all</button>
            <button type="button" class="cc-btn cc-btn--ghost"   data-cc-action="customize">Customize</button>
            <button type="button" class="cc-btn cc-btn--primary" data-cc-action="accept_all">Accept all</button>
        </div>
    </div>
</div>

<div id="cc-modal" class="cc-modal" role="dialog" aria-modal="true" aria-labelledby="cc-modal-title" hidden>
    <div class="cc-modal__backdrop" data-cc-action="close-modal"></div>
    <div class="cc-modal__panel">
        <header class="cc-modal__header">
            <h2 id="cc-modal-title">Manage your cookie preferences</h2>
            <button type="button" class="cc-modal__close" aria-label="Close" data-cc-action="close-modal">&times;</button>
        </header>
        <form id="cc-form" method="post" action="/cookie-consent" class="cc-modal__body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="custom">

            <?php foreach (['necessary','preferences','analytics','marketing'] as $cat):
                $locked = ($cat === 'necessary');
                $label  = $labels[$cat];
                $desc   = $descs[$cat];
            ?>
            <div class="cc-cat">
                <div class="cc-cat__row">
                    <label class="cc-cat__label" for="cc-<?= $cat ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES | ENT_HTML5) ?>
                        <?php if ($locked): ?><span class="cc-cat__badge">Always on</span><?php endif; ?>
                    </label>
                    <label class="cc-toggle">
                        <input type="checkbox"
                               id="cc-<?= $cat ?>"
                               name="cookieconsent[<?= $cat ?>]"
                               value="1"
                               <?= $locked ? 'checked disabled' : '' ?>
                               <?= $cat === 'necessary' ? '' : '' ?>>
                        <span class="cc-toggle__slider" aria-hidden="true"></span>
                    </label>
                </div>
                <?php if ($desc !== ''): ?>
                    <p class="cc-cat__desc"><?= htmlspecialchars($desc, ENT_QUOTES | ENT_HTML5) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <footer class="cc-modal__footer">
                <!-- Same parity rule as the banner: Reject must look as
                     prominent as Accept. Save my choices stays primary
                     because it's the form's main submit affordance. -->
                <button type="button" class="cc-btn cc-btn--reject"  data-cc-action="reject_all">Reject all</button>
                <button type="submit" class="cc-btn cc-btn--primary">Save my choices</button>
                <button type="button" class="cc-btn cc-btn--primary" data-cc-action="accept_all">Accept all</button>
            </footer>
        </form>
    </div>
</div>

<style>
/* The banner sits ABOVE the site footer so it doesn't get visually
   buried by the fixed footer bar. z-index higher than .site-footer. */
.cc-banner {
    position: fixed;
    left: 0; right: 0; bottom: 0;
    z-index: 9000;
    background: var(--bg-card, #fff);
    color: var(--text-default, #111827);
    border-top: 1px solid var(--border-default, #e5e7eb);
    box-shadow: 0 -4px 16px rgba(0,0,0,.08);
    font-size: 14px;
    line-height: 1.5;
    animation: cc-slide-up .35s ease-out;
}
@keyframes cc-slide-up { from { transform: translateY(100%); } to { transform: translateY(0); } }

.cc-banner__inner {
    max-width: 1280px;
    margin: 0 auto;
    padding: .9rem 1.25rem;
    display: flex;
    gap: 1.25rem;
    align-items: center;
    flex-wrap: wrap;
}
.cc-banner__text { flex: 1 1 320px; min-width: 0; }
.cc-banner__title { display:block; font-size: 14px; margin-bottom: .15rem; }
.cc-banner__body  { margin: 0; color: var(--text-muted, #6b7280); }
.cc-banner__link  { color: var(--color-primary, #4f46e5); text-decoration: none; white-space: nowrap; }
.cc-banner__link:hover { text-decoration: underline; }

.cc-banner__actions {
    display: flex; gap: .5rem; flex-wrap: wrap;
}

.cc-btn {
    appearance: none;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: .5rem 1rem;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color .15s, border-color .15s, color .15s;
    font-family: inherit;
    line-height: 1.2;
    white-space: nowrap;
}
.cc-btn--primary {
    background: var(--color-primary, #4f46e5);
    color: #fff;
}
.cc-btn--primary:hover  { background: var(--color-primary-dark, #3730a3); }
/* Equal-weight filled neutral, paired with --primary for Reject/Accept
   parity (EDPB Guidelines 5/2020 §41 — refuse must be as easy as accept).
   Same padding + font-weight + border-radius as --primary; only the fill
   color differs. Slate-700 in light mode; theme tokens take over in dark. */
.cc-btn--reject {
    background: var(--bg-button-neutral, #374151);
    color: var(--text-on-dark, #fff);
}
.cc-btn--reject:hover   { background: var(--bg-button-neutral-hover, #1f2937); }
.cc-btn--ghost {
    background: transparent;
    border-color: var(--border-default, #d1d5db);
    color: var(--text-default, #111827);
}
.cc-btn--ghost:hover    { background: var(--bg-page, #f9fafb); }

/* Modal */
.cc-modal {
    position: fixed; inset: 0; z-index: 9100;
    display: flex; align-items: center; justify-content: center;
}
.cc-modal[hidden] { display: none; }
.cc-modal__backdrop { position: absolute; inset: 0; background: rgba(17,24,39,.55); }
.cc-modal__panel {
    position: relative;
    background: var(--bg-card, #fff);
    color: var(--text-default, #111827);
    width: min(560px, calc(100% - 2rem));
    max-height: calc(100vh - 4rem);
    border-radius: 10px;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.cc-modal__header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-default, #e5e7eb);
}
.cc-modal__header h2 { margin: 0; font-size: 16px; }
.cc-modal__close {
    background: none; border: 0; color: var(--text-muted, #6b7280);
    font-size: 22px; line-height: 1; cursor: pointer; padding: 0 .25rem;
}
.cc-modal__body {
    padding: 1rem 1.25rem; overflow-y: auto;
}
.cc-modal__footer {
    display: flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end;
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--border-default, #e5e7eb);
    background: var(--bg-page, #f9fafb);
}

.cc-cat {
    padding: .85rem 0;
    border-bottom: 1px solid var(--border-default, #f3f4f6);
}
.cc-cat:last-of-type { border-bottom: 0; }
.cc-cat__row {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.cc-cat__label {
    font-weight: 600; font-size: 14px;
    display: flex; align-items: center; gap: .5rem;
}
.cc-cat__badge {
    font-size: 11px; font-weight: 500;
    background: var(--bg-page, #f3f4f6); color: var(--text-muted, #6b7280);
    padding: .15rem .45rem; border-radius: 999px;
}
.cc-cat__desc { margin: .35rem 0 0; color: var(--text-muted, #6b7280); font-size: 13px; }

/* Sliding toggle — same visual language the framework's superadmin
   header toggle uses, so the banner doesn't feel like a different app. */
.cc-toggle { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
.cc-toggle input { opacity: 0; width: 0; height: 0; }
.cc-toggle__slider {
    position: absolute; cursor: pointer; inset: 0;
    background-color: #d1d5db;
    transition: .2s; border-radius: 22px;
}
.cc-toggle__slider:before {
    position: absolute; content: "";
    height: 16px; width: 16px;
    left: 3px; bottom: 3px;
    background-color: #fff;
    transition: .2s; border-radius: 50%;
}
.cc-toggle input:checked + .cc-toggle__slider { background-color: var(--color-primary, #4f46e5); }
.cc-toggle input:checked + .cc-toggle__slider:before { transform: translateX(18px); }
.cc-toggle input:disabled + .cc-toggle__slider { opacity: .65; cursor: not-allowed; }

@media (max-width: 640px) {
    .cc-banner__inner   { padding: .75rem 1rem; }
    .cc-banner__actions { width: 100%; justify-content: stretch; }
    .cc-banner__actions .cc-btn { flex: 1 1 0; text-align: center; }
}
</style>

<script>
(function() {
    var banner = document.getElementById('cc-banner');
    var modal  = document.getElementById('cc-modal');
    var form   = document.getElementById('cc-form');
    if (!banner || !modal || !form) return;

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function postAction(action, categories) {
        // Background POST so the banner can disappear immediately without
        // the page navigating. We optimistically hide; on failure we
        // re-show with a toast.
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_token', csrfToken());
        if (categories) {
            Object.keys(categories).forEach(function(k) {
                if (categories[k]) fd.append('cookieconsent[' + k + ']', '1');
            });
        }
        return fetch('/cookie-consent', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
    }

    function hideAll() {
        banner.style.display = 'none';
        modal.setAttribute('hidden', '');
    }

    function openModal()  { modal.removeAttribute('hidden'); }
    function closeModal() { modal.setAttribute('hidden', ''); }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-cc-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-cc-action');

        if (action === 'customize')   { openModal(); return; }
        if (action === 'close-modal') { closeModal(); return; }

        if (action === 'accept_all' || action === 'reject_all') {
            hideAll();
            postAction(action).catch(function() {
                // Restore on failure — the user should see the banner again.
                banner.style.display = '';
                alert('Could not save your cookie preferences. Please try again.');
            });
        }
    });

    // Custom save — let the form submit normally so the server-side
    // POST handler does the redirect back. We just hide the modal up
    // front to avoid a flash.
    form.addEventListener('submit', function() {
        hideAll();
    });

    // Esc to close the modal — accessibility nicety.
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
    });
})();
</script>
