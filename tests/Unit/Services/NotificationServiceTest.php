<?php
// tests/Unit/Services/NotificationServiceTest.php
namespace Tests\Unit\Services;

use Core\Database\Database;
use Core\Services\NotificationService;
use Tests\TestCase;

/**
 * Captures-call fake for the bits of Database NotificationService touches.
 * Each call is logged so tests can assert on what the service actually did
 * (or didn't do) — the per-channel suppression is precisely about NOT
 * calling `insert` when the user opted out, so observability matters.
 */
final class FakeNotifDb extends Database
{
    /** Map "user_id|type|channel" → enabled bit. Missing = no row = default-on. */
    public array $prefs   = [];
    /** Calls to insert() so tests can assert what was written. */
    public array $inserts = [];

    public function __construct() { /* skip parent ctor + PDO connect */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        if (str_contains($sql, 'FROM notification_preferences')) {
            $key = (int) $bindings[0] . '|' . $bindings[1] . '|' . $bindings[2];
            return array_key_exists($key, $this->prefs)
                ? ['enabled' => $this->prefs[$key] ? 1 : 0]
                : null;
        }
        return null;
    }

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        // setPreferences uses INSERT ... ON DUPLICATE; capture so a test
        // can assert the bulk write happened with the right shape. We
        // satisfy the PDOStatement return-type by extending it directly
        // (no-arg ctor, none of its DB-bound methods get called) so the
        // test runs even on a PHP build without a PDO driver compiled in.
        $this->inserts[] = ['sql' => $sql, 'bindings' => $bindings];
        return new class extends \PDOStatement {
            public function __construct() {}
        };
    }
}

final class NotificationServiceTest extends TestCase
{
    private FakeNotifDb $db;
    private NotificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeNotifDb();
        $this->mockDatabase($this->db);
        $this->svc = new NotificationService();
    }

    public function test_types_catalog_has_expected_shape(): void
    {
        $this->assertNotEmpty(NotificationService::TYPES);
        foreach (NotificationService::TYPES as $key => $meta) {
            $this->assertIsString($key);
            $this->assertArrayHasKey('label',    $meta);
            $this->assertArrayHasKey('group',    $meta);
            $this->assertArrayHasKey('channels', $meta);
            $this->assertNotEmpty($meta['channels']);
            foreach ($meta['channels'] as $ch) {
                $this->assertContains($ch, ['in_app', 'email']);
            }
        }
    }

    public function test_is_allowed_defaults_to_true_when_no_pref_row(): void
    {
        $this->assertTrue($this->svc->isAllowed(1, 'social.followed', 'in_app'));
        $this->assertTrue($this->svc->isAllowed(1, 'comment.reply',   'email'));
    }

    public function test_is_allowed_returns_false_when_explicitly_disabled(): void
    {
        $this->db->prefs['1|social.followed|in_app'] = 0;
        $this->assertFalse($this->svc->isAllowed(1, 'social.followed', 'in_app'));
        // Email channel for the same type is independent — still on.
        $this->assertTrue($this->svc->isAllowed(1, 'social.followed', 'email'));
    }

    public function test_send_inserts_when_at_least_in_app_allowed(): void
    {
        $uuid = $this->svc->send(2, 'social.followed', 'Hi', 'Body', [], 'in_app,email');
        $this->assertNotSame('', $uuid);
        $this->assertCount(1, $this->db->inserts);
        $row = $this->db->inserts[0];
        $this->assertSame('notifications', $row['table']);
        $this->assertSame(2, $row['data']['user_id']);
        $this->assertSame('social.followed', $row['data']['type']);
    }

    public function test_send_skips_insert_when_in_app_disabled(): void
    {
        $this->db->prefs['3|social.followed|in_app'] = 0;
        $uuid = $this->svc->send(3, 'social.followed', 'Hi', 'Body', [], 'in_app');
        $this->assertSame('', $uuid);
        $this->assertCount(0, $this->db->inserts,
            'When the only requested channel is disabled, no row is inserted.');
    }

    public function test_send_skips_when_email_only_requested_but_no_in_app_in_allowed(): void
    {
        // in_app NOT in the channels CSV → no row inserted because the
        // notifications table only writes for in_app.
        $uuid = $this->svc->send(4, 'social.followed', 'Hi', 'Body', [], 'email');
        $this->assertSame('', $uuid);
        $this->assertCount(0, $this->db->inserts);
    }

    public function test_set_preferences_writes_one_upsert_per_type_channel(): void
    {
        $this->svc->setPreferences(7, [
            'social.followed' => ['in_app' => true,  'email' => false],
            'comment.reply'   => ['in_app' => false, 'email' => true],
        ]);
        // Should have one query call per (type, channel) listed in TYPES.
        $expected = 0;
        foreach (NotificationService::TYPES as $meta) {
            $expected += count($meta['channels']);
        }
        $this->assertCount($expected, $this->db->inserts);
        // Every captured call should be the upsert SQL with 4 bindings.
        foreach ($this->db->inserts as $row) {
            $this->assertArrayHasKey('sql', $row);
            $this->assertStringContainsString('INSERT INTO notification_preferences', $row['sql']);
            $this->assertCount(4, $row['bindings']);
            $this->assertSame(7, $row['bindings'][0]);
        }
    }

    public function test_preferences_for_returns_full_grid_with_defaults(): void
    {
        $this->db->prefs['9|social.followed|email'] = 0;
        $prefs = $this->svc->preferencesFor(9);

        $this->assertArrayHasKey('social.followed', $prefs);
        $this->assertTrue($prefs['social.followed']['in_app']);
        $this->assertFalse($prefs['social.followed']['email']);
        // Other types retain their default-on state.
        $this->assertTrue($prefs['comment.reply']['in_app']);
    }
}
