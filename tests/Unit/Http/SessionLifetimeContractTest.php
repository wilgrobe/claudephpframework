<?php
// tests/Unit/Http/SessionLifetimeContractTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * The session-timeout warning (ported upstream from MarketOtter, where it has
 * run in production since 2026-09-10) rests on three rules that break silently:
 *
 *  1. Asking how long is left is NOT activity. AuthMiddleware stamps
 *     LAST_SEEN on every request except /session/status — otherwise a tab
 *     polling the countdown keeps its own session alive and never times out,
 *     and the warning is the reason it never fires.
 *  2. /session/expire has NO AuthMiddleware: it is called exactly when the
 *     session is dying, and bouncing it to /login would defeat clearing the
 *     cookie first.
 *  3. /session/extend is a state change, so it carries CSRF; /csrf-token is a
 *     guest GET (the forms that go stale are sign-in, register, reset).
 */
final class SessionLifetimeContractTest extends TestCase
{
    private function src(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(BASE_PATH . '/' . $rel));
    }

    public function test_asking_for_the_status_does_not_count_as_activity(): void
    {
        $mw = $this->src('app/Middleware/AuthMiddleware.php');
        $this->assertMatchesRegularExpression(
            "~if \\('/' \\. ltrim\\(\\\$request->path\\(\\), '/'\\) !== '/session/status'\\) \\{\\s*try \\{ Session::set\\(\\\\App\\\\Controllers\\\\SessionLifetimeController::LAST_SEEN~",
            $mw,
            'every request except /session/status must stamp LAST_SEEN — otherwise the countdown keeps the session alive'
        );
    }

    public function test_the_routes_carry_the_middleware_the_design_needs(): void
    {
        $r = $this->src('routes/web.php');
        $this->assertStringContainsString("\$router->get ('/session/status', 'SessionLifetimeController@status', [AuthMiddleware::class]);", $r);
        $this->assertStringContainsString("\$router->post('/session/extend', 'SessionLifetimeController@extend', [AuthMiddleware::class, CsrfMiddleware::class]);", $r,
            'extending the session is a state change and must carry CSRF');
        $this->assertStringContainsString("\$router->post('/session/expire', 'SessionLifetimeController@expire');", $r,
            '/session/expire must NOT require auth: it runs precisely when the session is dying');
        $this->assertStringContainsString("\$router->get('/csrf-token', function () {", $r,
            'stale guest forms re-mint their token from /csrf-token');
    }

    public function test_the_last_seen_key_is_the_one_the_middleware_writes(): void
    {
        $this->assertSame('_last_seen', \App\Controllers\SessionLifetimeController::LAST_SEEN);
    }
}
