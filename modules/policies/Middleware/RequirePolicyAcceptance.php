<?php
// modules/policies/Middleware/RequirePolicyAcceptance.php
namespace Modules\Policies\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Modules\Policies\Services\PolicyService;

/**
 * Blocks every authenticated request when the user has unaccepted
 * required-acceptance policies.
 *
 * Allow-list of routes that pass through unconditionally:
 *   - /logout                  always (user must be able to leave)
 *   - /policies/*              the show + accept endpoints themselves
 *   - /account/policies        the user's history page
 *   - /account/data            GDPR self-service stays available
 *   - /api/*                   API clients aren't end users — they
 *                              shouldn't be hit by an interactive modal
 *
 * Superadmins are exempt while in superadmin mode (so an admin who
 * needs to fix a misconfigured policy can reach /admin/policies).
 *
 * Wired globally from public/index.php after the maintenance gate.
 * Not attached per-route.
 */
class RequirePolicyAcceptance
{
    private const ALLOW_EXACT = [
        '/logout',
        '/login',
        '/register',
        '/account/policies',
        '/account/data',
    ];

    private const ALLOW_PREFIX = [
        '/policies/',           // show + accept
        '/auth/',
        '/api/',                // JSON / external clients
        '/uploads/',            // avatars, attachments
        '/assets/',
    ];

    public static function isBlocked(Request $request): ?Response
    {
        $auth = Auth::getInstance();
        if ($auth->guest()) return null;
        if ($auth->isSuperadminModeOn()) return null;

        $path = '/' . ltrim((string) parse_url($request->path(), PHP_URL_PATH), '/');
        if (in_array($path, self::ALLOW_EXACT, true)) return null;
        foreach (self::ALLOW_PREFIX as $p) {
            if (str_starts_with($path, $p)) return null;
        }

        try {
            $svc       = new PolicyService();
            $unaccepted = $svc->unacceptedFor((int) $auth->id());
        } catch (\Throwable $e) {
            // Schema not migrated yet — fail-open. Once the policies
            // tables exist, the gate kicks in automatically.
            return null;
        }

        if (empty($unaccepted)) return null;

        // Capture the original requested path + query so the controller
        // can return the user to where they were going after they
        // accept. Skip if the user was already heading somewhere we
        // wouldn't want to send them post-accept (auth pages, the
        // accept form itself).
        $original = $path;
        $qs       = $request->server('QUERY_STRING');
        if ($qs) $original .= '?' . $qs;
        if (!str_starts_with($original, '/policies/')
            && !str_starts_with($original, '/login')
            && !str_starts_with($original, '/logout')
        ) {
            // Stash on the session so the redirect target survives the
            // submit POST. We use $_SESSION direct rather than flash
            // because we want it to persist for multiple requests if
            // the user reloads the accept form.
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['__intended_after_policy_accept'] = $original;
            }
        }

        // Block. Send to the accept page with a list of pending kinds.
        return Response::redirect('/policies/accept');
    }
}
