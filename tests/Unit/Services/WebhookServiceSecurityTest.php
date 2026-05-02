<?php
// tests/Unit/Services/WebhookServiceSecurityTest.php
namespace Tests\Unit\Services;

use Core\Database\Database;
use Core\Services\WebhookService;
use Tests\TestCase;

/**
 * Tests the two security hardenings added 2026-04-22:
 *   - Refuse log/capture driver in production (prevents accidental
 *     prod misconfiguration from leaking request bodies to the PHP log).
 *   - Private-IP deny-list in urlIsPublic() (prevents SSRF via
 *     user-controlled webhook URLs).
 */
final class WebhookServiceSecurityTest extends TestCase
{
    /** Installed before each test so WebhookService ctor doesn't open a DB connection. */
    private function stubDb(): void
    {
        $mock = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->mockDatabase($mock);
    }

    protected function tearDown(): void
    {
        // Keep $_ENV clean between tests so one test's APP_ENV/WEBHOOK_DRIVER
        // setting can't poison the next.
        unset($_ENV['APP_ENV'], $_ENV['WEBHOOK_DRIVER'], $_ENV['WEBHOOK_ALLOW_PRIVATE']);
        parent::tearDown();
    }

    // ── Production-log-driver guard ───────────────────────────────────────

    public function test_log_driver_refused_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']         = 'production';
        $_ENV['WEBHOOK_DRIVER']  = 'log';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("driver 'log' is refused in production");
        new WebhookService();
    }

    public function test_capture_driver_refused_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']        = 'production';
        $_ENV['WEBHOOK_DRIVER'] = 'capture';

        $this->expectException(\RuntimeException::class);
        new WebhookService();
    }

    public function test_log_driver_allowed_in_dev(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']        = 'development';
        $_ENV['WEBHOOK_DRIVER'] = 'log';

        $svc = new WebhookService();
        $this->assertInstanceOf(WebhookService::class, $svc);
    }

    public function test_http_driver_allowed_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']        = 'production';
        $_ENV['WEBHOOK_DRIVER'] = 'http';

        $svc = new WebhookService();
        $this->assertInstanceOf(WebhookService::class, $svc);
    }

    public function test_auto_driver_picks_http_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']        = 'production';
        $_ENV['WEBHOOK_DRIVER'] = 'auto';

        // auto → http in production, so construction should NOT throw.
        $svc = new WebhookService();
        $this->assertInstanceOf(WebhookService::class, $svc);
    }

    // ── SSRF / urlIsPublic() ──────────────────────────────────────────────

    /** Exercise the private urlIsPublic() via reflection for targeted coverage. */
    private function callUrlIsPublic(WebhookService $svc, string $url): bool
    {
        $m = new \ReflectionMethod($svc, 'urlIsPublic');
        return (bool) $m->invoke($svc, $url);
    }

    private function newDevService(): WebhookService
    {
        $this->stubDb();
        $_ENV['APP_ENV']        = 'development';
        $_ENV['WEBHOOK_DRIVER'] = 'http';
        return new WebhookService();
    }

    public function test_url_rejects_non_http_schemes(): void
    {
        $svc = $this->newDevService();

        $this->assertFalse($this->callUrlIsPublic($svc, 'file:///etc/passwd'));
        $this->assertFalse($this->callUrlIsPublic($svc, 'ftp://example.com/x'));
        $this->assertFalse($this->callUrlIsPublic($svc, 'gopher://example.com/'));
        $this->assertFalse($this->callUrlIsPublic($svc, 'not-a-url'));
        $this->assertFalse($this->callUrlIsPublic($svc, ''));
    }

    public function test_url_rejects_literal_loopback_and_private_ips(): void
    {
        $svc = $this->newDevService();

        // IPv4 private + loopback + link-local.
        foreach ([
            'http://127.0.0.1/',
            'http://127.0.0.1:8080/admin',
            'http://10.0.0.1/',
            'http://192.168.1.5/',
            'http://172.16.0.1/',
            'http://169.254.169.254/latest/meta-data/',  // AWS IMDS — the classic
            'http://0.0.0.0/',
        ] as $url) {
            $this->assertFalse(
                $this->callUrlIsPublic($svc, $url),
                "Should reject private/reserved URL: $url"
            );
        }
    }

    public function test_url_rejects_ipv6_loopback_and_link_local(): void
    {
        $svc = $this->newDevService();

        $this->assertFalse($this->callUrlIsPublic($svc, 'http://[::1]/'));
        $this->assertFalse($this->callUrlIsPublic($svc, 'http://[fe80::1]/'));
        // fc00::/7 (unique local) — private in IPv6.
        $this->assertFalse($this->callUrlIsPublic($svc, 'http://[fd00::1]/'));
    }

    public function test_url_allows_literal_public_ip(): void
    {
        $svc = $this->newDevService();

        // 8.8.8.8 and 1.1.1.1 are public anycast DNS — stable targets for this test.
        $this->assertTrue($this->callUrlIsPublic($svc, 'http://8.8.8.8/'));
        $this->assertTrue($this->callUrlIsPublic($svc, 'https://1.1.1.1/'));
    }

    public function test_allow_private_bypass_lets_internal_urls_through(): void
    {
        $svc = $this->newDevService();
        $_ENV['WEBHOOK_ALLOW_PRIVATE'] = '1';

        // With the bypass set, even blatantly internal URLs pass — this is
        // the intended hatch for docker-compose dev setups. Production
        // should never set this; the log-driver guard is the secondary fence
        // but this test just verifies the bypass flag actually works.
        $this->assertTrue($this->callUrlIsPublic($svc, 'http://127.0.0.1:9000/'));
        $this->assertTrue($this->callUrlIsPublic($svc, 'http://mailhog:1025/'));
    }
}
