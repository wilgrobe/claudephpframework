<?php
// modules/seo/Services/SearchSeed.php
namespace Modules\Seo\Services;

/**
 * Search settings chosen at BUILD time (the Builder wizard's step 8 "Get found" card) meeting the ones an owner set
 * on the live site (/admin/settings/search). Pure — no database.
 *
 * A hosted site is re-seeded from the wizard on every republish, so without this a republish would quietly undo the
 * owner's own changes. The rule, key by key: the wizard's value is written when the site has no value yet, still
 * has the value the wizard last wrote, or (the wizard never wrote this key before) still has the module default.
 * A value the owner changed on the site is theirs from then on. What the wizard wrote is remembered in
 * seo.wizard_seeded (JSON key => value).
 *
 * Downloads need none of this: their wizard_seeds.sql runs on the first deploy only.
 */
final class SearchSeed
{
    public const SEEDED = 'seo.wizard_seeded';

    /** The keys the wizard may set (step 8's card). Everything else in seo.* is the owner's alone. */
    public const WIZARD_KEYS = [
        'seo.hide_site', 'seo.ai.search', 'seo.ai.input', 'seo.ai.train', 'seo.main_host',
        'seo.business.type', 'seo.business.description', 'seo.verify.google', 'seo.verify.bing',
    ];

    /**
     * @param array<string,string>      $wizard     the project's seo.* values from the wizard (key => value)
     * @param array<string,string|null> $current    the site's current values for those keys (null = no row)
     * @param array<string,string>      $lastSeeded what the wizard wrote last time (decoded seo.wizard_seeded)
     * @return array{write: array<string,string>, seeded: array<string,string>, kept: list<string>}
     */
    public static function merge(array $wizard, array $current, array $lastSeeded): array
    {
        $wizard = array_intersect_key($wizard, array_flip(self::WIZARD_KEYS));
        return self::mergeKeys($wizard, $current, $lastSeeded, SearchSettings::defaults());
    }

    /**
     * The same rule for the pages the wizard ships: each page's "hidden from search" and canonical override.
     * Remembered in the same seo.wizard_seeded map under "page.<slug>.hidden" / "page.<slug>.canonical".
     *
     * @param array<string, array{hidden:int|bool, canonical:?string}>      $wizardPages  slug => the wizard's choice
     * @param array<string, array{hidden:int|bool, canonical:?string}|null> $sitePages    slug => the site's now (null = no such page)
     * @return array{write: array<string, array{hidden:int, canonical:string}>, seeded: array<string,string>}
     *         write = slug => the full pair to store (only pages with something to change)
     */
    public static function mergePages(array $wizardPages, array $sitePages, array $lastSeeded): array
    {
        $flat = static function (array $pages): array {
            $out = [];
            foreach ($pages as $slug => $p) {
                if (!is_array($p)) { continue; }
                $out["page.$slug.hidden"] = !empty($p['hidden']) ? '1' : '0';
                $out["page.$slug.canonical"] = SearchPages::cleanCanonical((string) ($p['canonical'] ?? ''));
            }
            return $out;
        };
        $wizard = $flat($wizardPages);
        $current = $flat(array_filter($sitePages, 'is_array'));
        $defaults = [];
        foreach (array_keys($wizard) as $k) { $defaults[$k] = str_ends_with($k, '.hidden') ? '0' : ''; }
        $r = self::mergeKeys($wizard, $current, $lastSeeded, $defaults);

        $write = [];
        foreach ($r['write'] as $k => $v) {
            if (!preg_match('/^page\.(.+)\.(hidden|canonical)$/', $k, $m)) { continue; }
            $slug = $m[1];
            if (!array_key_exists($slug, $sitePages) || !is_array($sitePages[$slug])) { continue; }   // no such page on the site
            $write[$slug] ??= [
                'hidden'    => !empty($sitePages[$slug]['hidden']) ? 1 : 0,
                'canonical' => SearchPages::cleanCanonical((string) ($sitePages[$slug]['canonical'] ?? '')),
            ];
            if ($m[2] === 'hidden') { $write[$slug]['hidden'] = $v === '1' ? 1 : 0; } else { $write[$slug]['canonical'] = $v; }
        }
        return ['write' => $write, 'seeded' => $r['seeded']];
    }

    /** @return array{write: array<string,string>, seeded: array<string,string>, kept: list<string>} */
    private static function mergeKeys(array $wizard, array $current, array $lastSeeded, array $defaults): array
    {
        $write = []; $kept = [];
        $seeded = $lastSeeded;
        foreach ($wizard as $key => $value) {
            $value = (string) $value;
            $now = $current[$key] ?? null;
            $ours = $now === null
                || (array_key_exists($key, $lastSeeded) && (string) $now === (string) $lastSeeded[$key])
                || (!array_key_exists($key, $lastSeeded) && (string) $now === (string) ($defaults[$key] ?? ''));
            if ($ours) {
                if ($now === null || (string) $now !== $value) { $write[$key] = $value; }
                $seeded[$key] = $value;
            } else {
                $kept[] = $key;
            }
        }
        return ['write' => $write, 'seeded' => $seeded, 'kept' => $kept];
    }

    /** Pure: seo.wizard_seeded as stored → key => value. */
    public static function decode(?string $json): array
    {
        $d = json_decode((string) $json, true);
        if (!is_array($d)) { return []; }
        $out = [];
        foreach ($d as $k => $v) { if (is_string($k) && is_scalar($v)) { $out[$k] = (string) $v; } }
        return $out;
    }

    /**
     * Pure: step 8's posted card → seo.* key => value, every WIZARD_KEY present. Same validation as the site's own
     * settings page (SearchSettingsController::clean) for the fields both have.
     *
     * @return array<string,string>
     */
    public static function cleanCard(array $post): array
    {
        $full = \Modules\Seo\Controllers\SearchSettingsController::clean($post);
        $out = [];
        foreach (self::WIZARD_KEYS as $k) { $out[$k] = (string) ($full[$k] ?? (SearchSettings::defaults()[$k] ?? '')); }
        return $out;
    }
}
