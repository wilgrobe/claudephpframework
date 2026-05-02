<?php
// modules/loginanomaly/Services/LoginAnomalyService.php
namespace Modules\Loginanomaly\Services;

use Core\Database\Database;

/**
 * Detects suspicious sign-in patterns by comparing the current login's
 * geo to the user's PRIOR login geo. Currently implements:
 *
 *   impossible_travel  — distance-from-prior-login / time-elapsed
 *                        exceeds the configured km/h threshold
 *                        (default 900 km/h ~ commercial flight cruise
 *                        plus 1h airport buffer).
 *   country_jump       — different country from prior login regardless
 *                        of speed; logged as 'info' severity.
 *
 * Both checks need PRIOR login geo to compare against. The first
 * login from a fresh user / new install reports nothing (no prior
 * data point — every login looks new).
 *
 * Severity:
 *   info   — country_jump under threshold OR plausible-speed travel
 *   warn   — impossible_travel above threshold_kmh
 *   alert  — impossible_travel above alert_threshold_kmh (likely
 *            VPN / proxy hop)
 *
 * The service does NOT block the login — it records the finding +
 * returns it. The caller decides what to do (typically: send the
 * user a "suspicious sign-in" email and let them act on it).
 */
class LoginAnomalyService
{
    private Database     $db;
    private GeoIpService $geo;

    public function __construct(?Database $db = null, ?GeoIpService $geo = null)
    {
        $this->db  = $db  ?? Database::getInstance();
        $this->geo = $geo ?? new GeoIpService($this->db);
    }

    public function isEnabled(): bool
    {
        return (bool) (setting('login_anomaly_enabled', false) ?? false);
    }

    /**
     * Analyse a sign-in event. Returns the recorded anomaly row id +
     * severity, or null when no anomaly fires (clean login or
     * insufficient data to compare).
     *
     * Side effects:
     *   - Performs a geo lookup for $ip (cached 30 days).
     *   - Inserts a row into login_anomalies if anything fires.
     *
     * @return array{anomaly_id:int, severity:string, rule:string, implied_kmh:?int, distance_km:?int}|null
     */
    public function analyseLogin(int $userId, string $ip, string $userAgent): ?array
    {
        if (!$this->isEnabled()) return null;
        if ($userId <= 0 || $ip === '') return null;

        $currentGeo = $this->geo->lookup($ip);
        if ($currentGeo === null) return null; // unresolvable IP — fail open

        // Find the prior login geo for this user.
        $prior = $this->priorLoginGeo($userId, $ip);
        if ($prior === null) return null; // no prior data point

        $priorTime = (int) strtotime((string) $prior['created_at']);
        $now       = time();
        $elapsed   = max(1, $now - $priorTime); // seconds, never zero
        $elapsedMin = (int) round($elapsed / 60);

        $distanceKm = (int) round(GeoIpService::haversineKm(
            (float) $prior['latitude'], (float) $prior['longitude'],
            (float) $currentGeo['latitude'], (float) $currentGeo['longitude'],
        ));

        // 0-distance same-city login from same prior IP — nothing to do.
        if ($distanceKm === 0) return null;

        $impliedKmh = $elapsedMin > 0
            ? (int) round(($distanceKm * 60) / $elapsedMin)
            : null;

        $threshold      = max(100, (int) (setting('login_anomaly_threshold_kmh',       900)  ?? 900));
        $alertThreshold = max($threshold, (int) (setting('login_anomaly_alert_threshold_kmh', 2000) ?? 2000));

        $rule     = 'country_jump';
        $severity = 'info';

        if ($impliedKmh !== null && $impliedKmh >= $alertThreshold) {
            $rule = 'impossible_travel'; $severity = 'alert';
        } elseif ($impliedKmh !== null && $impliedKmh >= $threshold) {
            $rule = 'impossible_travel'; $severity = 'warn';
        } elseif (($currentGeo['country_code'] ?? '') !== ($prior['country_code'] ?? '')) {
            $rule = 'country_jump'; $severity = 'info';
        } else {
            // Same country, plausible speed — nothing notable.
            return null;
        }

        $anomalyId = $this->db->insert('login_anomalies', [
            'user_id'            => $userId,
            'ip_address'         => @inet_pton($ip) ?: null,
            'user_agent'         => mb_substr($userAgent, 0, 500),
            'country_code'       => $currentGeo['country_code'] ?? null,
            'city'               => $currentGeo['city']         ?? null,
            'prior_country_code' => $prior['country_code']      ?? null,
            'prior_city'         => $prior['city']              ?? null,
            'distance_km'        => $distanceKm,
            'elapsed_minutes'    => $elapsedMin,
            'implied_kmh'        => $impliedKmh,
            'severity'           => $severity,
            'rule'               => $rule,
            'action_taken'       => null,  // caller fills this in if it sends an email
        ]);

        return [
            'anomaly_id'   => (int) $anomalyId,
            'severity'     => $severity,
            'rule'         => $rule,
            'implied_kmh'  => $impliedKmh,
            'distance_km'  => $distanceKm,
            'current_geo'  => $currentGeo,
            'prior_geo'    => [
                'country_code' => $prior['country_code'] ?? '',
                'city'         => $prior['city']         ?? '',
            ],
        ];
    }

