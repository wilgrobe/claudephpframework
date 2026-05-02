<?php
// tests/Unit/Services/SmsServiceSecurityTest.php
namespace Tests\Unit\Services;

use Core\Database\Database;
use Core\Services\SmsService;
use Tests\TestCase;

/**
 * Covers the production-log-driver guard added to SmsService 2026-04-22.
 * Paired with WebhookServiceSecurityTest — the pattern is identical.
 */
final class SmsServiceSecurityTest extends TestCase
{
    private function stubDb(): void
    {
        $mock = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->mockDatabase($mock);
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV'], $_ENV['SMS_DRIVER']);
        parent::tearDown();
    }

    public function test_log_driver_refused_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']    = 'production';
        $_ENV['SMS_DRIVER'] = 'log';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SMS_DRIVER");
        new SmsService();
    }

    public function test_log_driver_allowed_in_dev(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']    = 'development';
        $_ENV['SMS_DRIVER'] = 'log';

        $svc = new SmsService();
        $this->assertInstanceOf(SmsService::class, $svc);
    }

    public function test_none_driver_allowed_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']    = 'production';
        $_ENV['SMS_DRIVER'] = 'none';

        $svc = new SmsService();
        $this->assertInstanceOf(SmsService::class, $svc);
    }

    public function test_auto_driver_does_not_throw_in_production(): void
    {
        $this->stubDb();
        $_ENV['APP_ENV']    = 'production';
        $_ENV['SMS_DRIVER'] = 'auto';

        // auto → 'none' in production (when no provider creds), so no throw.
        $svc = new SmsService();
        $this->assertInstanceOf(SmsService::class, $svc);
    }
}
