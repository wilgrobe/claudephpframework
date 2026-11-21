<?php
// modules/seo/Services/SiteProfile.php
namespace Modules\Seo\Services;

/**
 * Who this site is, as far as search engines are concerned: its ONE main address (host + scheme), the other
 * addresses that serve the same site, and whether the whole site should be hidden from search.
 *
 * Main host, first that applies:
 *   1. the site's own setting seo.main_host;
 *   2. on the hosted Builder: the tenant's verified custom domain, else its builder subdomain;
 *   3. in a generated kit: APP_URL's host (managed servers set it to the real domain — ServerSetup::nginxSite serves
 *      www. as a copy of the same site, which is what the 301 fixes);
 *   4. the host of this request.
 *
 * Hidden sites (hosted Builder only; Will, 17 Nov 2026): a demo is hidden unless an operator turned search on for it
 * (tenants.demo_search_enabled, `php artisan demo:search`), and a free proofing/preview site is always hidden — it
 * is a preview, not the customer's live site. A site's owner can also hide their own site (seo.hide_site).
 *
 * build() is pure; current() gathers the inputs for this request (cached) and never throws.
 */
final class SiteProfile
{
    /** Hosts that are never a public site: no redirects, no IndexNow, no forced https. */
    private const LOCAL_SUFFIXES = ['.test', '.local', '.localhost', '.example', '.invalid', '.internal', '.lan'];

    private static ?self $current = null;

    /**
     * @param list<string> $ownHosts every host name that serves (or used to stand for) this site
     * @param list<string> $redirectHosts hosts whose public pages 301 to the main host
     */
    public function __construct(
        public readonly string $requestHost,
        public readonly bool   $requestHttps,
        public readonly string $mainHost,
        public readonly bool   $https,
        public readonly array  $ownHosts,
        public readonly array  $redirectHosts,
        public readonly string $hidden,      // '' | 'staging' | 'demo' | 'preview' | 'owner'
        public readonly bool   $apex,        // the Builder's own apex host: this module stays out of the way
        public readonly bool   $httpsKnown,  // the main host is known to serve https (safe to redirect http → https)
    ) {}

    public function base(): string
    {
        return $this->mainHost === '' ? '' : ($this->https ? 'https://' : 'http://') . $this->mainHost;
    }

    public function isHidden(): bool { return $this->hidden !== ''; }

