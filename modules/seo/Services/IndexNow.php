<?php
// modules/seo/Services/IndexNow.php
namespace Modules\Seo\Services;

/**
 * IndexNow: how a site tells search engines "this address is new or changed" instead of waiting to be crawled. One
 * POST to api.indexnow.org reaches every engine in the scheme — Bing (whose index ChatGPT search and Copilot answer
 * from), Yandex, Seznam, Naver. Google is not in it; Google reads the sitemap.
 *
 * Ported from StoriesDen's publish-hub (12 Nov 2026). What it sends: the sitemap is the list of record. Each address
 * is sent when it is new, or when its <lastmod> differs from what was last sent; one with no lastmod is sent again
 * every RESEND_DAYS. What was sent is the record in the site setting seo.indexnow.sent (address => "lastmod|date"),
 * and the last run's result is seo.indexnow.last ("date status count http").
 *
 * The key proves the site is ours: engines fetch /indexnow-key.txt and expect it to match. It is an HMAC of APP_KEY
 * and the host, so it is stable across deploys, differs per site (hosted tenants share one APP_KEY) and never
 * reveals APP_KEY. It is public by design.
 *
 * ⚠ Only a real public https host ever pings (SiteProfile::isPublicHost): dev machines, test runs, hidden sites
 * (demos, previews) and sites that turned it off send nothing. Tests swap the sender with useSender().
 */
final class IndexNow
{
    public const ENDPOINT     = 'https://api.indexnow.org/indexnow';
    public const SETTING      = 'seo.indexnow.sent';
    public const LAST         = 'seo.indexnow.last';
    public const RESEND_DAYS  = 30;
    public const MAX_PER_CALL = 10000;

    /** @var null|callable(string $url, string $json): int  returns the HTTP status */
    private static $sender = null;

    /** @var null|array{load: callable(): array, save: callable(array, string): void} */
    private static ?array $store = null;

    public static function useSender(?callable $sender): void { self::$sender = $sender; }

    /** Tests: keep the record in memory. */
    public static function useStore(?array $store): void { self::$store = $store; }

    public static function key(string $host): string
    {
        $appKey = (string) ($_ENV['APP_KEY'] ?? (function_exists('config') ? config('app.key', '') : ''));
        return substr(hash_hmac('sha256', 'indexnow|' . strtolower($host), $appKey !== '' ? $appKey : 'no-app-key'), 0, 32);
    }

    /** Pure: may this base URL ping? Only a public https host. */
    public static function publicHost(string $base): ?string
    {
        $p = parse_url($base);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || !SiteProfile::isPublicHost($host)) { return null; }
        return $host;
    }

    /**
     * Pure: which addresses to send now.
     * @param array<string, ?string> $current  loc => lastmod (from the sitemap)
     * @param array<string, string>  $sent     loc => "lastmod|Y-m-d sent"
     * @return list<string>
     */
    public static function due(array $current, array $sent, string $today): array
    {
        $due = [];
        foreach ($current as $loc => $mod) {
            if (!isset($sent[$loc])) { $due[] = $loc; continue; }
            [$sentMod, $sentOn] = array_pad(explode('|', $sent[$loc], 2), 2, '');
            if ($mod !== null && $mod !== $sentMod) { $due[] = $loc; continue; }
            if ($mod === null && ($sentOn === '' || strtotime($today) - strtotime($sentOn) >= self::RESEND_DAYS * 86400)) { $due[] = $loc; }
        }
        return $due;
    }

    /**
     * Send what's due. $sitemapXml should already be the module's sitemap (main host, account pages dropped).
     * Returns ['status' => 'sent'|'nothing'|'skipped'|'dry-run'|'failed', 'count' => n, 'http' => code, 'urls' => [...]].
     */
    public function run(string $base, string $sitemapXml, bool $all = false, bool $dryRun = false): array
    {
        $host = self::publicHost($base);
        if ($host === null) { return ['status' => 'skipped', 'count' => 0, 'http' => 0, 'urls' => []]; }
        $current = [];
        foreach (SearchFiles::parseSitemap($sitemapXml) as $e) {
            if (SearchHead::noindex((string) (parse_url($e['loc'], PHP_URL_PATH) ?: '/'))) { continue; }
            $current[$e['loc']] = $e['lastmod'];
        }
        $sent = $this->loadSent();
        $today = gmdate('Y-m-d');
        $urls = $all ? array_keys($current) : self::due($current, $sent, $today);
        // Only this site's own addresses: IndexNow refuses a batch with another host in it.
        $urls = array_values(array_filter($urls, static fn ($u) => strtolower((string) parse_url((string) $u, PHP_URL_HOST)) === $host));
        $urls = array_slice($urls, 0, self::MAX_PER_CALL);

        if (!$urls)  { return ['status' => 'nothing', 'count' => 0, 'http' => 0, 'urls' => []]; }
        if ($dryRun) { return ['status' => 'dry-run', 'count' => count($urls), 'http' => 0, 'urls' => $urls]; }

        $json = (string) json_encode([
            'host'        => $host,
            'key'         => self::key($host),
            'keyLocation' => rtrim($base, '/') . '/indexnow-key.txt',
            'urlList'     => $urls,
        ], JSON_UNESCAPED_SLASHES);
        $code = $this->send(self::ENDPOINT, $json);

        // 200 = received, 202 = received, key still being checked. Anything else: keep the old record so it's retried.
        if ($code !== 200 && $code !== 202) {
            $this->saveLast("$today failed " . count($urls) . " $code");
            return ['status' => 'failed', 'count' => count($urls), 'http' => $code, 'urls' => $urls];
        }
        foreach ($urls as $u) { $sent[$u] = ($current[$u] ?? '') . '|' . $today; }
        // Forget addresses that left the sitemap, so the record can't grow forever.
        $sent = array_intersect_key($sent, $current);
        $this->saveSent($sent);
        $this->saveLast("$today sent " . count($urls) . " $code");
        return ['status' => 'sent', 'count' => count($urls), 'http' => $code, 'urls' => $urls];
    }

    private function send(string $url, string $json): int
    {
        if (self::$sender !== null) { return (int) (self::$sender)($url, $json); }
        if (!function_exists('curl_init')) { return 0; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }

    /** @return array<string, string> */
    public function loadSent(): array
    {
        if (self::$store !== null) { return (self::$store['load'])(); }
        $raw = function_exists('setting') ? setting(self::SETTING, null) : null;
        $v = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
        return is_array($v) ? array_map('strval', $v) : [];
    }

    private function saveSent(array $sent): void
    {
        if (self::$store !== null) { (self::$store['save'])($sent, self::SETTING); return; }
        app(\Core\Services\SettingsService::class)->set(self::SETTING, $sent, 'site', null, 'json');
    }

    private function saveLast(string $line): void
    {
        if (self::$store !== null) { (self::$store['save'])([$line], self::LAST); return; }
        try { app(\Core\Services\SettingsService::class)->set(self::LAST, $line, 'site'); } catch (\Throwable) {}
    }
}
