<?php
// tests/Unit/Http/PageChromeBatchATest.php
namespace Tests\Unit\Http;

use Core\Database\Database;
use Core\Http\ChromeWrapper;
use Core\Response;
use Core\Services\SystemLayoutService;
use Core\View;
use Tests\TestCase;

/**
 * Captures-call fake for the bits of Database the page-chrome paths
 * touch: SystemLayoutService::get/listAll/seedLayout/seedSlot, plus
 * the savePlacements transaction.
 *
 * Each public method is overridden; the real PDO connection is never
 * touched. The fake stores a layout map + a placements list so tests
 * can pre-load known fixtures and assert what was written.
 */
final class FakeChromeDb extends Database
{
    /** Map layout name → row array (mirrors system_layouts). */
    public array $layouts = [];
    /** Flat list of placement rows (mirrors system_block_placements). */
    public array $placements = [];
    /** Whether the discoverability columns "exist". Toggle to test the fallback. */
    public bool $hasDiscoverability = true;
    /** Whether placement_type/slot_name "exist". */
    public bool $hasPlacementCols   = true;

    public function __construct() { /* skip parent ctor + PDO connect */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // information_schema.COLUMNS guards in the service. The service
        // calls this with NO bindings (column name is literal in the SQL),
        // so detect by string content.
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            if (str_contains($sql, "'friendly_name'")) {
                return ['c' => $this->hasDiscoverability ? 1 : 0];
            }
            if (str_contains($sql, "'placement_type'")) {
                return ['c' => $this->hasPlacementCols ? 1 : 0];
            }
            // Migration helper passes (table_name, column_name) bindings.
            if (count($bindings) === 2) {
                $col = $bindings[1];
                if ($col === 'friendly_name')  return ['c' => $this->hasDiscoverability ? 1 : 0];
                if ($col === 'placement_type') return ['c' => $this->hasPlacementCols   ? 1 : 0];
            }
            return ['c' => 0];
        }

        // Layout lookup — match by `name = ?` parameter.
        if (preg_match('/FROM system_layouts WHERE name = \?/', $sql)) {
            $name = $bindings[0] ?? null;
            return $this->layouts[$name] ?? null;
        }

        // Existence check via "SELECT 1 FROM system_layouts WHERE name = ?".
        if (preg_match('/SELECT 1 FROM system_layouts WHERE name = \?/', $sql)) {
            $name = $bindings[0] ?? null;
            return isset($this->layouts[$name]) ? ['1' => 1] : null;
        }

