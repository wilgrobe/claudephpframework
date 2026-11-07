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
    // ── Only the app's own errors (Will, 7 Nov 2026: "make sure real errors still come through") ──────────

    private function tree(): array
    {
        $root = sys_get_temp_dir() . '/errrec-' . bin2hex(random_bytes(4));
        mkdir("$root/app/public", 0777, true); mkdir("$root/app/vendor/bin", 0777, true); mkdir("$root/tmp", 0777, true);
        foreach (['app/artisan', 'app/public/index.php', 'app/vendor/bin/phpunit', 'tmp/x.php'] as $p) touch("$root/$p");
        return [$root, "$root/app"];
    }

    public function test_web_requests_and_the_apps_own_commands_are_recorded(): void
    {
        [$root, $app] = $this->tree();
        $this->assertTrue(R::fromAppEntry('fpm-fcgi', '', '', $app), 'every web request');
        $this->assertTrue(R::fromAppEntry('apache2handler', "$root/tmp/x.php", "$root/tmp/x.php", $app), 'web, whatever the script');
        $this->assertTrue(R::fromAppEntry('cli', 'artisan', "$app/artisan", $app), 'php artisan … — the queue worker and cron run through it');
        $this->assertTrue(R::fromAppEntry('cli', 'vendor/bin/phpunit', "$app/vendor/bin/phpunit", $app), 'the test suite');
        $this->assertTrue(R::fromAppEntry('cli', "$root/tmp/x.php", "$root/tmp/x.php", null), 'cannot tell (no BASE_PATH) → record');
        $this->assertTrue(R::fromAppEntry('cli', 'artisan', '', $app), 'no entry file known → record');
        // The process running these tests is the app's own test suite. Other tests chdir() before this one runs — the
        // first version resolved the path against the working directory and called the suite a stray script here.
        $this->assertTrue(R::fromAppEntry(PHP_SAPI, (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), (string) (get_included_files()[0] ?? ''), BASE_PATH));
        $here = getcwd(); chdir(sys_get_temp_dir());
        try {
            $this->assertTrue(R::fromAppEntry(PHP_SAPI, (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), (string) (get_included_files()[0] ?? ''), BASE_PATH), 'still the app after a chdir');
        } finally { chdir($here); }
    }

    public function test_a_throwaway_script_outside_the_app_is_not(): void
    {
        [$root, $app] = $this->tree();
        $this->assertFalse(R::fromAppEntry('cli', "$root/tmp/x.php", "$root/tmp/x.php", $app), '/tmp/x.php that requires the bootstrap');
        $this->assertFalse(R::fromAppEntry('cli', 'Standard input code', "$app/public/index.php", $app), 'php -r, even after it required app files');
        $this->assertTrue(R::fromAppEntry('cli', '', "$app/artisan", $app), 'no script name (something cleared $_SERVER) → cannot tell → record');
        $this->assertFalse(R::fromAppEntry('cli', '-', "$app/artisan", $app), 'php reading the script from stdin');
        mkdir("$root/app-old"); touch("$root/app-old/artisan");
        $this->assertFalse(R::fromAppEntry('cli', "$root/app-old/artisan", "$root/app-old/artisan", $app), 'a sibling whose name starts the same is not inside');
    }

    public function test_a_release_run_through_a_symlink_is_the_app(): void
    {
        [$root, $app] = $this->tree();
        if (!@symlink($app, "$root/current")) $this->markTestSkipped('this machine cannot make symlinks');
        $this->assertTrue(R::fromAppEntry('cli', "$root/current/artisan", "$root/current/artisan", $app), 'managed servers: current/ → releases/<ts>/');
    }
}
