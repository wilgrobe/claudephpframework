<?php
// modules/seo/Controllers/SearchSettingsController.php
namespace Modules\Seo\Controllers;

use Core\Auth\Auth;
use Core\Container\Container;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Services\SettingsService;
use Core\Session;
use Modules\Seo\Services\IndexNow;
use Modules\Seo\Services\PageText;
use Modules\Seo\Services\SearchHead;
use Modules\Seo\Services\SearchHealth;
use Modules\Seo\Services\SearchPages;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;
use Modules\Seo\Services\StructuredData;

/**
 * /admin/settings/search — "Search & AI": the one place a site's owner turns each piece of "Get found" off (or back
 * on), sets the AI policy, pastes search-engine verification codes and describes the business for structured data.
 *
 * This is the writer of every seo.* setting the module reads (CLAUDE.md: a setting that is read must have a path
 * that sets it). All writes are site scope, scope_key NULL, through SettingsService.
 */
final class SearchSettingsController
{
    private function guard(): bool
    {
        $auth = Auth::getInstance();
        return !$auth->guest() && ($auth->isSuperAdmin() || $auth->hasRole('admin'));
    }

    public function show(Request $request): Response
    {
        if (!$this->guard()) {
            Session::flash('error', 'Search settings require admin access.');
            return Response::redirect('/admin');
        }
        SearchSettings::flush();
        $values = [];
        foreach (array_keys(SearchSettings::defaults()) as $k) { $values[$k] = SearchSettings::get($k); }
        $report = $_SESSION['seo_health'] ?? null;
        unset($_SESSION['seo_health']);
        return Response::view('seo::admin.settings', [
            'values'   => $values,
            'profile'  => SiteProfile::current(),
            'last'     => (string) setting(IndexNow::LAST, ''),
            'types'    => StructuredData::TYPES,
            'report'   => is_array($report) && time() - (int) ($report['at'] ?? 0) < 900 ? $report : null,
            'pages'    => self::pageRows(),
            'texts'    => self::textRows(),
        ]);
    }

