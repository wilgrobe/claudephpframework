<?php
// core/Services/AnalyticsService.php
namespace Core\Services;

/**
 * Renders the configured analytics provider's tracking snippet for
 * injection into the <head>. Providers:
 *
 *   plausible — Plausible Analytics (plausible.io or self-hosted)
 *   ga        — Google Analytics 4 (gtag.js)
 *   umami     — Umami (self-hosted by default)
 *
 * When ANALYTICS_PROVIDER=none (or required vars are missing) the
 * snippet() method returns '' so the layout renders nothing.
 */
class AnalyticsService
{
    /**
     * HTML to paste into the document <head>. Returns '' when disabled
     * or misconfigured.
     */
    public static function snippet(): string
    {
        if (!IntegrationConfig::enabled('analytics')) return '';

        $cfg = IntegrationConfig::config('analytics');
        $provider = (string) ($cfg['driver'] ?? 'none');

        switch ($provider) {
            case 'plausible':
                return self::plausible($cfg);
            case 'ga':
                return self::ga($cfg);
            case 'umami':
                return self::umami($cfg);
        }
        return '';
    }

    private static function plausible(array $cfg): string
    {
        $domain = (string) ($cfg['domain'] ?? '');
        if ($domain === '') return '';
        // host is optional — defaults to plausible.io. Self-hosters set it
        // to their own deployment URL.
        $host = trim((string) ($cfg['host'] ?? '')) ?: 'https://plausible.io';
        $host = rtrim($host, '/');
        return sprintf(
            '<script defer data-domain="%s" src="%s/js/script.js"></script>',
            htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($host,   ENT_QUOTES, 'UTF-8')
        );
    }

    private static function ga(array $cfg): string
    {
        $id = (string) ($cfg['measurement_id'] ?? '');
        if ($id === '') return '';
        $id = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id=$id"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '$id');
</script>
HTML;
    }

    private static function umami(array $cfg): string
    {
        $id   = (string) ($cfg['website_id'] ?? '');
        $host = rtrim((string) ($cfg['host'] ?? ''), '/');
        if ($id === '' || $host === '') return '';
        return sprintf(
            '<script defer src="%s/script.js" data-website-id="%s"></script>',
            htmlspecialchars($host, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($id,   ENT_QUOTES, 'UTF-8')
        );
    }
}
