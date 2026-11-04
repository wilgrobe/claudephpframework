<?php
// tests/Unit/Security/SecuritySweep466Test.php
namespace Tests\Unit\Security;

use App\Controllers\UserController;
use Core\Auth\Auth;
use Core\Request;
use Modules\Email\Controllers\WebhookController;
use Tests\TestCase;

/**
 * Security sweep #466 (4 Nov 2026) — the rules behind each fix, so a merge or a rewrite that drops one fails here.
 */
final class SecuritySweep466Test extends TestCase
{
    private array $envBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // ── 1. an admin cannot grant or strip the super-admin role ─────────

    public function test_without_sa_mode_the_super_admin_role_is_dropped_from_what_is_posted(): void
    {
        $this->assertSame([3, 5], UserController::allowedRoleIds(['3', '1', '5', '', '0', '3'], [], 1, false));
    }

    public function test_without_sa_mode_an_existing_super_admin_role_is_kept(): void
    {
        $this->assertSame([3, 1], UserController::allowedRoleIds(['3'], [1, 3], 1, false), 'an admin must not be able to strip it either');
    }

    public function test_in_sa_mode_roles_are_set_exactly_as_posted(): void
    {
        $this->assertSame([1, 3], UserController::allowedRoleIds(['1', '3'], [], 1, true));
        $this->assertSame([3], UserController::allowedRoleIds(['3'], [1, 3], 1, true), 'a System Admin may remove it');
    }

    // ── 3. the passwordless dev login is an allow-list, off by default ─

    public function test_dev_login_needs_a_development_environment_and_the_flag(): void
    {
        foreach (['local', 'development', 'dev', 'testing', 'LOCAL '] as $env) {
            $_ENV['APP_ENV'] = $env; $_ENV['APP_DEV_LOGIN'] = '1';
            $this->assertTrue(Auth::devLoginAllowed(), $env);
        }
        foreach (['production', 'staging', 'prod', 'live', 'demo', ''] as $env) {
            $_ENV['APP_ENV'] = $env; $_ENV['APP_DEV_LOGIN'] = '1';
            $this->assertFalse(Auth::devLoginAllowed(), "APP_ENV='$env' must not allow the dev login");
        }
    }

    public function test_dev_login_is_off_unless_the_flag_is_set(): void
    {
        $_ENV['APP_ENV'] = 'local';
        unset($_ENV['APP_DEV_LOGIN']);
        $this->assertFalse(Auth::devLoginAllowed(), 'the flag defaulted ON before #466');
        $_ENV['APP_DEV_LOGIN'] = '0';
        $this->assertFalse(Auth::devLoginAllowed());
        unset($_ENV['APP_ENV']);
        $_ENV['APP_DEV_LOGIN'] = '1';
        $this->assertFalse(Auth::devLoginAllowed(), 'no APP_ENV is production');
    }

    // ── 4. email webhooks fail closed outside development ──────────────

    private function secretCheck(array $server): bool
    {
        $_SERVER = $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/webhooks/email/sendgrid'];
        $m = new \ReflectionMethod(WebhookController::class, 'verifySharedSecret');
        return $m->invoke((new \ReflectionClass(WebhookController::class))->newInstanceWithoutConstructor(), Request::capture());
    }

    public function test_an_unset_webhook_secret_rejects_everywhere_but_development(): void
    {
        $_ENV['MAIL_WEBHOOK_SECRET'] = '';
        foreach (['production', 'staging', 'live', 'prod'] as $env) {
            $_ENV['APP_ENV'] = $env;
            $this->assertFalse($this->secretCheck([]), "an unset secret must reject on '$env'");
        }
        $_ENV['APP_ENV'] = 'local';
        $this->assertTrue(@$this->secretCheck([]), 'local development may run without one');
    }

    public function test_a_set_webhook_secret_must_match(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['MAIL_WEBHOOK_SECRET'] = 's3cret-value';
        $this->assertFalse($this->secretCheck([]));
        $this->assertFalse($this->secretCheck(['HTTP_X_WEBHOOK_SECRET' => 'wrong']));
        $this->assertTrue($this->secretCheck(['HTTP_X_WEBHOOK_SECRET' => 's3cret-value']));
        $this->assertTrue($this->secretCheck(['HTTP_AUTHORIZATION' => 'Bearer s3cret-value']));
    }

    // ── 5. user search hands out masked emails ─────────────────────────

    public function test_emails_are_masked(): void
    {
        $this->assertSame('w•••@gmail.com', mask_email('wilgrobe@gmail.com'));
        $this->assertSame('a•••@x.io', mask_email('a@x.io'));
        $this->assertSame('•••', mask_email('not-an-email'));
        $this->assertSame('•••', mask_email('@x.io'));
    }

    // ── XSS: hrefs from users or admins ────────────────────────────────

    public function test_safe_href_refuses_script_schemes_however_they_are_spelled(): void
    {
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', " javascript:alert(1)", "java\tscript:alert(1)", "java\nscript:x",
                  "\x01javascript:x", 'data:text/html,<script>x</script>', 'vbscript:x', 'file:///etc/passwd'] as $bad) {
            $this->assertNull(safe_href($bad), json_encode($bad));
        }
    }

    public function test_safe_href_keeps_ordinary_links(): void
    {
        foreach (['https://example.com/a?b=c', 'http://x.test', '/about', '#top', '?page=2', 'contact', 'mailto:a@b.c', 'tel:+18015551212',
                  '//cdn.example.com/x', '/path:with-colon'] as $ok) {
            $this->assertSame($ok, safe_href($ok), $ok);
        }
        $this->assertNull(safe_href(''));
        $this->assertNull(safe_href(null));
    }

    // ── Host header: links only to hosts the site really serves ────────

    public function test_trusted_host_accepts_app_url_and_listed_hosts_only(): void
    {
        $_ENV['APP_URL'] = 'https://www.example.com';
        $_ENV['APP_TRUSTED_HOSTS'] = 'example.com, *.tenants.example.com';
        $this->assertSame('www.example.com', trusted_host('www.example.com'));
        $this->assertSame('www.example.com:8443', trusted_host('WWW.Example.com:8443'));
        $this->assertSame('example.com', trusted_host('example.com'));
        $this->assertSame('acme.tenants.example.com', trusted_host('acme.tenants.example.com'));
        $this->assertNull(trusted_host('evil.example'), 'a spoofed Host header');
        $this->assertNull(trusted_host('tenants.example.com.evil.example'));
        $this->assertNull(trusted_host('www.example.com@evil.example'));
        $this->assertNull(trusted_host(''));
    }

    public function test_site_base_url_falls_back_to_app_url_for_a_spoofed_host(): void
    {
        $_ENV['APP_URL'] = 'https://www.example.com';
        $_ENV['APP_TRUSTED_HOSTS'] = '';
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame(rtrim((string) config('app.url', ''), '/'), site_base_url());
        $this->assertStringNotContainsString('evil.example', site_base_url());
        $_SERVER['HTTP_HOST'] = 'www.example.com';
        $this->assertSame('https://www.example.com', site_base_url());
    }

    // ── open redirects ──────────────────────────────────────────────────

    public function test_safe_redirect_refuses_backslash_and_control_characters(): void
    {
        foreach (['/\\evil.com', '/\\/evil.com', "/a\r\nLocation: http://evil.com", "/\tevil", '//evil.com', 'https://evil.com'] as $bad) {
            $this->assertSame('/dashboard', Auth::safeRedirect($bad), json_encode($bad));
        }
        $this->assertSame('/groups/5?tab=members', Auth::safeRedirect('/groups/5?tab=members'));
    }
}
