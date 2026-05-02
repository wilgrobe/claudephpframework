<?php
// modules/pages/LinkSources/PagesLinkSource.php
namespace Modules\Pages\LinkSources;

use Core\Database\Database;
use Core\Services\LinkSource;

/**
 * Surfaces every published, public page from the page-builder as a
 * link the menu admin can pick. Drafts and non-public pages stay out
 * of the picker - they're not legitimate menu targets.
 */
class PagesLinkSource implements LinkSource
{
    public function name(): string  { return 'pages'; }
    public function label(): string { return 'Pages'; }

    public function items(): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT slug, title FROM pages
             WHERE status = 'published' AND is_public = 1
             ORDER BY title ASC"
        );
        $out = [];
        foreach ($rows as $r) {
            $slug = (string) ($r['slug'] ?? '');
            $title = (string) ($r['title'] ?? '');
            if ($slug === '' || $title === '') continue;
            $out[] = ['label' => $title, 'url' => '/' . ltrim($slug, '/'), 'icon' => null];
        }
        return $out;
    }
}
