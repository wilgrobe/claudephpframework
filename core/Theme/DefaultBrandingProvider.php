<?php
// core/Theme/DefaultBrandingProvider.php
namespace Core\Theme;

use Core\Services\FileUploadService;

/**
 * Framework default {@see BrandingProvider}. Emits favicon, an optional custom
 * web-font, and custom CSS from neutral `branding.*` settings, so a standalone
 * install gets basic per-site branding with no host layer.
 *
 * Settings read (all optional, all site-scope):
 *   branding.favicon_url        — stored file path → <link rel="icon">
 *   branding.custom_font_url    — web-font stylesheet URL → <link>
 *   branding.custom_font_family — applied as the body font-family
 *   branding.custom_css         — raw CSS appended last (author's own override)
 */
final class DefaultBrandingProvider implements BrandingProvider
{
    public function renderHead(): string
    {
        $out = '';

        $favicon = trim((string) setting('branding.favicon_url', ''));
        if ($favicon !== '') {
            $url = (new FileUploadService())->url($favicon);
            $out .= '<link rel="icon" href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
        }

        $fontUrl    = trim((string) setting('branding.custom_font_url', ''));
        $fontFamily = trim((string) setting('branding.custom_font_family', ''));
        if ($fontUrl !== '') {
            $out .= '<link rel="stylesheet" href="' . htmlspecialchars($fontUrl, ENT_QUOTES) . '">';
        }
        if ($fontFamily !== '') {
            $out .= '<style>body{font-family:' . htmlspecialchars($fontFamily, ENT_QUOTES) . ',system-ui,sans-serif;}</style>';
        }

        $customCss = trim((string) setting('branding.custom_css', ''));
        if ($customCss !== '') {
            // Author-supplied site CSS. It must not be able to close the wrapper.
            // #466: str_ireplace('</style>') was one pass over one spelling —
            // "</style >", "</style/>" and "</sty</style>le>" all got through.
            // Any "</style" (any case, any spacing) becomes "<\/style", which
            // the HTML parser does not read as an end tag.
            $customCss = (string) preg_replace('#<\s*/\s*(style)#i', '<\\\\/$1', $customCss);
            $out .= '<style>' . $customCss . '</style>';
        }

        return $out;
    }
}
