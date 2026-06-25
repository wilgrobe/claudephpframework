<?php
// core/Theme/BrandingProvider.php
namespace Core\Theme;

/**
 * Extension point for per-site branding emitted into <head>: favicon, custom
 * web-font, custom CSS, logo styling — anything a site owner sets that has to
 * reach the document head.
 *
 * The framework ships {@see DefaultBrandingProvider}, which reads neutral
 * `branding.*` settings. A host with a richer branding model
 * binds its own implementation in config/services.php. Views emit branding via
 * the `branding_head()` helper, which resolves whatever is bound — so no view
 * names a host-specific class.
 */
interface BrandingProvider
{
    /**
     * Return ready-to-emit HTML for the document <head> (favicon <link>,
     * <style>, web-font <link>, …). Return '' when there's nothing to add.
     */
    public function renderHead(): string;
}
