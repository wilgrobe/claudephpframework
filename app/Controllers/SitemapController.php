<?php
// app/Controllers/SitemapController.php
namespace App\Controllers;

use Core\Database\Database;
use Core\Request;
use Core\Response;

/**
 * SitemapController — generates an XML sitemap for search engines.
 * Only includes publicly accessible pages; excludes auth/admin routes.
 */
class SitemapController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        $baseUrl = rtrim(config('app.url', ''), '/');
        $urls    = [];

        // Load pages up front so we can use the guest home page's updated_at
        // to enrich the "/" entry with a real lastmod.
        $homeSlug = (string) setting('guest_home_page_slug', '');
        $pages    = $this->db->fetchAll(
            "SELECT slug, updated_at FROM pages WHERE status = 'published' AND is_public = 1 ORDER BY sort_order"
        );

        $homeLastmod = null;
        if ($homeSlug !== '') {
            foreach ($pages as $p) {
                if ($p['slug'] === $homeSlug) {
                    $homeLastmod = date('Y-m-d', strtotime($p['updated_at']));
                    break;
                }
            }
        }

        // Static always-public routes. "/" gets a lastmod when a home page
        // is configured so crawlers can see when its content last changed.
        $static = [
            ['/',         $homeLastmod],
            ['/faq',      null],
            ['/login',    null],
            ['/register', null],
        ];
        foreach ($static as [$path, $lastmod]) {
            $entry = ['loc' => $baseUrl . $path, 'priority' => '0.8', 'changefreq' => 'weekly'];
            if ($lastmod) $entry['lastmod'] = $lastmod;
            $urls[] = $entry;
        }

        // Published public pages. Two things to be careful of:
        //   1. Pages are now served at /{slug} (not /page/{slug}), so that's
        //      the canonical URL and what this sitemap must advertise.
        //   2. If a page is configured as the guest home, it's also reachable
        //      at /. The view emits canonical=/ for that page, so to avoid
        //      handing search engines two URLs for identical content we skip
        //      that page's /{slug} entry — / is already in the static list.
        foreach ($pages as $page) {
            if ($homeSlug !== '' && $page['slug'] === $homeSlug) {
                continue; // represented as "/" above
            }
            $urls[] = [
                'loc'        => $baseUrl . '/' . $page['slug'],
                'lastmod'    => date('Y-m-d', strtotime($page['updated_at'])),
                'priority'   => '0.6',
                'changefreq' => 'monthly',
            ];
        }

        // Public groups
        $groups = $this->db->fetchAll(
            "SELECT slug, updated_at FROM `groups` WHERE is_public = 1 ORDER BY `name`"
        );
        foreach ($groups as $group) {
            $urls[] = [
                'loc'        => $baseUrl . '/groups/' . $group['slug'],
                'lastmod'    => date('Y-m-d', strtotime($group['updated_at'])),
                'priority'   => '0.5',
                'changefreq' => 'weekly',
            ];
        }

        // Build XML
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($urls as $url) {
            $xml .= '  <url>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>' . PHP_EOL;
            if (!empty($url['lastmod']))   $xml .= '    <lastmod>'   . $url['lastmod']   . '</lastmod>'   . PHP_EOL;
            if (!empty($url['changefreq']))$xml .= '    <changefreq>'.$url['changefreq'] . '</changefreq>'. PHP_EOL;
            if (!empty($url['priority']))  $xml .= '    <priority>'  . $url['priority']  . '</priority>'  . PHP_EOL;
            $xml .= '  </url>' . PHP_EOL;
        }

        $xml .= '</urlset>';

        return new Response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