    /** The site's published pages with their search choices; null when the columns are not there yet. */
    private static function pageRows(): ?array
    {
        SearchPages::reset();
        if (!SearchPages::hasColumns()) { return null; }
        try {
            return Database::getInstance()->fetchAll(
                "SELECT id, slug, title, search_hidden, canonical_url FROM pages WHERE status = 'published' ORDER BY sort_order, id"
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** POST /admin/settings/search/pages — each listed page's "Hide from search" and canonical address. */
    public function savePages(Request $request): Response
    {
        if (!$this->guard()) {
            Session::flash('error', 'Search settings require admin access.');
            return Response::redirect('/admin');
        }
        SearchPages::reset();
        if (!SearchPages::hasColumns()) {
            Session::flash('error', 'This site\'s database needs updating first (php artisan migrate).');
            return Response::redirect('/admin/settings/search#pages');
        }
        $db = Database::getInstance();
        $n = 0;
        foreach (self::cleanPages((array) $request->post()) as $id => [$hidden, $canonical]) {
            $db->query('UPDATE pages SET search_hidden = ?, canonical_url = ? WHERE id = ?', [$hidden, $canonical === '' ? null : $canonical, $id]);
            $n++;
        }
        SearchPages::reset();
        Session::flash('success', $n === 1 ? 'Saved 1 page.' : "Saved $n pages.");
        return Response::redirect('/admin/settings/search#pages');
    }

    /**
     * Pure: the posted page table → id => [hidden 0|1, canonical override]. Only pages listed in page_ids[] are
     * written (an unticked box is "shown"); an address that is not a path here or a full http(s) address is dropped.
     *
     * @return array<int, array{0:int, 1:string}>
     */
    public static function cleanPages(array $post): array
    {
        $out = [];
        $hidden = (array) ($post['hidden'] ?? []);
        $canon = (array) ($post['canonical'] ?? []);
        foreach ((array) ($post['page_ids'] ?? []) as $raw) {
            $id = (int) $raw;
            if ($id <= 0) { continue; }
            $out[$id] = [($hidden[$id] ?? '') === '1' ? 1 : 0, SearchPages::cleanCanonical((string) ($canon[$id] ?? ''))];
        }
        return $out;
    }

    /** The stored titles and descriptions, read fresh. */
    private static function textRows(): array
    {
        PageText::reset();
        return PageText::all();
    }

    /** POST /admin/settings/search/text — the title and description of any public address (PageText). */
    public function saveText(Request $request): Response
    {
        if (!$this->guard()) {
            Session::flash('error', 'Search settings require admin access.');
            return Response::redirect('/admin');
        }
        $json = PageText::fromPost((array) $request->post());
        Container::global()->get(SettingsService::class)->set(PageText::KEY, $json, 'site', null);
        SearchSettings::flush();
        PageText::reset();
        $n = count(PageText::parse($json));
        Session::flash('success', $n === 1 ? 'Saved. 1 address has its own title or description.' : "Saved. $n addresses have their own title or description.");
        return Response::redirect('/admin/settings/search#text');
    }

    /** POST /admin/settings/search/check — run the Search health report against the live site; shown once on the page. */
    public function check(Request $request): Response
    {
        if (!$this->guard()) {
            Session::flash('error', 'Search settings require admin access.');
            return Response::redirect('/admin');
        }
        $p = SiteProfile::current();
        if ($p->mainHost === '') {
            Session::flash('error', 'The site has no address to check yet.');
            return Response::redirect('/admin/settings/search');
        }
        $ai = SearchSettings::get('seo.ai.input') !== 'no';
        $_SESSION['seo_health'] = (new SearchHealth())->run($p, $ai) + ['at' => time()];
        return Response::redirect('/admin/settings/search#health');
    }

    public function save(Request $request): Response
    {
        if (!$this->guard()) {
            Session::flash('error', 'Search settings require admin access.');
            return Response::redirect('/admin');
        }
        $s = Container::global()->get(SettingsService::class);
        foreach (self::clean((array) $request->post()) as $key => $value) {
            $s->set($key, $value, 'site', null);
        }
        SearchSettings::flush();
        Session::flash('success', 'Search settings saved.');
        return Response::redirect('/admin/settings/search');
    }

    /**
     * Pure: the posted form → setting key => value. Every switch is written (an unticked box is '0', so turning a
     * piece off sticks); free text is trimmed and length-capped; anything unrecognised is ignored.
     *
     * @return array<string,string>
     */
    public static function clean(array $post): array
    {
        $out = [];
        $field = static fn (string $key) => str_replace('.', '_', $key);
        $out[SearchSettings::ENABLED] = ($post[$field(SearchSettings::ENABLED)] ?? '') === '1' ? '1' : '0';
        foreach (array_keys(SearchSettings::SWITCHES) as $k) {
            $out[$k] = ($post[$field($k)] ?? '') === '1' ? '1' : '0';
        }
        foreach (SearchSettings::AI_KEYS as $k => $default) {
            $v = (string) ($post[$field($k)] ?? $default);
            $out[$k] = $v === 'no' ? 'no' : 'yes';
        }
        foreach (array_keys(SearchSettings::VERIFY) as $k) {
            $out[$k] = SearchHead::verificationValue((string) ($post[$field($k)] ?? ''));
        }
        $out['seo.hide_site'] = ($post['seo_hide_site'] ?? '') === '1' ? '1' : '0';

        $host = strtolower(trim((string) ($post['seo_main_host'] ?? '')));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim((string) preg_replace('#[/?\#].*$#', '', $host), '.');
        $out['seo.main_host'] = ($host === '' || SiteProfile::isPublicHost($host)) ? $host : '';

        $type = (string) ($post['seo_business_type'] ?? 'Organization');
        $out['seo.business.type'] = isset(StructuredData::TYPES[$type]) ? $type : 'Organization';
        $caps = ['name' => 120, 'description' => 500, 'email' => 190, 'phone' => 40, 'street' => 190, 'city' => 120,
                 'region' => 120, 'postal' => 30, 'country' => 60, 'same_as' => 2000];
        foreach ($caps as $f => $max) {
            $v = trim(str_replace(["\r", "\0"], '', (string) ($post['seo_business_' . $f] ?? '')));
            if ($f !== 'same_as') { $v = (string) preg_replace('/\s+/', ' ', $v); }
            $out['seo.business.' . $f] = mb_substr(strip_tags($v), 0, $max);
        }
        $intro = trim(str_replace(["\r", "\0"], '', (string) ($post['seo_llms_intro'] ?? '')));
        $out['seo.llms.intro'] = mb_substr(strip_tags($intro), 0, 2000);
        return $out;
    }
}
