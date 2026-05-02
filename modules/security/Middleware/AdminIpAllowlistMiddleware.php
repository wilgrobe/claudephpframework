<?php
// modules/security/Middleware/AdminIpAllowlistMiddleware.php
namespace Modules\Security\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Modules\Security\Services\CidrMatcher;

/**
 * IP allowlist for /admin/* routes.
 *
 * When `admin_ip_allowlist_enabled` is on, every request to a path
 * starting with /admin/ is gated against the CIDR list configured in
 * `admin_ip_allowlist`. A miss returns 403 with a minimal page so the
 * client doesn't even see a login form.
 *
 * Hook: called from public/index.php as a static gate, after the
 * maintenance gate but before route dispatch. Returns null to allow,
 * Response to block.
 *
 * Why static + inline gate vs. attached middleware:
 *   - Self-contained — security module owns it; no patches to
 *     RequireAdmin or admin route declarations.
 *   - Path-prefix scoped — gate fires for /admin/* only. The check is
 *     cheap (single setting read + at-most-N CIDR comparisons), so the
 *     occasional non-admin request that gets the read-and-skip is fine.
 *   - Easy to disable — module uninstall / setting toggle off, gate
 *     returns null immediately.
 *
 * UX safeguards (both implemented in SettingsController, not here):
 *   - The save handler validates that the editing admin's CURRENT IP
 *     is in the proposed list before persisting — refuses-with-error
 *     if they'd lock themselves out.
 *   - The toggle defaults off; the input is empty by default.
 */
class AdminIpAllowlistMiddleware
{
    public static function isBlocked(Request $request): ?Response
    {
        // Cheap fast path — only fire for /admin/*.
        $path = '/' . ltrim((string) parse_url($request->path(), PHP_URL_PATH), '/');
        if (!str_starts_with($path, '/admin')) return null;

        if (!(bool) (setting('admin_ip_allowlist_enabled', false) ?? false)) {
            return null;
        }

        $listText = (string) (setting('admin_ip_allowlist', '') ?? '');
        $entries  = CidrMatcher::parseList($listText);
        if (empty($entries)) {
            // Toggle on but list is empty = misconfiguration. Fail
            // OPEN — better to let admins in than to lock the whole
            // /admin out of an empty list.
            return null;
        }

        $ip = $request->ip();
        if (CidrMatcher::matches($ip, $entries)) return null;

        // Audit the block, then refuse. Best-effort audit so a logging
        // failure doesn't accidentally let the request through.
        try {
            Auth::getInstance()->auditLog(
                'security.admin_ip_blocked',
                null, null, null,
                ['ip' => $ip, 'path' => $path]
            );
        } catch (\Throwable) {
            // ignore
        }

        $body = '<!DOCTYPE html><html><head><title>403</title>'
              . '<style>body{font-family:system-ui,Arial,sans-serif;display:flex;align-items:center;'
              . 'justify-content:center;min-height:100vh;margin:0;background:#fafafa;color:#111}'
              . '.box{text-align:center;padding:2rem;max-width:480px}.box h1{margin:0 0 .5rem;font-size:1.4rem}'
              . '.box p{color:#6b7280;font-size:14px;line-height:1.5}.ip{font-family:ui-monospace,monospace;'
              . 'background:#f3f4f6;padding:.1rem .4rem;border-radius:4px;font-size:12px}</style></head><body>'
              . '<div class="box"><h1>Access denied</h1>'
              . '<p>This site\'s admin area restricts access by IP address. Your address '
              . '(<span class="ip">' . htmlspecialchars($ip, ENT_QUOTES) . '</span>) is not on the allowlist.</p>'
              . '<p>Contact your administrator if you need access.</p></div></body></html>';

        return new Response($body, 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
