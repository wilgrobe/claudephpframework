<?php
// modules/seo/Console/Commands/IndexNowCommand.php
namespace Modules\Seo\Console\Commands;

use Core\Console\Command;
use Modules\Seo\Controllers\SearchController;
use Modules\Seo\Services\IndexNow;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;

/**
 * seo:indexnow — daily: tell Bing and the other IndexNow engines about every page in the sitemap that is new or has
 * changed since it was last sent. Google is not in IndexNow; it reads the sitemap.
 *
 *   --all      send every address in the sitemap (the first run, or after a big change)
 *   --dry-run  list what would be sent; send nothing
 *
 * Sends nothing from a local/test host, from a hidden site (demo, preview), or when the site turned IndexNow off.
 * On the hosted Builder it runs once per tenant through tenants:schedule-run (the migration schedules it).
 */
final class IndexNowCommand extends Command
{
    public function name(): string        { return 'seo:indexnow'; }
    public function description(): string { return 'Tell search engines (Bing and other IndexNow engines) which pages are new or changed'; }

    public function handle(array $argv): int
    {
        // One process may run this for several tenants in a row.
        SiteProfile::reset();
        SearchSettings::flush();
        $p = SiteProfile::current();
        if ($p->apex) { $this->line('The Builder apex is not a customer site; nothing sent.'); return 0; }
        if (!SearchSettings::on('seo.indexnow')) { $this->line('IndexNow is turned off for this site; nothing sent.'); return 0; }
        if ($p->isHidden()) { $this->line('This site is hidden from search (' . $p->hidden . '); nothing sent.'); return 0; }

        $framework = (new \App\Controllers\SitemapController())->index(\Core\Request::capture())->getBody();
        $r = (new IndexNow())->run($p->base(), SearchController::sitemapXml($framework), in_array('--all', $argv, true), in_array('--dry-run', $argv, true));
        switch ($r['status']) {
            case 'skipped':
                $this->line('Not a public https site (' . ($p->base() ?: 'no address') . '); nothing sent.');
                return 0;
            case 'nothing':
                $this->line('No new or changed pages.');
                return 0;
            case 'dry-run':
                $this->line('Would send ' . $r['count'] . ':');
                foreach ($r['urls'] as $u) { $this->line('  ' . $u); }
                return 0;
            case 'sent':
                $this->line(sprintf('Sent %d page%s (HTTP %d).', $r['count'], $r['count'] === 1 ? '' : 's', $r['http']));
                return 0;
            default:
                $this->error(sprintf('IndexNow refused %d page%s (HTTP %d); they will be tried again next run.', $r['count'], $r['count'] === 1 ? '' : 's', $r['http']));
                return 1;
        }
    }
}
