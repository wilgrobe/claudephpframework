<?php
// tests/Unit/Modules/Social/FollowServiceTest.php
namespace Tests\Unit\Modules\Social;

use Core\Database\Database;
use Modules\Social\Services\FollowService;
use Tests\TestCase;

/**
 * FollowService::follow now dispatches a notification on a NEW insert
 * (rowCount > 0) and silently no-ops on a duplicate (rowCount === 0).
 *
 * We can't return a real PDOStatement from the fake DB without depending
 * on a PDO driver (sqlite isn't always compiled in). Instead we return
 * a small anonymous-class stub that exposes just the rowCount() method
 * FollowService consults. PHP doesn't enforce the return-type covariance
 * at the fake-method level because we override Database::query() — its
 * return type is ?PDOStatement, so we satisfy that by extending PDOStatement
 * (which is allowed because PDOStatement has a no-arg constructor and we
 * never use any of its real DB-bound methods).
 */
final class FakeStmt extends \PDOStatement
{
    public int $rowCount = 0;
    public function rowCount(): int { return $this->rowCount; }
}

final class FakeFollowDb extends Database
{
    /** rowCount the next query() call should report. */
    public int $nextRowCount = 1;
    /** Notifications inserted via NotificationService. */
    public array $notifInserts = [];
    /** Users keyed by id for the followed-user lookup. */
    public array $users = [
        100 => ['id' => 100, 'username' => 'alice', 'first_name' => 'Alice', 'last_name' => 'A'],
        101 => ['id' => 101, 'username' => 'bob',   'first_name' => 'Bob',   'last_name' => 'B'],
    ];

    public function __construct() { /* skip parent */ }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = new FakeStmt();
        $stmt->rowCount = $this->nextRowCount;
        return $stmt;
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // FollowService::notifyNewFollower looks up the FOLLOWER (the one
        // who initiated the follow) by id to build the notification title.
        if (str_contains($sql, 'FROM users WHERE id = ?')) {
            $id = (int) ($bindings[0] ?? 0);
            return $this->users[$id] ?? null;
        }
        // NotificationService::isAllowed (no row → default-on)
        if (str_contains($sql, 'FROM notification_preferences')) return null;
        return null;
    }

    public function insert(string $table, array $data): int
    {
        if ($table === 'notifications') {
            $this->notifInserts[] = $data;
        }
        return 1;
    }
}

final class FollowServiceTest extends TestCase
{
    private FakeFollowDb $db;
    private FollowService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeFollowDb();
        $this->mockDatabase($this->db);
        $this->svc = new FollowService();
    }

    public function test_follow_self_is_noop(): void
    {
        $this->assertFalse($this->svc->follow(100, 100));
        $this->assertCount(0, $this->db->notifInserts);
    }

    public function test_follow_new_dispatches_notification(): void
    {
        $this->db->nextRowCount = 1; // INSERT IGNORE actually inserted.
        $ok = $this->svc->follow(100, 101); // Alice follows Bob
        $this->assertTrue($ok);
        $this->assertCount(1, $this->db->notifInserts);
        $row = $this->db->notifInserts[0];
        $this->assertSame(101, $row['user_id'],   'Bob (the followed) is the recipient.');
        $this->assertSame('social.followed', $row['type']);
        $this->assertStringContainsString('@alice', $row['title']);
    }

    public function test_follow_duplicate_does_not_renotify(): void
    {
        $this->db->nextRowCount = 0; // INSERT IGNORE hit duplicate-key.
        $ok = $this->svc->follow(100, 101);
        $this->assertTrue($ok, 'Returns true even on duplicate to keep the contract idempotent.');
        $this->assertCount(0, $this->db->notifInserts,
            'A re-follow must not spam the followee with a duplicate notification.');
    }
}
