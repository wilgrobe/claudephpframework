<?php
// tests/Unit/Auth/SlidingSessionCookieTest.php
namespace Tests\Unit\Auth;

use App\Controllers\SessionLifetimeController as S;
use Tests\TestCase;

/**
 * Activity keeps the session cookie alive; only idleness ends a session.
 *
 * 2026-10-01: PHP sends the session cookie when a session starts and never again,
 * so `session.cookie_lifetime` (2h) was an ABSOLUTE limit from sign-in. A busy
 * author was signed out two hours in, with no warning, even after pressing "Keep
 * me signed in" — that reset only the idle timer. The cookie now slides.
 */
final class SlidingSessionCookieTest extends TestCase
{
    public function test_a_refresh_is_due_on_first_activity_and_then_at_most_once_a_minute(): void
    {
        $t = 1_000_000;
        $this->assertTrue(S::cookieRefreshDue($t, 0, 7200), 'Never sent: the first activity must send it.');
        $this->assertFalse(S::cookieRefreshDue($t + 10, $t, 7200), 'Re-sent ten seconds after the last time.');
        $this->assertTrue(S::cookieRefreshDue($t + S::COOKIE_REFRESH_EVERY, $t, 7200));
        $this->assertTrue(S::cookieRefreshDue($t + 3 * 3600, $t, 7200));
    }

    public function test_keep_me_signed_in_always_refreshes(): void
    {
        $t = 1_000_000;
        $this->assertTrue(S::cookieRefreshDue($t + 1, $t, 7200, true));
    }

    public function test_a_browser_session_cookie_has_nothing_to_slide(): void
    {
        $this->assertFalse(S::cookieRefreshDue(1_000_000, 0, 0), 'cookie_lifetime 0 = until the browser closes; no expiry to move.');
        $this->assertFalse(S::cookieRefreshDue(1_000_000, 0, 0, true));
    }

    /** The calls that make it happen — without them the logic above decides nothing. */
    public function test_activity_and_keep_me_signed_in_both_slide_the_cookie(): void
    {
        $mw = (string) file_get_contents(BASE_PATH . '/app/Middleware/AuthMiddleware.php');
        $this->assertMatchesRegularExpression(
            '/LAST_SEEN, time\(\)\);.*?SessionLifetimeController::refreshCookie\(\);/s',
            $mw,
            'AuthMiddleware stamps activity but no longer slides the cookie — sessions end two hours after sign-in again.'
        );
        $ctl = (string) file_get_contents(BASE_PATH . '/app/Controllers/SessionLifetimeController.php');
        $this->assertMatchesRegularExpression(
            '/function extend\(.*?self::refreshCookie\(true\);/s',
            $ctl,
            '"Keep me signed in" resets the idle timer but not the cookie.'
        );
    }

    /** The cookie is re-sent with the SAME id: sliding must never rotate it. */
    public function test_the_refresh_keeps_the_session_id(): void
    {
        $ctl = (string) file_get_contents(BASE_PATH . '/app/Controllers/SessionLifetimeController.php');
        preg_match('/function refreshCookie\(.*?\n    \}/s', $ctl, $m);
        $this->assertNotEmpty($m, 'refreshCookie() is gone.');
        $this->assertStringContainsString('session_id()', $m[0]);
        $this->assertStringNotContainsString('session_regenerate_id', $m[0], 'Sliding the expiry must not rotate the id.');
    }
}