        // Slot uniqueness check used by seedSlot.
        if (str_contains($sql, "FROM system_block_placements") && str_contains($sql, "placement_type = 'content_slot'")) {
            [$layoutName, $row, $col, , $slot] = $bindings;
            foreach ($this->placements as $p) {
                if ($p['system_name'] === $layoutName
                    && ($p['placement_type'] ?? 'block') === 'content_slot'
                    && (int) $p['row_index'] === (int) $row
                    && (int) $p['col_index'] === (int) $col
                    && (($p['slot_name'] ?? null) ?: 'primary') === $slot) {
                    return ['1' => 1];
                }
            }
            return null;
        }

        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, 'FROM system_block_placements') && str_contains($sql, 'WHERE system_name = ?')) {
            $name = $bindings[0] ?? null;
            $out  = [];
            foreach ($this->placements as $p) {
                if ($p['system_name'] === $name) $out[] = $p;
            }
            usort($out, fn($a, $b) =>
                [$a['row_index'], $a['col_index'], $a['sort_order'], $a['id'] ?? 0]
                <=> [$b['row_index'], $b['col_index'], $b['sort_order'], $b['id'] ?? 0]
            );
            return $out;
        }
        if (str_contains($sql, 'FROM system_layouts')) {
            return array_values($this->layouts);
        }
        return [];
    }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $noopStmt = new class extends \PDOStatement { public function __construct() {} };

        if (preg_match('/INSERT(?: IGNORE)? INTO system_layouts/', $sql)) {
            $hasMeta = $this->hasDiscoverability && str_contains($sql, 'friendly_name');
            if ($hasMeta) {
                [$name, $friendly, $module, $category, $description, $rows, $cols, $cw, $rh, $gap, $mw] = $bindings;
            } else {
                [$name, $rows, $cols, $cw, $rh, $gap, $mw] = $bindings;
                $friendly = $module = $category = $description = null;
            }
            if (str_contains($sql, 'INSERT IGNORE') && isset($this->layouts[$name])) {
                // INSERT IGNORE — leave the existing row alone.
            } else {
                $this->layouts[$name] = [
                    'name'          => $name,
                    'friendly_name' => $friendly,
                    'module'        => $module,
                    'category'      => $category,
                    'description'   => $description,
                    'rows'          => (int) $rows,
                    'cols'          => (int) $cols,
                    'col_widths'    => $cw,
                    'row_heights'   => $rh,
                    'gap_pct'       => (int) $gap,
                    'max_width_px'  => (int) $mw,
                ];
            }
            return $noopStmt;
        }

        if (str_starts_with(trim($sql), 'REPLACE INTO system_layouts')) {
            [$name, $friendly, $module, $category, $description, $rows, $cols, $cw, $rh, $gap, $mw] = $bindings;
            $this->layouts[$name] = [
                'name'          => $name,
                'friendly_name' => $friendly,
                'module'        => $module,
                'category'      => $category,
                'description'   => $description,
                'rows'          => (int) $rows,
                'cols'          => (int) $cols,
                'col_widths'    => $cw,
                'row_heights'   => $rh,
                'gap_pct'       => (int) $gap,
                'max_width_px'  => (int) $mw,
            ];
            return $noopStmt;
        }

        if (preg_match('/^UPDATE system_layouts/', trim($sql))) {
            $name = end($bindings);
            if (isset($this->layouts[$name])) {
                [$rows, $cols, $cw, $rh, $gap, $mw] = $bindings;
                $this->layouts[$name]['rows']         = (int) $rows;
                $this->layouts[$name]['cols']         = (int) $cols;
                $this->layouts[$name]['col_widths']   = $cw;
                $this->layouts[$name]['row_heights']  = $rh;
                $this->layouts[$name]['gap_pct']      = (int) $gap;
                $this->layouts[$name]['max_width_px'] = (int) $mw;
            }
            return $noopStmt;
        }

        if (preg_match('/DELETE FROM system_block_placements WHERE system_name = \?/', $sql)) {
            $name = $bindings[0];
            $this->placements = array_values(array_filter(
                $this->placements,
                fn($p) => $p['system_name'] !== $name
            ));
            return $noopStmt;
        }

        return $noopStmt;
    }

    public function insert(string $table, array $data): int
    {
        if ($table === 'system_block_placements') {
            $data['id'] = count($this->placements) + 1;
            $data['settings'] = is_string($data['settings'] ?? null) ? json_decode($data['settings'], true) : $data['settings'];
            $this->placements[] = $data;
            return (int) $data['id'];
        }
        return 0;
    }

    public function transaction(callable $fn): mixed
    {
        return $fn();
    }
}

