<?php
// modules/loginanomaly/Services/GeoIpService.php
namespace Modules\Loginanomaly\Services;

use Core\Database\Database;

/**
 * IP → geo lookup with persistent cache.
 *
 * Default provider: ip-api.com (free, no API key, ~45 req/min limit
 * per origin IP). Cached for 30 days per IP — a typical site sees
 * the same client IPs repeatedly so the cache hit rate is high.
 *
 * Failures are cached too (with `lookup_failed=1`) for a shorter
 * window so a single network blip doesn't prevent re-lookups for a
 * month, but a persistently-unreachable IP doesn't get retried on
 * every login.
 *
 * To swap providers (e.g. MaxMind GeoLite2): subclass this service
 * and override `fetchFromProvider()`. The cache layer is provider-
 * agnostic.
 *
 * Designed to fail OPEN — if the provider is unreachable, returns
 * null. Callers (LoginAnomalyService) treat null as "geo unknown,
 * skip detection" rather than blocking the login.
 */
class GeoIpService
{
    public const CACHE_TTL_OK_DAYS    = 30;
    public const CACHE_TTL_FAIL_DAYS  = 1;
    public const HTTP_TIMEOUT         = 3;
    public const PROVIDER_IP_API      = 'ip-api';
    public const IP_API_URL           = 'http://ip-api.com/json/';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * @return array{country_code:string,country_name:string,region:string,city:string,latitude:float,longitude:float}|null
     */
    public function lookup(string $ip): ?array
    {
        if ($ip === '' || $ip === '0.0.0.0') return null;

        // Skip private + reserved IPs — no point asking the provider,
        // and we'd just get back nothing useful anyway.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) return null;

        // Cache hit?
        try {
            $row = $this->db->fetchOne(
                "SELECT * FROM login_geo_cache WHERE ip_address = ? AND expires_at > NOW()",
                [$packed]
            );
        } catch (\Throwable) {
            $row = null;
        }
        if ($row) {
            if ((int) $row['lookup_failed'] === 1) return null;
            return $this->shape($row);
        }

        // Live lookup.
        $fetched = $this->fetchFromProvider($ip);
        $this->cachePut($packed, $fetched);
        return $fetched;
    }

    /**
     * @return array{country_code:string,country_name:string,region:string,city:string,latitude:float,longitude:float}|null
     */
    private function fetchFromProvider(string $ip): ?array
    {
        $url = self::IP_API_URL . urlencode($ip)
             . '?fields=status,countryCode,country,regionName,city,lat,lon';

        $body = $this->httpGet($url);
        if ($body === null) return null;

        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') return null;

        $lat = isset($data['lat']) ? (float) $data['lat'] : 0.0;
        $lon = isset($data['lon']) ? (float) $data['lon'] : 0.0;
        if ($lat === 0.0 && $lon === 0.0) return null;

        return [
            'country_code' => (string) ($data['countryCode'] ?? ''),
            'country_name' => (string) ($data['country']     ?? ''),
            'region'       => (string) ($data['regionName']  ?? ''),
            'city'         => (string) ($data['city']        ?? ''),
            'latitude'     => $lat,
            'longitude'    => $lon,
        ];
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => 'cphpfw-loginanomaly/1.0',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $code < 200 || $code >= 300) return null;
            return $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT,
                'header'        => "User-Agent: cphpfw-loginanomaly/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) && $body !== '' ? $body : null;
    }

    private function cachePut($ipPacked, ?array $data): void
    {
        try {
            $ttlDays = $data === null ? self::CACHE_TTL_FAIL_DAYS : self::CACHE_TTL_OK_DAYS;
            $this->db->query("
                REPLACE INTO login_geo_cache
                  (ip_address, country_code, country_name, region, city, latitude, longitude, provider, fetched_at, expires_at, lookup_failed)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)
            ", [
                $ipPacked,
                $data['country_code'] ?? null,
                $data['country_name'] ?? null,
                $data['region']       ?? null,
                $data['city']         ?? null,
                isset($data['latitude'])  ? $data['latitude']  : null,
                isset($data['longitude']) ? $data['longitude'] : null,
                self::PROVIDER_IP_API,
                $ttlDays,
                $data === null ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('GeoIpService cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Haversine distance in kilometres between two lat/lon pairs.
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0;
        $rLat1 = deg2rad($lat1);
        $rLat2 = deg2rad($lat2);
        $dLat  = deg2rad($lat2 - $lat1);
        $dLon  = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos($rLat1) * cos($rLat2) * sin($dLon / 2) ** 2;
        return 2 * $earthKm * asin(min(1.0, sqrt($a)));
    }

    private function shape(array $row): array
    {
        return [
            'country_code' => (string) ($row['country_code'] ?? ''),
            'country_name' => (string) ($row['country_name'] ?? ''),
            'region'       => (string) ($row['region']       ?? ''),
            'city'         => (string) ($row['city']         ?? ''),
            'latitude'     => (float)  ($row['latitude']     ?? 0.0),
            'longitude'    => (float)  ($row['longitude']    ?? 0.0),
        ];
    }
}