    public static function isPublicHost(string $host): bool
    {
        $host = strtolower($host);
        if ($host === '' || !str_contains($host, '.') || $host === 'localhost') { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) { return false; }
        foreach (self::LOCAL_SUFFIXES as $s) { if (str_ends_with($host, $s)) { return false; } }
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $host);
    }

    public static function requestIsHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') { return true; }
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') { return true; }
        if (str_contains((string) ($server['HTTP_CF_VISITOR'] ?? ''), '"https"')) { return true; }
        return (int) ($server['SERVER_PORT'] ?? 0) === 443;
    }

    private static function cleanHost(string $h): string
    {
        $h = strtolower(trim($h));
        $h = (string) preg_replace('#^https?://#', '', $h);
        $h = (string) preg_replace('#[/:].*$#', '', $h);
        return preg_match('/^[a-z0-9.\-]+$/', $h) ? rtrim($h, '.') : '';
    }

    private static function wwwTwin(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
    }

    /**
     * Pure.
     *
     * @param array $in {
     *   server: array ($_SERVER),
     *   settings: array{main_host?:string, hide_site?:string},
     *   app_url: string,
     *   production: bool,           APP_ENV is production
     *   builder: bool,              the hosted Builder (App\Tenancy\Tenant exists)
     *   tenant: ?array{tier?:string, demo_mode?:int|string, demo_search_enabled?:int|string},
     *   domains: list<array{hostname:string, kind:string, verified_at:?string}>,
     *   env_hidden?: bool,          SEARCH_HIDDEN=1 in .env: a staging or preview copy (Get found, 21 Nov 2026)
     * }
     */
    public static function build(array $in): self
    {
        $server  = (array) ($in['server'] ?? []);
        $set     = (array) ($in['settings'] ?? []);
        $appUrl  = (string) ($in['app_url'] ?? '');
        $builder = (bool) ($in['builder'] ?? false);
        $tenant  = $in['tenant'] ?? null;
        $domains = (array) ($in['domains'] ?? []);

        $reqHost  = self::cleanHost((string) ($server['HTTP_HOST'] ?? ''));
        $reqHttps = self::requestIsHttps($server);
        $appHost  = self::cleanHost((string) parse_url($appUrl, PHP_URL_HOST));
        $appHttps = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https';

        $apex = $builder && $tenant === null;

        // ── Main host ──
        $custom = ''; $sub = '';
        $tenantHosts = [];
        foreach ($domains as $d) {
            $h = self::cleanHost((string) ($d['hostname'] ?? ''));
            if ($h === '' || str_contains($h, '*')) { continue; }
            $tenantHosts[] = $h;
            $kind = (string) ($d['kind'] ?? '');
            if ($kind === 'custom' && !empty($d['verified_at']) && $custom === '') { $custom = $h; }
            if ($kind === 'subdomain' && $sub === '') { $sub = $h; }
        }
        $explicit = self::cleanHost((string) ($set['main_host'] ?? ''));
        if ($explicit !== '' && !self::isPublicHost($explicit) && $explicit !== $reqHost) { $explicit = ''; }

        if ($explicit !== '') {
            $main = $explicit;
        } elseif (!$apex && $builder && $tenant !== null && ($custom !== '' || $sub !== '')) {
            $main = $custom !== '' ? $custom : $sub;
        } elseif (!$builder && $appHost !== '' && (self::isPublicHost($appHost) || $reqHost === '')) {
            $main = $appHost;
        } else {
            $main = $reqHost !== '' ? $reqHost : $appHost;
        }

        // ── Scheme ──
        // https when this request came over https, or the main host is known to serve it: a kit whose APP_URL says
        // https for that host (managed servers switch APP_URL to https:// once the certificate is issued), or a
        // hosted tenant in production (every hosted site is served through Cloudflare with TLS).
        $httpsKnown = ($appHttps && $appHost === $main)
            || ($builder && $tenant !== null && !empty($in['production']) && self::isPublicHost($main));
        $https = ($reqHttps && ($reqHost === $main || $reqHost === '')) || $httpsKnown;

        // ── Own hosts + redirect hosts ──
        $own = array_values(array_unique(array_filter(array_merge(
            [$main, self::wwwTwin($main), $reqHost, $appHost],
            $tenantHosts
        ))));
        $redirect = [];
        if (self::isPublicHost($main)) {
            $redirect[] = self::wwwTwin($main);
            foreach ($tenantHosts as $h) { if ($h !== $main) { $redirect[] = $h; } }
        }
        $redirect = array_values(array_unique(array_filter($redirect, static fn ($h) => $h !== $main && self::isPublicHost($h))));

        // ── Hidden ──
        $hidden = !empty($in['env_hidden']) ? 'staging' : '';
        if ($hidden === '' && $builder && is_array($tenant)) {
            if ((int) ($tenant['demo_mode'] ?? 0) === 1) {
                if ((int) ($tenant['demo_search_enabled'] ?? 0) !== 1) { $hidden = 'demo'; }
            } elseif ((string) ($tenant['tier'] ?? 'production') === 'proofing') {
                $hidden = 'preview';
            }
        }
        if ($hidden === '' && (string) ($set['hide_site'] ?? '0') === '1') { $hidden = 'owner'; }

        return new self($reqHost, $reqHttps, $main, $https, $own, $redirect, $hidden, $apex, $httpsKnown);
    }

    /** This request's profile. Never throws: on any failure, a profile built from the request alone. */
    public static function current(): self
    {
        if (self::$current !== null) { return self::$current; }
        $builder = class_exists(\App\Tenancy\Tenant::class);
        $tenantRow = null; $domains = [];
        try {
            if ($builder && ($t = \App\Tenancy\Tenant::current()) !== null) {
                [$tenantRow, $domains] = self::centralFacts($t->id, $t->row);
            }
        } catch (\Throwable) {
            $tenantRow = $tenantRow ?? ['tier' => 'production'];
        }
        $appUrl = (string) ($_ENV['APP_URL'] ?? (function_exists('config') ? config('app.url', '') : ''));
        $env = strtolower((string) ($_ENV['APP_ENV'] ?? (function_exists('config') ? config('app.env', '') : '')));
        return self::$current = self::build([
            'server'     => $_SERVER,
            'settings'   => ['main_host' => SearchSettings::get('seo.main_host'), 'hide_site' => SearchSettings::get('seo.hide_site')],
            'app_url'    => $appUrl,
            'production' => $env === 'production' || $env === 'prod',
            'builder'    => $builder,
            'tenant'     => $tenantRow,
            'domains'    => $domains,
            'env_hidden' => in_array(strtolower(trim((string) ($_ENV['SEARCH_HIDDEN'] ?? getenv('SEARCH_HIDDEN') ?: ''))), ['1', 'true', 'yes', 'on'], true),
        ]);
    }

    /** Tests and CLI loops (one process, several tenants) start again. */
    public static function reset(): void { self::$current = null; }

    /** @return array{0: array, 1: list<array>} the tenant's tier/demo facts and its domains, from the central DB */
    private static function centralFacts(int $tenantId, array $row): array
    {
        $tenant = [
            'tier'                => $row['tier'] ?? null,
            'demo_mode'           => $row['demo_mode'] ?? null,
            'demo_search_enabled' => $row['demo_search_enabled'] ?? null,
        ];
        $domains = [];
        if (!class_exists(\App\Database\CentralDatabase::class)) {
            return [array_map(static fn ($v) => $v ?? 0, $tenant + ['tier' => 'production']), []];
        }
        $pdo = \App\Database\CentralDatabase::get();
        // The resolver's row has every tenants column; a CLI loop (tenants:schedule-run) builds a Tenant with no row.
        if ($tenant['tier'] === null || $tenant['demo_mode'] === null || !array_key_exists('demo_search_enabled', $row)) {
            $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
            $st->execute([$tenantId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            $tenant = [
                'tier'                => $r['tier'] ?? 'production',
                'demo_mode'           => $r['demo_mode'] ?? 0,
                'demo_search_enabled' => $r['demo_search_enabled'] ?? 0,
            ];
        }
        $st = $pdo->prepare('SELECT hostname, kind, verified_at FROM domains WHERE tenant_id = ? ORDER BY id');
        $st->execute([$tenantId]);
        $domains = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return [$tenant, $domains];
    }
}
