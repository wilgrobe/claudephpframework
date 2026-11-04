<?php
// tests/Unit/Services/CacheTenantIsolationTest.php
namespace Tests\Unit\Services;

use Core\Services\CacheService;
use Tests\TestCase;

/**
 * #466 (4 Nov 2026): a multi-tenant host runs one database per tenant behind one storage/cache folder. Cache keys
 * carried no tenant part (the file driver hashed the bare key), so the audit log's cached "top actions" and pager
 * total computed for one tenant were shown to every other. Keys are namespaced by database now.
 */
final class CacheTenantIsolationTest extends TestCase
{
    private string $dir;
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CACHE_DRIVER', 'STORAGE_PATH', 'DB_DATABASE', 'APP_NAME'] as $k) $this->env[$k] = $_ENV[$k] ?? null;
        $this->dir = sys_get_temp_dir() . '/cache466-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $_ENV['CACHE_DRIVER'] = 'file';
        $_ENV['STORAGE_PATH'] = $this->dir;
        $_ENV['APP_NAME'] = 'Same App';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/cache/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir . '/cache'); @rmdir($this->dir);
        foreach ($this->env as $k => $v) { if ($v === null) unset($_ENV[$k]); else $_ENV[$k] = $v; }
        parent::tearDown();
    }

    public function test_one_tenants_cached_value_is_invisible_to_another(): void
    {
        $cache = new CacheService();
        $this->assertSame('file', $cache->driver());

        $_ENV['DB_DATABASE'] = 'tenant_alpha';
        $cache->set('auditlog.top_actions.10', ['alpha.secret_action' => 9], 600);
        $this->assertSame(['alpha.secret_action' => 9], $cache->get('auditlog.top_actions.10'));

        $_ENV['DB_DATABASE'] = 'tenant_beta';
        $this->assertNull($cache->get('auditlog.top_actions.10'), "tenant_beta read tenant_alpha's cached value");
        $calls = 0;
        $beta = $cache->remember('auditlog.top_actions.10', 600, function () use (&$calls) { $calls++; return ['beta.own' => 1]; });
        $this->assertSame([['beta.own' => 1], 1], [$beta, $calls], 'remember() must compute for the second tenant, not reuse the first');

        $_ENV['DB_DATABASE'] = 'tenant_alpha';
        $this->assertSame(['alpha.secret_action' => 9], $cache->get('auditlog.top_actions.10'), 'and each keeps its own');
        $cache->forget('auditlog.top_actions.10');
        $_ENV['DB_DATABASE'] = 'tenant_beta';
        $this->assertSame(['beta.own' => 1], $cache->get('auditlog.top_actions.10'), "forget() for one tenant must not drop another's");
    }
}