    /**
     * The user's previous-login location, joined to the geo cache.
     * Excludes the CURRENT IP so a quick refresh doesn't compare
     * against itself.
     */
    private function priorLoginGeo(int $userId, string $currentIp): ?array
    {
        $currentPacked = @inet_pton($currentIp);

        // Walk back through the user's session history. Sessions
        // stores ip_address as VARCHAR(45) not VARBINARY, so we
        // resolve it back to bytes before joining the geo cache.
        $rows = $this->db->fetchAll("
            SELECT s.ip_address AS ip_str, s.last_activity AS created_at
            FROM sessions s
            WHERE s.user_id = ?
            ORDER BY s.last_activity DESC
            LIMIT 5
        ", [$userId]);

        foreach ($rows as $row) {
            $ipStr = (string) $row['ip_str'];
            if ($ipStr === '' || $ipStr === $currentIp) continue;

            $packed = @inet_pton($ipStr);
            if ($packed === false) continue;
            if ($currentPacked !== false && $packed === $currentPacked) continue;

            $geo = $this->db->fetchOne(
                "SELECT country_code, city, latitude, longitude FROM login_geo_cache
                 WHERE ip_address = ? AND lookup_failed = 0",
                [$packed]
            );
            if ($geo) {
                $geo['created_at'] = $row['created_at'];
                return $geo;
            }

            // Lazily populate the cache for the prior IP if missing.
            $fresh = $this->geo->lookup($ipStr);
            if ($fresh) {
                $fresh['created_at'] = $row['created_at'];
                return $fresh;
            }
        }

        return null;
    }

    public function recentAnomalies(int $limit = 100): array
    {
        return $this->db->fetchAll("
            SELECT a.*, u.username, u.email,
                   ack.username AS ack_username
            FROM login_anomalies a
            LEFT JOIN users u   ON u.id   = a.user_id
            LEFT JOIN users ack ON ack.id = a.acknowledged_by
            ORDER BY a.id DESC
            LIMIT ?
        ", [$limit]);
    }

    public function statsLast30Days(): array
    {
        return $this->db->fetchOne("
            SELECT
              COUNT(*) AS total,
              SUM(severity = 'info')  AS info,
              SUM(severity = 'warn')  AS warn,
              SUM(severity = 'alert') AS alerts,
              SUM(acknowledged_at IS NULL) AS unacknowledged
            FROM login_anomalies
            WHERE created_at > NOW() - INTERVAL 30 DAY
        ") ?: ['total'=>0,'info'=>0,'warn'=>0,'alerts'=>0,'unacknowledged'=>0];
    }

    public function acknowledge(int $anomalyId, int $byUserId): void
    {
        $this->db->update('login_anomalies', [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_by' => $byUserId,
        ], 'id = ?', [$anomalyId]);
    }

    public function markActionTaken(int $anomalyId, string $action): void
    {
        $this->db->update('login_anomalies', [
            'action_taken' => mb_substr($action, 0, 120),
        ], 'id = ?', [$anomalyId]);
    }
}
