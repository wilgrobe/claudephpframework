<?php
// tests/Unit/Modules/Comments/CommentServiceReplyNotifyTest.php
namespace Tests\Unit\Modules\Comments;

use Core\Database\Database;
use Modules\Comments\Services\CommentService;
use Tests\TestCase;

/**
 * CommentService::create now fires a per-reply notification to the parent
 * commenter when (a) the new comment is a reply (parentId provided), (b)
 * the parent is published, (c) the new comment is itself published, and
 * (d) the replier isn't the parent's author (no self-pings).
 *
 * Tested through the same Database mock that backs both CommentService
 * and the NotificationService it instantiates internally.
 */
final class FakeReplyDb extends Database
{
    public array $parentRows  = []; // id => parent comment row
    public array $inserts     = [];
    public array $userRows    = [];
    public string $nextStatus = 'published';

    public function __construct() { /* skip parent */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // CommentService::findById for the parent.
        if (str_contains($sql, 'FROM comments') && str_contains($sql, 'id = ?')) {
            $id = (int) ($bindings[0] ?? 0);
            return $this->parentRows[$id] ?? null;
        }
        // CommentService::notifyReplyToParent looks up the replier user.
        if (str_contains($sql, 'FROM users WHERE id = ?')) {
            $id = (int) ($bindings[0] ?? 0);
            return $this->userRows[$id] ?? null;
        }
        // NotificationService::isAllowed (no row → default-on)
        if (str_contains($sql, 'FROM notification_preferences')) {
            return null;
        }
        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        // SettingService::warmBucket queries `settings` to prime its cache.
        // Returning [] silences the "$pdo not initialized" error_log noise
        // that bleeds into PHPUnit's reporter when warmBucket falls through
        // to the real PDO path on the un-initialized fake.
        return [];
    }

    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed
    {
        return 0; // no moderation requirements, no settings rows
    }

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        // Comments table: return the new id; notifications: return 1.
        return count(array_filter($this->inserts, fn($r) => $r['table'] === 'comments'));
    }
}

final class CommentServiceReplyNotifyTest extends TestCase
{
    private FakeReplyDb $db;
    private CommentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeReplyDb();
        $this->db->userRows[200] = ['id' => 200, 'username' => 'alice', 'first_name' => 'Alice', 'last_name' => 'A'];
        $this->db->userRows[201] = ['id' => 201, 'username' => 'bob',   'first_name' => 'Bob',   'last_name' => 'B'];
        $this->mockDatabase($this->db);
        // CommentService's constructor — uses Database::getInstance + a
        // SettingService that itself reads from DB. We rely on the fake
        // returning 0/null from fetchColumn so initial-status defaults
        // to 'published' (no moderation queue gating).
        $this->svc = new CommentService();
    }

    /** Build a published parent comment owned by Bob (id=201). */
    private function makeParent(int $id, int $userId = 201): void
    {
        $this->db->parentRows[$id] = [
            'id'               => $id,
            'commentable_type' => 'social_post',
            'commentable_id'   => 999,
            'user_id'          => $userId,
            'parent_id'        => null,
            'status'           => 'published',
            'body'             => 'parent body',
            'created_at'       => '2026-05-01 00:00:00',
        ];
    }

    private function notifInsertsOnly(): array
    {
        return array_values(array_filter($this->db->inserts, fn($r) => $r['table'] === 'notifications'));
    }

    public function test_reply_to_someone_else_dispatches_notification(): void
    {
        $this->makeParent(50, /*owner=*/ 201); // Bob
        // Alice (200) replies to Bob's comment (parent 50).
        $this->svc->create('social_post', 999, 200, 'Great point!', 50);
        $notifs = $this->notifInsertsOnly();
        $this->assertCount(1, $notifs);
        $this->assertSame(201, $notifs[0]['data']['user_id'],
            'Bob (the parent author) is the notification recipient.');
        $this->assertSame('comment.reply', $notifs[0]['data']['type']);
        $this->assertStringContainsString('@alice', $notifs[0]['data']['title']);
    }

    public function test_self_reply_does_not_notify(): void
    {
        $this->makeParent(60, /*owner=*/ 200); // Alice's comment
        // Alice replies to her own comment — no self-ping.
        $this->svc->create('social_post', 999, 200, 'Adding more.', 60);
        $this->assertCount(0, $this->notifInsertsOnly(),
            'Replying to your own comment must not generate a notification.');
    }

    public function test_top_level_comment_does_not_notify(): void
    {
        // No parentId → not a reply → no notification.
        $this->svc->create('social_post', 999, 200, 'First!', null);
        $this->assertCount(0, $this->notifInsertsOnly());
    }
}
