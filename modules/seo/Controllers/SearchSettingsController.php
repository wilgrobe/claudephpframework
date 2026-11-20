<?php
// modules/seo/Controllers/SearchSettingsController.php
namespace Modules\Seo\Controllers;

use Core\Auth\Auth;
use Core\Container\Container;
use Core\Request;
use Core\Response;
use Core\Services\SettingsService;
use Core\Session;
use Modules\Seo\Services\IndexNow;
use Modules\Seo\Services\SearchHead;
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
        return Response::view('seo::admin.settings', [
            'values'   => $values,
            'profile'  => SiteProfile::current(),
            'last'     => (string) setting(IndexNow::LAST, ''),
            'types'    => StructuredData::TYPES,
        ]);
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
