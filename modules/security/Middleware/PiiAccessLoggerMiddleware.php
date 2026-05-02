<?php
// modules/security/Middleware/PiiAccessLoggerMiddleware.php
namespace Modules\Security\Middleware;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;

/**
 * Records every admin read of a personal-data surface as a sealed
 * `pii.viewed` audit_log row. Completes the SOC2 / ISO 27001 evidence
 * triangle alongside the audit chain (writes) and GDPR module (erasure
 * + export):
 *
 *   • write  — Auth::auditLog → AuditChainService::sealAndInsert
 *   • read   — this middleware → audit_log row of action 'pii.viewed'
 *   • erase  — Modules\Gdpr\Services\DataPurger
 *
 * Path scope (only these admin paths are PII-bearing surfaces):
 *
 *   /admin/users                     user list + per-user records
 *   /admin/users/{id}                user detail
 *   /admin/users/{id}/edit           user edit form
 *   /admin/sessions                  every active session, joined to users
 *   /admin/audit-log                 admin can see everyone's actions
 *   /admin/audit-log/{id}            audit row detail (often holds PII in old/new_values)
 *   /admin/dsar                      DSAR queue with requester emails
 *   /admin/dsar/{id}                 DSAR detail
 *   /admin/gdpr                      GDPR queue, same as DSAR
 *   /admin/gdpr/dsar/{id}            DSAR detail under gdpr namespace
 *   /admin/email-suppressions        suppressed email addresses
 *   /admin/email-suppressions/blocks blocked-send log shows recipient emails
 *   /admin/email-suppressions/bounces bounce events with email
 *
 * The list is conservative — it captures the cases where an admin
 * is reading another user's personal data. Pages where admins
 * configure the framework itself (settings, modules, retention rules)
 * are NOT logged because the admin is reading their own / the
 * framework's data, not personal data of other users.
 *
 * Throttling: identical (admin_id, path) within `THROTTLE_SECONDS`
 * does NOT log a duplicate row. Without this, a single click on a
 * link followed by a refresh would write 2 rows for the same view —
 * noisy. The throttle uses an in-process static cache, which is
 * sufficient for a single request lifecycle but resets between
 * requests; the second request after a refresh DOES log if outside
 * the window. For tighter throttling across requests, you could move
 * the de-dup to the audit_log itself with a "find recent identical
 * row" probe before insert — declined here because the duplicate-row
 * cost is negligible vs. the extra DB round-trip on every page load.
 *
 * GET-only: POSTs / PUTs / DELETEs are already covered by the
 * controller's own auditLog calls (e.g. user.create, user.update).
 * Logging POSTs here too would create double-rows.
 */
class PiiAccessLoggerMiddleware
{
    public const THROTTLE_SECONDS = 30;

    /** Path prefixes that count as a PII read. */
    private const SCOPE_PREFIXES = [
        '/admin/users',
        '/admin/sessions',
        '/admin/audit-log',
        '/admin/dsar',
        '/admin/gdpr',
        '/admin/email-suppressions',
    ];

    /** @var array<string,int> in-process throttle: pathKey => unix ts of last log */
    private static array $recentLogs = [];

    /**
     * Called from public/index.php after the IP allowlist + idle gates.
     * Returns void — never blocks the request, only records.
     */
    public static function maybeLog(Request $request): void
    {
        // Cheap fast-path: only fire on GET, only for /admin/*.
        if (strtoupper((string) $request->method()) !== 'GET') return;

        $path = '/' . ltrim((string) parse_url($request->path(), PHP_URL_PATH), '/');
        if (!str_starts_with($path, '/admin/')) return;

        // Match against scope prefixes
        $matched = false;
        foreach (self::SCOPE_PREFIXES as $p) {
            if (str_starts_with($path, $p)) { $matched = true; break; }
        }
        if (!$matched) return;

        $auth = Auth::getInstance();
        if ($auth->guest()) return;

        // Setting toggle — disabled means no logging
        if (!(bool) (setting('admin_pii_access_logging_enabled', true) ?? true)) {
            return;
        }

        $actorId = (int) ($auth->id() ?? 0);
        if ($actorId === 0) return;

        // Throttle identical (actor, path) within the window.
        $key = $actorId . '::' . $path;
        $now = time();
        if (isset(self::$recentLogs[$key]) && ($now - self::$recentLogs[$key]) < self::THROTTLE_SECONDS) {
            return;
        }
        self::$recentLogs[$key] = $now;

        // Best-effort log; never block a request on a logger failure.
        try {
            $auth->auditLog(
                'pii.viewed',
                null,
                null,
                null,
                [
                    'path'   => $path,
                    'method' => 'GET',
                ],
                null
            );
        } catch (\Throwable $e) {
            error_log('PiiAccessLogger: ' . $e->getMessage());
        }
    }
}
