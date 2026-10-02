<?php
// tests/Unit/Auth/InstallAccountsTest.php
namespace Tests\Unit\Auth;

use Core\Auth\InstallAccounts;
use Tests\TestCase;

/**
 * A fresh install never creates an account with a known password outside
 * development. Until 2026-10-02 the seed migration gave admin@ / editor@ /
 * viewer@example.com the published "Admin@123" on every install, so any app
 * deployed as shipped had a super-admin anyone could sign in as.
 */
final class InstallAccountsTest extends TestCase
{
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv === null) { unset($_ENV['APP_ENV']); } else { $_ENV['APP_ENV'] = $this->savedEnv; }
        parent::tearDown();
    }

    public function test_only_an_explicit_development_env_counts_as_development(): void
    {
        foreach (['local', 'development', 'dev', 'testing', ' Local '] as $e) {
            $_ENV['APP_ENV'] = $e;
            $this->assertTrue(InstallAccounts::isDevelopment(), "'$e' should be development");
        }
        foreach (['production', 'prod', 'staging', ''] as $e) {
            $_ENV['APP_ENV'] = $e;
            $this->assertFalse(InstallAccounts::isDevelopment(), "'$e' must not be development");
        }
    }

    public function test_random_passwords_are_long_varied_and_unambiguous(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $p = InstallAccounts::randomPassword();
            $this->assertSame(20, strlen($p));
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $p, 'look-alike characters');
            $seen[$p] = true;
        }
        $this->assertCount(50, $seen, 'passwords repeated');
        $this->assertFalse(password_verify('Admin@123', password_hash(InstallAccounts::randomPassword(), PASSWORD_DEFAULT)));
    }

    /** The known hash may exist only behind the development switch — never as a literal in the seed. */
    public function test_no_seed_migration_carries_a_password_hash_literal(): void
    {
        $found = [];
        foreach (glob(BASE_PATH . '/database/migrations/*.php') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('/\$2y\$\d\d\$[.\/A-Za-z0-9]{53}/', $src)) { $found[] = basename($f); }
        }
        $this->assertSame([], $found, 'A migration seeds a fixed password hash.');
        $seed = (string) file_get_contents(BASE_PATH . '/database/migrations/0500_framework_data.php');
        $this->assertStringContainsString('InstallAccounts::isDevelopment()', $seed);
        $this->assertStringContainsString("PHP_SAPI === 'cli'", $seed, 'The password must never be echoed into a web response.');
        $this->assertTrue(password_verify('Admin@123', InstallAccounts::DEV_PASSWORD_HASH), 'dev hash changed — update the README');
    }
}
