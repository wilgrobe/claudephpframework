<?php
// core/SEO/SeoManager.php
namespace Core\SEO;

use Core\Database\Database;

/**
 * SeoManager — handles persistent slug→URL mapping and SEO metadata.
 *
 * Slugs are permanent: once assigned, they resolve forever.
 * Changing a page's path auto-creates a 301 redirect from the old slug.
 */
class SeoManager
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Resolve an incoming request path to its canonical destination.
     * Returns ['path' => '...', 'redirect' => bool, 'redirect_to' => '...'].
     */
    public function resolve(string $requestPath): ?array
    {
        $link = $this->db->fetchOne(
            "SELECT * FROM seo_links WHERE slug = ? AND is_active = 1",
            [ltrim($requestPath, '/')]
        );
        if (!$link) return null;

        if ($link['redirect_to']) {
            return ['path' => $link['path'], 'redirect' => true, 'redirect_to' => $link['redirect_to']];
        }
        return ['path' => $link['path'], 'redirect' => false];
    }

    /**
     * Register or update a persistent slug.
     */
    public function register(string $slug, string $path, ?string $targetType = null, ?int $targetId = null): void
    {
        $slug = ltrim($slug, '/');
        $existing = $this->db->fetchOne("SELECT id FROM seo_links WHERE slug = ?", [$slug]);

        if ($existing) {
            $this->db->update('seo_links', [
                'path'        => $path,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'redirect_to' => null,
            ], 'slug = ?', [$slug]);
        } else {
            $this->db->insert('seo_links', [
                'slug'        => $slug,
                'path'        => $path,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'is_active'   => 1,
            ]);
        }
    }

    /**
     * When a path changes, add a 301 redirect from the old slug.
     */
    public function redirect(string $oldSlug, string $newPath): void
    {
        $oldSlug = ltrim($oldSlug, '/');
        $existing = $this->db->fetchOne("SELECT id FROM seo_links WHERE slug = ?", [$oldSlug]);
        if ($existing) {
            $this->db->update('seo_links',
                ['redirect_to' => $newPath],
                'slug = ?', [$oldSlug]
            );
        } else {
            $this->db->insert('seo_links', [
                'slug'        => $oldSlug,
                'redirect_to' => $newPath,
                'path'        => $newPath,
                'is_active'   => 1,
            ]);
        }
    }

    /**
     * Generate meta tags HTML for a view.
     */
    public static function metaTags(array $meta): string
    {
        $html = '';
        if (!empty($meta['title'])) {
            $t = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
            $html .= "<title>$t</title>\n";
            $html .= "<meta property=\"og:title\" content=\"$t\">\n";
        }
        if (!empty($meta['description'])) {
            $d = htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8');
            $html .= "<meta name=\"description\" content=\"$d\">\n";
            $html .= "<meta property=\"og:description\" content=\"$d\">\n";
        }
        if (!empty($meta['keywords'])) {
            $k = htmlspecialchars($meta['keywords'], ENT_QUOTES, 'UTF-8');
            $html .= "<meta name=\"keywords\" content=\"$k\">\n";
        }
        if (!empty($meta['canonical'])) {
            $c = htmlspecialchars($meta['canonical'], ENT_QUOTES, 'UTF-8');
            $html .= "<link rel=\"canonical\" href=\"$c\">\n";
        }
        // og:image - drawn separately because it's the only og: tag whose
        // value is a URL not a string. Same htmlspecialchars treatment so
        // an admin-pasted URL with quotes can't break out of the attribute.
        if (!empty($meta['image'])) {
            $img = htmlspecialchars($meta['image'], ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:image\" content=\"$img\">\n";
            $html .= "<meta name=\"twitter:image\" content=\"$img\">\n";
            $html .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        }
        return $html;
    }

    /**
     * The social-share image for a page, as an absolute URL, or null.
     *
     * The page's own `og_image_url` wins; otherwise the guest home page's, so a
     * site that set one card gets it on every page instead of a blank preview.
     * `pages.og_image_url` was written (Builder step 7 "OG card", the seeders)
     * long before anything read it, so every share showed no picture.
     */
    public static function pageImage(?array $page = null): ?string
    {
        $url = trim((string) ($page['og_image_url'] ?? ''));
        if ($url === '') {
            try {
                $home = function_exists('setting') ? (string) setting('guest_home_page_slug', '') : '';
                if ($home !== '') {
                    $url = trim((string) Database::getInstance()->fetchColumn(
                        "SELECT og_image_url FROM pages WHERE slug = ? AND status = 'published' LIMIT 1",
                        [$home]
                    ));
                }
            } catch (\Throwable $e) {
                return null; // a missing column or table must never break a page render
            }
        }
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        // Crawlers ignore relative og:image values.
        $base = function_exists('config') ? rtrim((string) config('app.url', ''), '/') : '';
        return $base === '' ? null : $base . '/' . ltrim($url, '/');
    }

    /**
     * Generate a URL-safe slug from a title.
     */
    public static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s\-]+/', '-', $slug);
        return trim($slug, '-');
    }
}
