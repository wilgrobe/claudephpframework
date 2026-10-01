<?php
// tests/Unit/Services/SessionCleanupBatchTest.php
namespace Tests\Unit\Services;

use Core\Database\Database;
use Core\Services\SessionCleanupService;
use Tests\TestCase;

/**
 * The session purge removes every idle session — in batches — and nothing live.
 *
 * The cleanup command had never been scheduled on the Builder box; 157,348
 * sessions had piled up. Runs against the real sessions table inside a
 * transaction that is rolled back.
 */
final class SessionCleanupBatchTest extends TestCase
{
    private ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (!empty($_ENV['DB_DATABASE'])) { return; }
        $loader = BASE_PATH . '/core/env_loader.php';
        if (is_file($loader) && is_file(BASE_PATH . '/.env')) {
            require_once $loader;
            if (function_exists('claude_load_env')) { claude_load_env(BASE_PATH . '/.env'); }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->pdo = Database::getInstance()->pdo();
            $this->pdo->query('SELECT 1 FROM sessions LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database with a sessions table: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        parent::tearDown();
    }

    public function test_more_than_two_batches_of_idle_sessions_all_go_and_live_ones_stay(): void
    {
        $lifetime = (int) config('app.session.lifetime', 120) * 60;
        $staleBefore = (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL $lifetime SECOND)")->fetchColumn();

        $stale = SessionCleanupService::SESSION_BATCH * 2 + 37;   // forces three DELETEs, the last one short
        $tag = 'cleanup-test-' . bin2hex(random_bytes(4));
        $rows = [];
        for ($i = 0; $i < $stale; $i++) { $rows[] = "('{$tag}-s{$i}', NULL, '', '', DATE_SUB(NOW(), INTERVAL 3 HOUR))"; }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $this->pdo->exec('INSERT INTO sessions (id, user_id, user_agent, payload, last_activity) VALUES ' . implode(',', $chunk));
        }
        $this->pdo->exec("INSERT INTO sessions (id, user_id, user_agent, payload, last_activity) VALUES
            ('{$tag}-live1', NULL, '', '', NOW()), ('{$tag}-live2', NULL, '', '', DATE_SUB(NOW(), INTERVAL 10 MINUTE))");

        $res = (new SessionCleanupService())->run();

        $this->assertSame($staleBefore + $stale, $res['sessions_purged'], 'Not every idle session was purged — the batch loop stopped early.');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE '{$tag}-s%'")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE '{$tag}-live%'")->fetchColumn(),
            'A live session was purged.');
    }
}
