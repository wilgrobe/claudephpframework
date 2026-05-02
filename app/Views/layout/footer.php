        </main>
    </div><!-- .main -->
</div><!-- .layout -->

<?php include BASE_PATH . '/app/Views/partials/site_footer.php'; ?>

<?php
// GDPR cookie-consent banner — self-renders only when consent is missing
// for the current policy version. Safe to include on every page; the
// partial returns early when the banner shouldn't display.
$__cc = BASE_PATH . '/modules/cookieconsent/Views/banner.php';
if (file_exists($__cc)) include $__cc;
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
