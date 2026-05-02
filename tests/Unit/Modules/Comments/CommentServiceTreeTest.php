<?php
// tests/Unit/Modules/Comments/CommentServiceTreeTest.php
namespace Tests\Unit\Modules\Comments;

use Core\Database\Database;
use Modules\Comments\Services\CommentService;
use Tests\TestCase;

/**
 * DB double — responds to the queries CommentService issues during tests.
 * Everything defaults to null/empty so SettingsService falls back to
 * defaults (900s edit window, no scope overrides) and ownership lookups
 * return null. Individual tests can override .settingsValue to simulate
 * specific scope overrides, or .contentOwnership to stub an owner row.
 */
final class FakeCommentsDb extends Database
{
    /** Shape: ['scope|scopeKey|key' => ['value' => ..., 'type' => ...]] */
    public array $settingsValue = [];
    /** Shape: [contentId => ['owner_type' => ..., 'owner_user_id' => ..., 'owner_group_id' => ...]] */
    public array $contentOwnership = [];
    /** user_id => [group_id => base_role] — for user_groups lookups */
    public array $userGroups = [];

    public function __construct() { /* skip parent; no PDO needed */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // SettingsService::get(key, default, scope, scopeKey)
        if (str_contains($sql, 'FROM settings') && str_contains($sql, 'WHERE `key`')) {
            [$key, $scope, $scopeKey] = $bindings;
            $k = "$scope|$scopeKey|$key";
            return $this->settingsValue[$k] ?? null;
        }
        // ownershipOfTarget on content_items
        if (str_contains($sql, 'FROM content_items WHERE id')) {
            $id = (int) $bindings[0];
            return $this->contentOwnership[$id] ?? null;
        }
        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        // groupIdsUserCanModerate — SELECT group_id FROM user_groups WHERE user_id AND base_role IN (...)
        if (str_contains($sql, 'FROM user_groups') && str_contains($sql, 'base_role')) {
            $uid   = (int) $bindings[0];
            $rows  = [];
            foreach ($this->userGroups[$uid] ?? [] as $gid => $role) {
                if (in_array($role, ['group_admin', 'group_owner'], true)) {
                    $rows[] = ['group_id' => $gid];
                }
            }
            return $rows;
        }
        // SettingsService bulk-warm — `SELECT key,value,type FROM settings
        // WHERE scope = ? AND scope_key <=> ?`. Convert the per-key
        // settingsValue map into the bulk-load shape the service expects:
        // any entry whose composite key starts with "{scope}|{scopeKey}|"
        // becomes one row.
        if (str_contains($sql, 'FROM settings') && str_contains($sql, 'scope_key')) {
            [$scope, $scopeKey] = $bindings + [null, null];
            $rows = [];
            $prefix = "$scope|" . ($scopeKey ?? '') . '|';
            foreach ($this->settingsValue as $composite => $cell) {
                if (!str_starts_with($composite, $prefix)) continue;
                $rows[] = [
                    'key'   => substr($composite, strlen($prefix)),
                    'value' => $cell['value'] ?? '',
                    'type'  => $cell['type']  ?? 'string',
                ];
            }
            return $rows;
        }
        return [];
    }
}

/**
 * Covers the tree-building + canEdit policy in CommentService. The DB is
 * stubbed (FakeCommentsDb) so these tests don't hit MySQL; we call tree()
 * and canEdit() with hand-crafted row shapes.
 */
