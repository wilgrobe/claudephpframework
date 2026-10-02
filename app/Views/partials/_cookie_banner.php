<?php
/*
 * GDPR cookie-consent banner — the one include every page shell uses
 * (layout/footer.php, public/page.php, and the standalone auth pages).
 *
 * The partial self-renders only when consent is missing for the current
 * policy version, carries its own styles, script and CSRF field, and is a
 * no-op when the cookieconsent module is absent. Gated on the
 * cookieconsent.banner-ui submodule (default-on without project_submodules;
 * deliberately off for sites running an external CMP that draws its own UI).
 *
 * The auth pages (login, register, password reset, 2FA) are full HTML
 * documents that never include the layout footer, so until 2026-10-02 a
 * site whose front door is /login showed no banner until after sign-in.
 */
$__cc = BASE_PATH . '/modules/cookieconsent/Views/banner.php';
if (file_exists($__cc)
    && (!class_exists(\Core\Module\SubmoduleRegistry::class)
        || \Core\Module\SubmoduleRegistry::featureEnabled('cookieconsent', 'banner-ui'))) {
    include $__cc;
}