final class PageChromeBatchATest extends TestCase
{
    private FakeChromeDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeChromeDb();
        $this->mockDatabase($this->db);
    }

    // ── Schema migration files ───────────────────────────────────────────────

    public function test_migration_adds_placement_type_and_slot_name_to_both_tables(): void
    {
        $path = BASE_PATH . '/database/migrations/2026_05_02_300000_add_placement_type_and_slot_to_block_placements.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        $this->assertStringContainsString('system_block_placements', $src);
        $this->assertStringContainsString('page_block_placements',   $src);
        $this->assertStringContainsString('placement_type',          $src);
        $this->assertStringContainsString('slot_name',               $src);
        $this->assertStringContainsString("ENUM('block','content_slot')", $src);
        $this->assertStringContainsString('information_schema.COLUMNS', $src,
            'Migration must check column existence before adding so re-runs are safe.');
    }

    public function test_migration_adds_discoverability_columns_to_system_layouts(): void
    {
        $path = BASE_PATH . '/database/migrations/2026_05_02_310000_add_discoverability_to_system_layouts.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        foreach (['friendly_name', 'module', 'category', 'description'] as $col) {
            $this->assertStringContainsString($col, $src,
                "Discoverability migration must add the `$col` column.");
        }
        $this->assertStringContainsString('idx_system_layouts_module_category', $src,
            'Discoverability migration must create the module+category index used by the admin index page.');
    }

    // ── SystemLayoutService::seedLayout / seedSlot ───────────────────────────

    public function test_seedLayout_inserts_with_metadata_when_columns_exist(): void
    {
        $svc = new SystemLayoutService();
        $created = $svc->seedLayout('messaging.thread_index', [
            'friendly_name' => 'Messaging — Inbox',
            'module'        => 'messaging',
            'category'      => 'Messaging',
            'description'   => 'Layout for /messages.',
            'rows' => 1, 'cols' => 1,
            'col_widths' => [100], 'row_heights' => [100],
        ]);
        $this->assertTrue($created);
        $this->assertTrue(array_key_exists('messaging.thread_index', $this->db->layouts));
        $row = $this->db->layouts['messaging.thread_index'];
        $this->assertSame('Messaging — Inbox', $row['friendly_name']);
        $this->assertSame('messaging',         $row['module']);
        $this->assertSame(1,                   (int) $row['rows']);
    }

    public function test_seedLayout_returns_false_when_layout_already_exists(): void
    {
        $svc = new SystemLayoutService();
        $svc->seedLayout('foo.bar', ['friendly_name' => 'Foo', 'rows' => 1, 'cols' => 1]);
        $created = $svc->seedLayout('foo.bar', ['friendly_name' => 'Other', 'rows' => 2, 'cols' => 2]);

        $this->assertFalse($created, 'Re-seed must report no insert when row already exists.');
        $this->assertSame('Foo', $this->db->layouts['foo.bar']['friendly_name'],
            'Re-seed must preserve admin-edited friendly_name (first-write-wins).');
    }

    public function test_seedSlot_creates_a_content_slot_placement(): void
    {
        $svc = new SystemLayoutService();
        $svc->seedLayout('foo.bar', ['rows' => 1, 'cols' => 1]);
        $created = $svc->seedSlot('foo.bar', 'primary', 0, 0);
        $this->assertTrue($created);

        $this->assertCount(1, $this->db->placements);
        $p = $this->db->placements[0];
        $this->assertSame('foo.bar',       $p['system_name']);
        $this->assertSame('content_slot',  $p['placement_type']);
        $this->assertSame('primary',       $p['slot_name']);
        $this->assertSame(SystemLayoutService::SLOT_SENTINEL, $p['block_key']);
    }

    public function test_seedSlot_is_idempotent(): void
    {
        $svc = new SystemLayoutService();
        $svc->seedLayout('foo.bar', ['rows' => 1, 'cols' => 1]);
        $svc->seedSlot('foo.bar', 'primary', 0, 0);
        $second = $svc->seedSlot('foo.bar', 'primary', 0, 0);

        $this->assertFalse($second);
        $this->assertCount(1, $this->db->placements,
            'Re-seeding the same (layout, slot, cell) tuple must NOT stack a duplicate placement.');
    }

    public function test_savePlacements_writes_content_slot_rows_with_sentinel_block_key(): void
    {
        $svc = new SystemLayoutService();
        $svc->seedLayout('foo.bar', ['rows' => 1, 'cols' => 1]);
        $svc->savePlacements('foo.bar', [
            ['row' => 0, 'col' => 0, 'sort_order' => 0,
             'placement_type' => 'content_slot', 'slot_name' => 'primary'],
        ]);

        $this->assertCount(1, $this->db->placements);
        $p = $this->db->placements[0];
        $this->assertSame(SystemLayoutService::SLOT_SENTINEL, $p['block_key'],
            'Slot rows must use the sentinel block_key so casual SQL inspection makes the type obvious.');
        $this->assertSame('any', $p['visible_to'],
            'Slot rows must hard-set visible_to to any — the controller owns auth gating.');
    }

    public function test_get_normalises_slot_name_null_to_primary(): void
    {
        $this->db->layouts['x.y'] = [
            'name' => 'x.y',
            'friendly_name' => null, 'module' => null, 'category' => null, 'description' => null,
            'rows' => 1, 'cols' => 1,
            'col_widths' => json_encode([100]), 'row_heights' => json_encode([100]),
            'gap_pct' => 3, 'max_width_px' => 1280,
        ];
        $this->db->placements[] = [
            'id' => 1, 'system_name' => 'x.y',
            'row_index' => 0, 'col_index' => 0, 'sort_order' => 0,
            'block_key' => SystemLayoutService::SLOT_SENTINEL,
            'placement_type' => 'content_slot',
            'slot_name' => null,
            'settings' => null, 'visible_to' => 'any',
        ];

        $svc = new SystemLayoutService();
        $env = $svc->get('x.y');
        $this->assertNotSame(null, $env);
        $this->assertSame('primary', $env['placements'][0]['slot_name'],
            'NULL slot_name must be normalised to "primary" by the service.');
    }

    // ── Response chrome API ──────────────────────────────────────────────────

    public function test_withLayout_records_chrome_config(): void
    {
        $r = (new Response('inner body', 200, ['Content-Type' => 'text/html']))
            ->withLayout('messaging.thread_index');

        $cfg = $r->getChromeConfig();
        $this->assertNotSame(null, $cfg);
        $this->assertSame('single', $cfg['mode']);
        $this->assertSame('messaging.thread_index', $cfg['layout']);
        $this->assertSame('primary', $cfg['slot']);
    }

    public function test_withLayout_blank_slot_falls_back_to_primary(): void
    {
        $r = (new Response('inner', 200, ['Content-Type' => 'text/html']))
            ->withLayout('foo.bar', '   ');
        $this->assertSame('primary', $r->getChromeConfig()['slot']);
    }

    public function test_chrome_factory_builds_multi_slot_config(): void
    {
        $r = Response::chrome('messaging.thread', [
            'primary' => '<div>main</div>',
            'sidebar' => '<aside>side</aside>',
        ]);

        $cfg = $r->getChromeConfig();
        $this->assertSame('multi', $cfg['mode']);
        $this->assertSame('messaging.thread', $cfg['layout']);
        $this->assertSame(['primary' => '<div>main</div>', 'sidebar' => '<aside>side</aside>'], $cfg['slots']);
    }

    public function test_response_without_chrome_returns_null_config(): void
    {
        $r = new Response('hello', 200, ['Content-Type' => 'text/html']);
        $this->assertSame(null, $r->getChromeConfig());
    }

    // ── ChromeWrapper skip rules ─────────────────────────────────────────────

    public function test_shouldWrap_skips_non_text_html_response(): void
    {
        $r = (new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']))
            ->withLayout('foo.bar');
        $this->assertFalse(ChromeWrapper::shouldWrap($r, $r->getChromeConfig()),
            'JSON responses must skip chrome wrapping.');
    }

    public function test_shouldWrap_skips_non_2xx_response(): void
    {
        $r = (new Response('moved', 302, ['Content-Type' => 'text/html', 'Location' => '/x']))
            ->withLayout('foo.bar');
        $this->assertFalse(ChromeWrapper::shouldWrap($r, $r->getChromeConfig()),
            'Redirect responses must skip chrome wrapping.');

        $r404 = (new Response('not found', 404, ['Content-Type' => 'text/html']))
            ->withLayout('foo.bar');
        $this->assertFalse(ChromeWrapper::shouldWrap($r404, $r404->getChromeConfig()));
    }

    public function test_shouldWrap_skips_xhr_request(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        try {
            $r = (new Response('partial', 200, ['Content-Type' => 'text/html; charset=UTF-8']))
                ->withLayout('foo.bar');
            $this->assertFalse(ChromeWrapper::shouldWrap($r, $r->getChromeConfig()),
                'XHR requests must skip chrome — they expect raw fragments.');
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }
    }

    public function test_shouldWrap_skips_htmx_request(): void
    {
        $_SERVER['HTTP_HX_REQUEST'] = 'true';
        try {
            $r = (new Response('partial', 200, ['Content-Type' => 'text/html']))
                ->withLayout('foo.bar');
            $this->assertFalse(ChromeWrapper::shouldWrap($r, $r->getChromeConfig()));
        } finally {
            unset($_SERVER['HTTP_HX_REQUEST']);
        }
    }

    public function test_shouldWrap_accepts_html_2xx_non_xhr(): void
    {
        $r = (new Response('hello', 200, ['Content-Type' => 'text/html; charset=UTF-8']))
            ->withLayout('foo.bar');
        $this->assertTrue(ChromeWrapper::shouldWrap($r, $r->getChromeConfig()));
    }

    // ── ChromeWrapper layout-missing fallback ────────────────────────────────

    public function test_wrap_falls_back_to_unwrapped_when_layout_missing(): void
    {
        // No layout seeded — wrap() must return the original body unchanged.
        $r = (new Response('<p>fallback body</p>', 200, ['Content-Type' => 'text/html']))
            ->withLayout('does.not.exist');

        $wrapped = ChromeWrapper::wrap($r);
        $this->assertSame('<p>fallback body</p>', $wrapped,
            'Missing layout must produce graceful fallback — broken chrome must never break the page.');
    }

    public function test_wrap_returns_body_unchanged_when_no_chrome_set(): void
    {
        $r = new Response('<p>unwrapped</p>', 200, ['Content-Type' => 'text/html']);
        $this->assertSame('<p>unwrapped</p>', ChromeWrapper::wrap($r));
    }

    // ── View::renderFragment capture-and-emit ────────────────────────────────

    public function test_renderFragment_captures_pageTitle_global(): void
    {
        // Build a temp fragment view that sets $pageTitle and emits HTML.
        $tmpDir  = sys_get_temp_dir() . '/chrome-test-' . uniqid('', true);
        @mkdir($tmpDir, 0777, true);
        $tmpView = $tmpDir . '/inbox.php';
        file_put_contents($tmpView, '<?php $pageTitle = "My Inbox"; $pageStyles = ["/css/inbox.css"]; ?><div class="inbox">hello</div>');

        $reset = $this->withViewsPath($tmpDir);
        try {
            $result = View::renderFragment('inbox');
            $this->assertSame('<div class="inbox">hello</div>', $result['body']);
            $this->assertSame('My Inbox', $result['captured']['pageTitle']);
            $this->assertSame(['/css/inbox.css'], $result['captured']['pageStyles']);
            $this->assertSame(null, $result['captured']['pageScripts'],
                'Captures shape must be stable — keys not set by the view become null, not absent.');
        } finally {
            $reset();
            @unlink($tmpView);
            @rmdir($tmpDir);
        }
    }

    public function test_renderFragment_throws_when_view_extends_a_layout(): void
    {
        $tmpDir  = sys_get_temp_dir() . '/chrome-test-' . uniqid('', true);
        @mkdir($tmpDir, 0777, true);
        $tmpView = $tmpDir . '/bad.php';
        file_put_contents($tmpView, "<?php \\Core\\View::extend('layouts.app'); ?>");

        $reset = $this->withViewsPath($tmpDir);
        try {
            $this->expectException(\LogicException::class);
            View::renderFragment('bad');
        } finally {
            $reset();
            @unlink($tmpView);
            @rmdir($tmpDir);
        }
    }

    /** Helper: temporarily point View at a tmp directory; returns a teardown closure. */
    private function withViewsPath(string $path): \Closure
    {
        $ref = new \ReflectionClass(View::class);
        $prop = $ref->getProperty('viewsPath');
        $prior = $prop->getValue();
        View::setViewsPath($path);
        return function () use ($prop, $prior) {
            $prop->setValue(null, $prior);
        };
    }
}
