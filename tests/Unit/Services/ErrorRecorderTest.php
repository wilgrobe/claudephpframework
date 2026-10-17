<?php
// tests/Unit/Services/ErrorRecorderTest.php
namespace Tests\Unit\Services;

use Core\Services\ErrorRecorder as R;
use Tests\TestCase;

/**
 * Errors recorded on the site's own server, so the owner's Ops Assistant can stand in for Sentry (board #456).
 * What matters: one row per ERROR (not per occurrence), and nothing private leaving the server in the sample.
 */
final class ErrorRecorderTest extends TestCase
{
    public function test_the_same_error_with_different_values_is_one_row(): void
    {
        $a = R::fingerprint('RuntimeException', 'modules/store/Services/Orders.php', 88, 'Order 123 not found for user 4');
        $b = R::fingerprint('RuntimeException', 'modules/store/Services/Orders.php', 88, 'Order 98765 not found for user 12');
        $this->assertSame($a, $b, 'numbers fold');
        $this->assertSame(R::fingerprint('E', 'f.php', 1, "No row for slug 'alpha'"), R::fingerprint('E', 'f.php', 1, "No row for slug 'beta'"), 'quoted values fold');
        $this->assertNotSame($a, R::fingerprint('RuntimeException', 'modules/store/Services/Orders.php', 89, 'Order 123 not found for user 4'), 'another line is another error');
        $this->assertNotSame($a, R::fingerprint('LogicException', 'modules/store/Services/Orders.php', 88, 'Order 123 not found for user 4'), 'another class too');
        $this->assertNotSame($a, R::fingerprint('RuntimeException', 'modules/store/Services/Orders.php', 88, 'Payment declined'), 'and another message');
    }

    public function test_private_values_never_leave_the_server(): void
    {
        $r = R::redact('Login failed for will@example.com with password=hunter2; token: abc; key sk_live_51Habcdefghijkl and session 0123456789abcdef0123456789abcdef9');
        $this->assertStringNotContainsString('will@example.com', $r);
        $this->assertStringNotContainsString('hunter2', $r);
        $this->assertStringNotContainsString('sk_live_51Habcdefghijkl', $r);
        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef9', $r);
        $this->assertStringContainsString('[email]', $r);
        $this->assertStringContainsString('password=[redacted]', $r);
        $this->assertStringContainsString('token: [redacted]', $r);
        $this->assertSame('Order 123 not found', R::redact('Order 123 not found'), 'an ordinary message is left alone');
    }

    public function test_a_trace_keeps_where_but_not_the_values(): void
    {
        $e = (function (string $secret) { return new \RuntimeException('x'); })('the-secret-value');
        $t = R::trace($e);
        $this->assertStringNotContainsString('the-secret-value', $t);
        $this->assertMatchesRegularExpression('/^#0 .+\(\d+\): .+\(\)$/m', $t);
    }

    public function test_the_row_for_one_occurrence(): void
    {
        $ev = R::event('error', 'PDOException', 'SQLSTATE: bad value for will@example.com', '/var/www/app/modules/x/Y.php', 12, '#0 trace()',
            ['url' => '/app/products/38/schedule?tab=…', 'method' => 'POST', 'user_id' => 7, 'runtime' => 'web'], '2026-10-17 04:30:00');
        $this->assertSame('SQLSTATE: bad value for [email]', $ev['message']);
        $this->assertSame(40, strlen($ev['fingerprint']));
        $this->assertSame(['error', 'PDOException', 12, '/app/products/38/schedule?tab=…', 'POST', 7, 'web', '2026-10-17 04:30:00'],
            [$ev['level'], $ev['class'], $ev['line'], $ev['url'], $ev['method'], $ev['user_id'], $ev['runtime'], $ev['at']]);
        $this->assertSame('error', R::event('nonsense', 'E', 'm', 'f', 1, '', ['url' => null, 'method' => null, 'user_id' => null, 'runtime' => 'cli'], 'now')['level']);
        $this->assertSame(1000, mb_strlen(R::event('error', 'E', str_repeat('a ', 900), 'f', 1, '', ['url' => null, 'method' => null, 'user_id' => null, 'runtime' => 'cli'], 'now')['message']), 'capped');
    }
}
