<?php
// modules/seo/Controllers/SearchController.php
namespace Modules\Seo\Controllers;

use App\Controllers\SitemapController;
use Core\Request;
use Core\Response;
use Modules\Seo\Services\IndexNow;
use Modules\Seo\Services\SearchFiles;
use Modules\Seo\Services\SearchPages;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;

/**
 * The files machines read. Module routes load before routes/web.php and the router takes the FIRST exact match, so
 * these answer /robots.txt and /sitemap.xml in place of the framework's SitemapController — which they still call
 * for the body, so every page and module sitemapUrls() entry it lists keeps appearing.
 *
 *   /robots.txt        the framework's, plus the AI Content-Signal and a few background paths
 *   /sitemap.xml       the framework's, on the main host, without account pages
 *   /llms.txt          a Markdown map of the site for AI assistants
 *   /indexnow-key.txt  IndexNow's proof of ownership
 *
 * With the module switched off (seo.enabled = 0) robots and sitemap are exactly the framework's again.
 */
class SearchController
{
    public function robots(Request $request): Response
    {
        $p = SiteProfile::current();
        $body = SitemapController::robotsTxt($p->base() !== '' ? $p->base() : (function_exists('site_base_url') ? site_base_url() : ''));
        if (SearchSettings::enabled()) {
            $body = SearchFiles::robots($body, SearchSettings::contentSignal(), SearchSettings::on('seo.robots_extras'), $p->isHidden());
        }
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
    }

    public function sitemap(Request $request): Response
    {
        $framework = (new SitemapController())->index($request);
        if (!SearchSettings::enabled()) { return $framework; }
        return new Response(self::sitemapXml($framework->getBody()), 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=600',
            'Vary'          => 'Cookie',
        ]);
    }

    /** The module's sitemap from the framework's body (shared with seo:indexnow). */
    public static function sitemapXml(string $frameworkXml): string
    {
        $p = SiteProfile::current();
        return SearchFiles::sitemap($frameworkXml, $p->base(), $p->ownHosts, SearchSettings::on('seo.noindex_account'), $p->isHidden(), SearchPages::hiddenPaths());
    }

    public function llms(Request $request): Response
    {
        $p = SiteProfile::current();
        if (!SearchSettings::on('seo.llms') || $p->isHidden() || $p->base() === '') { return Response::notFound(); }
        $name = SearchSettings::get('seo.business.name') ?: (string) setting('site_name', '');
        $summary = SearchSettings::get('seo.business.description') ?: (string) setting('site_tagline', '');
        $email = SearchSettings::get('seo.business.email') ?: (string) setting('contact_email', '');
        $body = SearchFiles::llms($p->base(), $name, $summary, SearchSettings::get('seo.llms.intro'), self::publishedPages(), $email,
            self::moduleSections($p->base()), SearchSettings::on('seo.markdown'));
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function indexNowKey(Request $request): Response
    {
        $p = SiteProfile::current();
        if (!SearchSettings::on('seo.indexnow') || $p->isHidden() || $p->mainHost === '') { return Response::notFound(); }
        return new Response(IndexNow::key($p->mainHost), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    /**
     * What modules add to llms.txt: any module provider with a llmsSections(string $base) method (optional — the
     * framework's ModuleProvider doesn't declare it, so it is looked up, not inherited). A module that fails is skipped.
     *
     * @return array<string, list<array{title:string, url:string, about?:string}>>
     */
    private static function moduleSections(string $base): array
    {
        $out = [];
        try {
            $c = \Core\Container\Container::global();
            if (!$c->has(\Core\Module\ModuleRegistry::class)) { return []; }
            foreach ($c->get(\Core\Module\ModuleRegistry::class)->all() as $provider) {
                if (!method_exists($provider, 'llmsSections')) { continue; }
                try {
                    foreach ((array) $provider->llmsSections($base) as $heading => $items) {
                        if (is_string($heading) && is_array($items)) { $out[$heading] = array_merge($out[$heading] ?? [], $items); }
                    }
                } catch (\Throwable) {
                    // one module's list never takes the file down
                }
            }
        } catch (\Throwable) {
            return $out;
        }
        return $out;
    }

    /** @return list<array{title:string, path:string, description:string}> */
    private static function publishedPages(): array
    {
        try {
            $home = (string) setting('guest_home_page_slug', '');
            $rows = \Core\Database\Database::getInstance()->fetchAll(
                "SELECT slug, title, seo_title, seo_description FROM pages WHERE status = 'published' AND is_public = 1 ORDER BY sort_order, id"
            );
            $out = [];
            $hidden = SearchPages::hiddenPaths();
            foreach ($rows as $r) {
                if ($home !== '' && $r['slug'] === $home) { continue; } // that page is the homepage
                if (in_array('/' . $r['slug'], $hidden, true)) { continue; }   // hidden from search by its owner
                $out[] = [
                    'title'       => (string) (($r['seo_title'] ?? '') ?: $r['title']),
                    'path'        => '/' . $r['slug'],
                    'description' => (string) ($r['seo_description'] ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