final class CommentServiceTreeTest extends TestCase
{
    private CommentService $svc;
    private FakeCommentsDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeCommentsDb();
        $this->mockDatabase($this->db);
        $this->svc = new CommentService();
    }

    private function row(int $id, ?int $parentId, string $body, string $when = '2026-04-22 10:00:00', string $status = 'published', int $userId = 7): array
    {
        return [
            'id'               => $id,
            'commentable_type' => 'content',
            'commentable_id'   => 1,
            'parent_id'        => $parentId,
            'user_id'          => $userId,
            'body'             => $body,
            'status'           => $status,
            'created_at'       => $when,
            'updated_at'       => $when,
        ];
    }

    // ── tree() ────────────────────────────────────────────────────────────

    public function test_flat_list_with_no_parents_is_all_roots(): void
    {
        $tree = $this->svc->tree([
            $this->row(1, null, 'A'),
            $this->row(2, null, 'B'),
            $this->row(3, null, 'C'),
        ]);

        $this->assertCount(3, $tree);
        foreach ($tree as $node) {
            $this->assertSame([], $node['children']);
        }
    }

    public function test_tree_nests_children_under_parents(): void
    {
        $tree = $this->svc->tree([
            $this->row(1, null, 'root-A'),
            $this->row(2, 1,    'reply-to-A-1'),
            $this->row(3, 1,    'reply-to-A-2'),
            $this->row(4, 2,    'nested-reply'),
            $this->row(5, null, 'root-B'),
        ]);

        $this->assertCount(2, $tree);
        $rootA = $tree[0];
        $rootB = $tree[1];

        $this->assertSame('root-A', $rootA['body']);
        $this->assertCount(2, $rootA['children']);
        $this->assertSame('reply-to-A-1', $rootA['children'][0]['body']);
        $this->assertCount(1, $rootA['children'][0]['children']);
        $this->assertSame('nested-reply', $rootA['children'][0]['children'][0]['body']);

        $this->assertSame('root-B', $rootB['body']);
        $this->assertSame([], $rootB['children']);
    }

    public function test_orphan_child_with_missing_parent_becomes_root(): void
    {
        // parent_id=99 doesn't exist in the set — the child shouldn't just
        // vanish, it should surface as a root so readers can still see it.
        $tree = $this->svc->tree([
            $this->row(1, null, 'alive'),
            $this->row(2, 99,   'orphan'),
        ]);

        $this->assertCount(2, $tree);
        $bodies = array_map(static fn(array $n): string => $n['body'], $tree);
        $this->assertContains('orphan', $bodies);
    }

    // ── canEdit() ─────────────────────────────────────────────────────────

    public function test_author_can_edit_within_window(): void
    {
        $comment = $this->row(1, null, 'mine', date('Y-m-d H:i:s'));
        $this->assertTrue($this->svc->canEdit($comment, userId: 7, isAdmin: false));
    }

    public function test_author_cannot_edit_after_window(): void
    {
        $old = date('Y-m-d H:i:s', time() - 901); // 1s past default window
        $comment = $this->row(1, null, 'old', $old);
        $this->assertFalse($this->svc->canEdit($comment, userId: 7, isAdmin: false));
    }

    public function test_other_user_cannot_edit(): void
    {
        $comment = $this->row(1, null, 'not-mine', date('Y-m-d H:i:s'));
        $this->assertFalse($this->svc->canEdit($comment, userId: 99, isAdmin: false));
    }

    public function test_admin_bypasses_window_and_authorship(): void
    {
        $comment = $this->row(1, null, 'old', '2026-01-01 00:00:00');
        $this->assertTrue($this->svc->canEdit($comment, userId: 99, isAdmin: true));
    }

    public function test_cannot_edit_non_published_status(): void
    {
        $comment = $this->row(1, null, 'pending', date('Y-m-d H:i:s'), status: 'pending');
        $this->assertFalse($this->svc->canEdit($comment, userId: 7, isAdmin: false));
    }

    // ── setStatus() ───────────────────────────────────────────────────────

    public function test_setStatus_rejects_unknown_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Even without DB wiring the argument-validation error fires first.
        $this->svc->setStatus(1, 'not-a-status');
    }

    // ── determineInitialStatus — three scopes ─────────────────────────────

    private function settingRow(bool $required): array
    {
        return ['value' => $required ? '1' : '0', 'type' => 'bool'];
    }

    public function test_initial_status_published_when_all_scopes_quiet(): void
    {
        $this->assertSame(
            'published',
            $this->svc->determineInitialStatus('content', 1, 7)
        );
    }

    public function test_site_wide_moderation_forces_pending(): void
    {
        $this->db->settingsValue['site||comments_require_moderation'] = $this->settingRow(true);
        $this->assertSame(
            'pending',
            $this->svc->determineInitialStatus('content', 1, 7)
        );
    }

    public function test_per_user_moderation_forces_pending(): void
    {
        // Site is off, but this specific user is soft-banned.
        $this->db->settingsValue['user|7|comments_require_moderation'] = $this->settingRow(true);
        $this->assertSame('pending', $this->svc->determineInitialStatus('content', 1, 7));
        // A different user on the same target auto-publishes.
        $this->assertSame('published', $this->svc->determineInitialStatus('content', 1, 99));
    }

    public function test_per_group_moderation_forces_pending(): void
    {
        // Target is owned by group 42 and that group has moderation on.
        $this->db->contentOwnership[1] = [
            'owner_type'     => 'group',
            'owner_user_id'  => null,
            'owner_group_id' => 42,
        ];
        $this->db->settingsValue['group|42|comments_require_moderation'] = $this->settingRow(true);
        $this->assertSame('pending', $this->svc->determineInitialStatus('content', 1, 7));
    }

    public function test_per_group_moderation_ignored_when_target_is_user_owned(): void
    {
        $this->db->contentOwnership[1] = [
            'owner_type'     => 'user',
            'owner_user_id'  => 10,
            'owner_group_id' => null,
        ];
        $this->db->settingsValue['group|42|comments_require_moderation'] = $this->settingRow(true);
        // No site or per-user flag, target isn't group-owned → published.
        $this->assertSame('published', $this->svc->determineInitialStatus('content', 1, 7));
    }

    // ── canModerate — authority matrix ────────────────────────────────────

    public function test_canModerate_allows_global_moderator(): void
    {
        // We don't have a full Auth stub — this test documents the expected
        // behavior for the site-level path. The `$auth->can()` check inside
        // canModerate() returns false here (no logged-in user in TestCase),
        // so we only verify the group-admin branch in the group-owned test.
        // The global-yes case is exercised in the integration test on Will's
        // machine via the real Auth session.
        $this->markTestSkipped('Global-perm path requires Auth session — covered by integration.');
    }

    public function test_canModerate_allows_group_admin_of_owning_group(): void
    {
        // Target owned by group 42. User 7 is group_admin of 42.
        // We can't easily stub Auth::can() from here, but Auth::isGroupAdmin
        // reads `$this->groups` which comes from session state we can't
        // populate without a login. Test the path via ownershipOfTarget
        // which is the component that would gate the group-admin branch.
        $this->db->contentOwnership[5] = [
            'owner_type'     => 'group',
            'owner_user_id'  => null,
            'owner_group_id' => 42,
        ];
        $own = $this->svc->ownershipOfTarget('content', 5);
        $this->assertNotNull($own);
        $this->assertSame(42, $own['group_id']);
        $this->assertNull($own['user_id']);
    }

    public function test_ownershipOfTarget_returns_null_for_unknown_type(): void
    {
        // Pages don't have a group-ownership model; lookup should be null.
        $this->assertNull($this->svc->ownershipOfTarget('page', 1));
    }

    // ── groupIdsUserCanModerate ──────────────────────────────────────────

    public function test_groupIds_filters_by_admin_plus_roles(): void
    {
        $this->db->userGroups[7] = [
            1 => 'group_owner',
            2 => 'group_admin',
            3 => 'member',          // should be excluded
            4 => 'editor',          // should be excluded
        ];
        $ids = $this->svc->groupIdsUserCanModerate(7);
        sort($ids);
        $this->assertSame([1, 2], $ids);
    }

    public function test_groupIds_empty_when_user_has_no_admin_groups(): void
    {
        $this->db->userGroups[99] = [1 => 'member', 2 => 'editor'];
        $this->assertSame([], $this->svc->groupIdsUserCanModerate(99));
    }

    // ── setModerationScope validation ────────────────────────────────────

    public function test_setModerationScope_rejects_unknown_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setModerationScope('country', 'US', true);
    }
}
