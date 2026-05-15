        </main>
    </div><!-- .main -->
</div><!-- .layout -->

<?php include BASE_PATH . '/app/Views/partials/site_footer.php'; ?>

<?php
// GDPR cookie-consent banner — self-renders only when consent is missing
// for the current policy version. Safe to include on every page; the
// partial returns early when the banner shouldn't display.
//
// Gated on cookieconsent.banner-ui submodule (default-on without
// project_submodules; deliberately off for sites running an external
// CMP that handles the banner UI themselves).
$__cc = BASE_PATH . '/modules/cookieconsent/Views/banner.php';
if (file_exists($__cc)
    && (!class_exists(\Core\Module\SubmoduleRegistry::class)
        || \Core\Module\SubmoduleRegistry::featureEnabled('cookieconsent', 'banner-ui'))) {
    include $__cc;
}
?>

<?php
// ── Real-time broadcasting (WebSocket subscriber) ────────────────
// Emits window.BROADCAST_CONFIG + loads the matching SDK + broadcast.js
// only when the current page meets all three conditions:
//   1. a user is signed in (broadcasts target user.{id} by default)
//   2. broadcasting is configured (BROADCAST_PROVIDER != none)
//   3. the broadcast module returned a non-empty client config
//
// Public CDN URLs for the SDKs are pinned to major versions; bump the
// pin (and re-verify CSP) when the framework upgrades broadcast deps.
$__bcastAuth = \Core\Auth\Auth::getInstance();
if ($__bcastAuth->check()) {
    $__bcastCfg = (new \Core\Services\BroadcastService())->clientConfig();
    if (!empty($__bcastCfg)) {
        $__bcastCfg['user_id'] = (int) $__bcastAuth->id();
        ?>
        <script>
        window.BROADCAST_CONFIG = <?= json_encode($__bcastCfg, JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <?php if ($__bcastCfg['provider'] === 'pusher' || $__bcastCfg['provider'] === 'soketi'): ?>
        <script src="https://js.pusher.com/8.0/pusher.min.js" defer></script>
        <?php elseif ($__bcastCfg['provider'] === 'ably'): ?>
        <script src="https://cdn.ably.com/lib/ably.min-1.js" defer></script>
        <?php endif; ?>
        <script src="<?= e(asset('/assets/js/broadcast.js')) ?>" defer></script>
        <?php
    }
}
?>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
<script>
// Close dropdowns when clicking outside. Two distinct dropdown patterns
// exist — the user menu (click toggles .dropdown-menu.open) and the
// topbar nav dropdowns (click toggles .topbar-dd.open). We close whichever
// wasn't clicked into.
document.addEventListener('click', function(e) {
    if (!e.target.closest('.user-menu')) {
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
    if (!e.target.closest('.topbar-dd')) {
        document.querySelectorAll('.topbar-dd.open').forEach(d => d.classList.remove('open'));
    }
});

// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert').forEach(a => {
    setTimeout(() => { a.style.opacity = '0'; a.style.transition = 'opacity .5s'; setTimeout(() => a.remove(), 500); }, 5000);
});

// Simple confirm dialogs for delete forms
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', e => {
        if (!confirm(form.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });
});

// AJAX superadmin toggle — uses csrfPost from app.js
document.querySelectorAll('.ajax-toggle').forEach(btn => {
    btn.addEventListener('change', async function() {
        try {
            await csrfPost(this.closest('form').action, { enable: this.checked ? 1 : 0 });
        } catch (e) {
            this.checked = !this.checked;
            alert('Action failed.');
        }
    });
});
</script>
</body>
</html>
