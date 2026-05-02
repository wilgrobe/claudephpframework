<?php
// core/Services/SystemLayoutService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * SystemLayoutService — same job as PageLayoutService, but for layouts
 * keyed by string name instead of page_id. Used by surfaces that aren't
 * rows in the `pages` table (dashboard, /admin/modules, future admin
 * landing pages, and — as of page-chrome Batch A — every module page
 * that opts in via Response::withLayout()).
 *
 * Returns the same envelope shape PageLayoutService does so the
 * composer partial can render either source without caring which is
 * which:
 *   ['layout' => [...], 'placements' => [...]]
 *
 * Read paths defensively swallow missing-table errors so a fresh
 * install before migrations have run still serves /dashboard (the
 * controller will fall back to body-only rendering when the service
 * returns null).
 *
 * As of page-chrome Batch A:
 *
 *   - Each placement carries a `placement_type` ('block' or
 *     'content_slot') + an optional `slot_name`. Slot rows hold the
 *     sentinel block_key '__slot__' so a casual SQL inspection makes
 *     the type obvious without joining.
 *
 *   - Layouts carry friendly_name / module / category / description
 *     metadata used by /admin/system-layouts to render a humane,
 *     groupable index. None of these are required to RENDER a layout.
 *
 *   - seedLayout() + seedSlot() let module migrations stamp out a
 *     default chrome layout for their pages without each migration
 *     reimplementing the upsert + sentinel pattern.
 */
class SystemLayoutService
{
    /**
     * Sentinel value stored in `block_key` for content-slot placements.
     * Resolved decision from page-chrome plan §3 (open question 3): a
     * sentinel keeps `block_key` NOT NULL — preserving existing CHECK
     * assumptions and indexes — while making the row's role obvious in
     * a casual SQL inspection. The renderer ignores `block_key` for
     * slot rows, so the sentinel never reaches BlockRegistry::render().
     */
    public const SLOT_SENTINEL = '__slot__';

    /** Default slot name when a placement's slot_name is NULL. */
    public const DEFAULT_SLOT = 'primary';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Look up a system layout by name. Returns null when no layout has
     * been seeded for that name yet — caller should fall back gracefully.
     */
    public function get(string $name): ?array
    {
        try {
            $layout = $this->db->fetchOne(
                "SELECT name, friendly_name, module, category, description, chromed_url,
                        `rows`, cols, col_widths, row_heights, gap_pct, max_width_px
                   FROM system_layouts WHERE name = ?",
                [$name]
            );
        } catch (\Throwable) {
            // Either the table is missing OR the chromed_url column
            // hasn't been added yet (migration 2026_05_02_600000 not
            // applied). Fall back to the discoverability-only SELECT
            // before giving up entirely so a half-migrated install
            // still serves layouts.
            try {
                $layout = $this->db->fetchOne(
                    "SELECT name, friendly_name, module, category, description,
                            `rows`, cols, col_widths, row_heights, gap_pct, max_width_px
                       FROM system_layouts WHERE name = ?",
                    [$name]
                );
                if ($layout) $layout['chromed_url'] = null;
            } catch (\Throwable) {
                $layout = $this->getLegacyLayout($name);
            }
            if ($layout === null) return null;
        }
        if (!$layout) return null;

        $layout['rows']         = (int) $layout['rows'];
        $layout['cols']         = (int) $layout['cols'];
        $layout['col_widths']   = $this->decodeIntArray($layout['col_widths']);
        $layout['row_heights']  = $this->decodeIntArray($layout['row_heights']);
        $layout['gap_pct']      = (int) $layout['gap_pct'];
        $layout['max_width_px'] = (int) $layout['max_width_px'];

        try {
            $placements = $this->db->fetchAll(
                "SELECT id, row_index, col_index, sort_order,
                        block_key, placement_type, slot_name,
                        settings, visible_to
                   FROM system_block_placements
                  WHERE system_name = ?
                  ORDER BY row_index, col_index, sort_order, id",
                [$name]
            );
        } catch (\Throwable) {
            // Discoverability columns missing — fall back to the pre-Batch-A
            // shape. Every row is implicitly a 'block' placement.
            $placements = $this->db->fetchAll(
                "SELECT id, row_index, col_index, sort_order, block_key, settings, visible_to
                   FROM system_block_placements
                  WHERE system_name = ?
                  ORDER BY row_index, col_index, sort_order, id",
                [$name]
            );
            foreach ($placements as &$p) {
                $p['placement_type'] = 'block';
                $p['slot_name']      = null;
            }
            unset($p);
        }
        foreach ($placements as &$p) {
            $p['settings']       = $p['settings'] ? (json_decode($p['settings'], true) ?: []) : [];
            $p['placement_type'] = $p['placement_type'] ?? 'block';
            // Normalise slot_name: the renderer treats NULL and 'primary'
            // as equivalent, but it's friendlier to consumers if we hand
            // them a concrete value for slot rows.
            if ($p['placement_type'] === 'content_slot') {
                $slot = trim((string) ($p['slot_name'] ?? ''));
                $p['slot_name'] = $slot === '' ? self::DEFAULT_SLOT : $slot;
            }
        }
        unset($p);

        return ['layout' => $layout, 'placements' => $placements];
    }

    /**
     * Same as get() but only returns the layout metadata (rows/cols/
     * styling envelope) — useful for the admin index where we don't
     * need the placements just to render a row.
     *
     * Returns the array of layout rows shaped for /admin/system-layouts
     * consumption. Each row carries an extra `slot_count` so the index
     * page can flag "this layout wraps a module page" at a glance.
     */
    public function listAll(): array
    {
        // Try the full Batch-C-and-later column set first; fall back
        // through progressively older schemas when columns are missing.
        try {
            $rows = $this->db->fetchAll(
                "SELECT sl.name, sl.friendly_name, sl.module, sl.category, sl.description, sl.chromed_url,
                        sl.`rows`, sl.cols, sl.gap_pct, sl.max_width_px, sl.updated_at,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name) AS placement_count,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name AND sbp.placement_type = 'content_slot') AS slot_count
                   FROM system_layouts sl
                  ORDER BY COALESCE(sl.module, ''), COALESCE(sl.category, ''),
                           COALESCE(sl.friendly_name, sl.name)"
            );
            return $rows;
        } catch (\Throwable) {
            // chromed_url not yet added — try the Batch A column set.
        }
        try {
            $rows = $this->db->fetchAll(
                "SELECT sl.name, sl.friendly_name, sl.module, sl.category, sl.description,
                        sl.`rows`, sl.cols, sl.gap_pct, sl.max_width_px, sl.updated_at,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name) AS placement_count,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name AND sbp.placement_type = 'content_slot') AS slot_count
                   FROM system_layouts sl
                  ORDER BY COALESCE(sl.module, ''), COALESCE(sl.category, ''),
                           COALESCE(sl.friendly_name, sl.name)"
            );
            foreach ($rows as &$r) $r['chromed_url'] = null;
            unset($r);
            return $rows;
        } catch (\Throwable) {
            // Pre-Batch-A schema — fall back to the column set
            // SystemLayoutAdminController::index used to query directly.
        }
        try {
            $rows = $this->db->fetchAll(
                "SELECT sl.name, sl.`rows`, sl.cols, sl.gap_pct, sl.max_width_px, sl.updated_at,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name) AS placement_count
                   FROM system_layouts sl
                  ORDER BY sl.name"
            );
            foreach ($rows as &$r) {
                $r['friendly_name'] = null;
                $r['module']        = null;
                $r['category']      = null;
                $r['description']   = null;
                $r['chromed_url']   = null;
                $r['slot_count']    = 0;
            }
            unset($r);
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Upsert a layout row. Same percent-array normalisation as
     * PageLayoutService — duplicated rather than shared via a trait
     * because the two services are intentionally independent (no
     * shared parent class) and the helper is small.
     *
     * Discoverability metadata (friendly_name / module / category /
     * description) round-trips when present in $input but is ignored
     * when omitted, so the existing /admin/system-layouts save POST
     * — which doesn't yet submit those fields — keeps working
     * without any controller changes.
     */
    public function saveLayout(string $name, array $input): void
    {
        $rows  = max(1, min(6, (int) ($input['rows'] ?? 1)));
        $cols  = max(1, min(4, (int) ($input['cols'] ?? 1)));
        $colWidths  = $this->normalisePercentArray($input['col_widths']  ?? [], $cols);
        $rowHeights = $this->normalisePercentArray($input['row_heights'] ?? [], $rows);
        $gapPct     = max(0, min(20, (int) ($input['gap_pct'] ?? 3)));
        $maxWidthPx = max(320, min(4096, (int) ($input['max_width_px'] ?? 1280)));

        // Whether the caller cares about the discoverability columns.
        // We only touch them when the input explicitly provides a value —
        // otherwise REPLACE INTO would NULL out admin-edited friendly
        // names whenever the editor saves a grid change.
        $hasMeta = array_key_exists('friendly_name', $input)
                 || array_key_exists('module', $input)
                 || array_key_exists('category', $input)
                 || array_key_exists('description', $input);

        if ($hasMeta && $this->hasDiscoverabilityColumns()) {
            // Pull current row so unset keys preserve their stored value
            // — admins might post only `friendly_name`, not the others.
            $existing = $this->db->fetchOne(
                "SELECT friendly_name, module, category, description
                   FROM system_layouts WHERE name = ?",
                [$name]
            ) ?: [];

            $friendly = $this->coerceMetaString(
                $input['friendly_name'] ?? ($existing['friendly_name'] ?? null), 255
            );
            $module   = $this->coerceMetaString(
                $input['module']        ?? ($existing['module']        ?? null), 64
            );
            $category = $this->coerceMetaString(
                $input['category']      ?? ($existing['category']      ?? null), 64
            );
            $description = $this->coerceMetaString(
                $input['description']   ?? ($existing['description']   ?? null), 65535
            );

            $this->db->query(
                "REPLACE INTO system_layouts
                    (name, friendly_name, module, category, description,
                     `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $name, $friendly, $module, $category, $description,
                    $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                ]
            );
            return;
        }

        // Path that preserves existing discoverability values when the
        // editor doesn't post them — UPDATE if the row exists, INSERT
        // when it doesn't. We can't use REPLACE INTO here because that
        // would clobber the metadata columns with NULL.
        $exists = $this->db->fetchOne(
            "SELECT 1 FROM system_layouts WHERE name = ?",
            [$name]
        );
        if ($exists) {
            $this->db->query(
                "UPDATE system_layouts
                    SET `rows` = ?, cols = ?, col_widths = ?, row_heights = ?,
                        gap_pct = ?, max_width_px = ?
                  WHERE name = ?",
                [
                    $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                    $name,
                ]
            );
        } else {
            $this->db->query(
                "INSERT INTO system_layouts
                    (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $name, $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                ]
            );
        }
    }

    /**
     * Replace ALL placements for a system layout in one transaction.
     * Same semantics as PageLayoutService::savePlacements.
     *
     * Each placement input is either:
     *   ['row'=>int, 'col'=>int, 'sort_order'=>int,
     *    'placement_type'=>'block', 'block_key'=>string,
     *    'settings'=>array|null, 'visible_to'=>'any'|'auth'|'guest']
     *
     *   ['row'=>int, 'col'=>int, 'sort_order'=>int,
     *    'placement_type'=>'content_slot', 'slot_name'=>string,
     *    'settings'=>array|null]   // visible_to is intentionally ignored
     *
     * Rows missing a placement_type default to 'block' for back-compat
     * with the existing admin form which doesn't yet submit the field.
     */
    public function savePlacements(string $name, array $placements): void
    {
        $hasNewCols = $this->hasPlacementTypeColumns();

        $this->db->transaction(function () use ($name, $placements, $hasNewCols) {
            $this->db->query("DELETE FROM system_block_placements WHERE system_name = ?", [$name]);
            foreach ($placements as $p) {
                $type = (string) ($p['placement_type'] ?? 'block');
                if (!in_array($type, ['block','content_slot'], true)) $type = 'block';

                if ($type === 'content_slot') {
                    $slot = trim((string) ($p['slot_name'] ?? ''));
                    if ($slot === '') $slot = self::DEFAULT_SLOT;
                    $key = self::SLOT_SENTINEL;
                    // visible_to intentionally hard-set to 'any' for slots
                    // — the controller owns auth gating; the layout must
                    // never silently hide the page's primary content. See
                    // page-chrome plan §"placement_type='content_slot'".
                    $vt  = 'any';
                } else {
                    $key = trim((string) ($p['block_key'] ?? ''));
                    if ($key === '') continue;
                    $vt = (string) ($p['visible_to'] ?? 'any');
                    if (!in_array($vt, ['any','auth','guest'], true)) $vt = 'any';
                    $slot = null;
                }

                $settings = $p['settings'] ?? null;
                if (is_string($settings)) {
                    $decoded  = json_decode($settings, true);
                    $settings = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
                }

                $row = [
                    'system_name' => $name,
                    'row_index'   => max(0, (int) ($p['row'] ?? 0)),
                    'col_index'   => max(0, (int) ($p['col'] ?? 0)),
                    'sort_order'  => max(0, (int) ($p['sort_order'] ?? 0)),
                    'block_key'   => $key,
                    'settings'    => $settings !== null ? json_encode($settings) : null,
                    'visible_to'  => $vt,
                ];
                if ($hasNewCols) {
                    $row['placement_type'] = $type;
                    $row['slot_name']      = $slot;
                }

                $this->db->insert('system_block_placements', $row);
            }
        });
    }

    public function delete(string $name): void
    {
        // CASCADE on system_block_placements handles the children automatically
        $this->db->query("DELETE FROM system_layouts WHERE name = ?", [$name]);
    }

    // ── Module-migration helpers (page-chrome Batch A) ────────────────────────

    /**
     * Idempotently create a default system layout. INSERT IGNORE on the
     * primary key — re-running the migration that calls this won't
     * clobber an admin's edits.
     *
     * $opts:
     *   friendly_name, module, category, description — metadata for the
     *     admin index. Optional but strongly recommended; without
     *     friendly_name the layout shows up as its raw `name` slug.
     *   chromed_url — URL of the page this layout chromes (e.g.
     *     '/account/data'). Powers the "View ↗" button on the admin
     *     index. Optional; NULL means "this layout isn't a standalone
     *     page" (dashboard partials, etc).
     *   rows (1..6), cols (1..4), col_widths (int[]), row_heights (int[]),
     *     gap_pct (0..20), max_width_px (320..4096) — same defaults as
     *     PageLayoutService.
     *
     * Returns true when the row was newly created, false when it
     * already existed (and therefore wasn't touched).
     */
    public function seedLayout(string $name, array $opts = []): bool
    {
        $rows         = max(1, min(6, (int) ($opts['rows']         ?? 1)));
        $cols         = max(1, min(4, (int) ($opts['cols']         ?? 1)));
        $colWidths    = $this->normalisePercentArray($opts['col_widths']  ?? [], $cols);
        $rowHeights   = $this->normalisePercentArray($opts['row_heights'] ?? [], $rows);
        $gapPct       = max(0, min(20, (int) ($opts['gap_pct']      ?? 3)));
        $maxWidthPx   = max(320, min(4096, (int) ($opts['max_width_px'] ?? 1280)));

        $existing = $this->db->fetchOne(
            "SELECT 1 FROM system_layouts WHERE name = ?",
            [$name]
        );
        if ($existing) return false;

        $hasDisc = $this->hasDiscoverabilityColumns();
        $hasUrl  = $this->hasChromedUrlColumn();

        if ($hasDisc && $hasUrl) {
            $this->db->query(
                "INSERT IGNORE INTO system_layouts
                    (name, friendly_name, module, category, description, chromed_url,
                     `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $name,
                    $this->coerceMetaString($opts['friendly_name'] ?? null, 255),
                    $this->coerceMetaString($opts['module']        ?? null, 64),
                    $this->coerceMetaString($opts['category']      ?? null, 64),
                    $this->coerceMetaString($opts['description']   ?? null, 65535),
                    $this->coerceMetaString($opts['chromed_url']   ?? null, 255),
                    $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                ]
            );
        } elseif ($hasDisc) {
            $this->db->query(
                "INSERT IGNORE INTO system_layouts
                    (name, friendly_name, module, category, description,
                     `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $name,
                    $this->coerceMetaString($opts['friendly_name'] ?? null, 255),
                    $this->coerceMetaString($opts['module']        ?? null, 64),
                    $this->coerceMetaString($opts['category']      ?? null, 64),
                    $this->coerceMetaString($opts['description']   ?? null, 65535),
                    $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                ]
            );
        } else {
            $this->db->query(
                "INSERT IGNORE INTO system_layouts
                    (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $name, $rows, $cols,
                    json_encode($colWidths),
                    json_encode($rowHeights),
                    $gapPct, $maxWidthPx,
                ]
            );
        }
        return true;
    }

    /**
     * Idempotently insert a content-slot placement into a layout cell.
     * INSERT IGNORE based on a uniqueness check across (system_name,
     * row_index, col_index, slot_name) — re-running the migration won't
     * stack duplicate slot rows in the same cell.
     *
     * Returns true when a new row was created.
     */
    public function seedSlot(string $layoutName, string $slotName = self::DEFAULT_SLOT, int $row = 0, int $col = 0, int $sortOrder = 0): bool
    {
        if (!$this->hasPlacementTypeColumns()) {
            // Schema migration hasn't run yet — nothing we can usefully do.
            // The migration that calls seedSlot() should be ordered after
            // 2026_05_02_300000_add_placement_type_and_slot_to_block_placements,
            // but we fail soft if the order is wrong on a partial install.
            return false;
        }

        $slot = trim($slotName) === '' ? self::DEFAULT_SLOT : trim($slotName);

        $exists = $this->db->fetchOne(
            "SELECT 1 FROM system_block_placements
              WHERE system_name = ? AND placement_type = 'content_slot'
                AND row_index = ? AND col_index = ? AND COALESCE(slot_name, ?) = ?",
            [$layoutName, $row, $col, self::DEFAULT_SLOT, $slot]
        );
        if ($exists) return false;

        $this->db->insert('system_block_placements', [
            'system_name'    => $layoutName,
            'row_index'      => max(0, $row),
            'col_index'      => max(0, $col),
            'sort_order'     => max(0, $sortOrder),
            'block_key'      => self::SLOT_SENTINEL,
            'placement_type' => 'content_slot',
            'slot_name'      => $slot,
            'settings'       => null,
            'visible_to'     => 'any',
        ]);
        return true;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Pre-Batch-A schema fallback: SELECT only the original columns when
     * the discoverability columns aren't present. Returns null when the
     * table itself is missing.
     */
    private function getLegacyLayout(string $name): ?array
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT `rows`, cols, col_widths, row_heights, gap_pct, max_width_px
                   FROM system_layouts WHERE name = ?",
                [$name]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!$row) return null;
        $row['friendly_name'] = null;
        $row['module']        = null;
        $row['category']      = null;
        $row['description']   = null;
        $row['chromed_url']   = null;
        $row['name']          = $name;
        return $row;
    }

    /**
     * Cheap memo of the schema state. Several columns were added across
     * Batches A + C; keep a separate guard per column group so a
     * partial install (one ALTER applied, the next not yet) still gets
     * correct behaviour through the right code path.
     */
    private ?bool $cachedHasDiscoverability = null;
    private ?bool $cachedHasPlacementCols   = null;
    private ?bool $cachedHasChromedUrl      = null;

    private function hasDiscoverabilityColumns(): bool
    {
        if ($this->cachedHasDiscoverability !== null) return $this->cachedHasDiscoverability;
        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS c
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'system_layouts'
                    AND COLUMN_NAME  = 'friendly_name'"
            );
            $this->cachedHasDiscoverability = $row !== null && (int) $row['c'] > 0;
        } catch (\Throwable) {
            $this->cachedHasDiscoverability = false;
        }
        return $this->cachedHasDiscoverability;
    }

    private function hasPlacementTypeColumns(): bool
    {
        if ($this->cachedHasPlacementCols !== null) return $this->cachedHasPlacementCols;
        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS c
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'system_block_placements'
                    AND COLUMN_NAME  = 'placement_type'"
            );
            $this->cachedHasPlacementCols = $row !== null && (int) $row['c'] > 0;
        } catch (\Throwable) {
            $this->cachedHasPlacementCols = false;
        }
        return $this->cachedHasPlacementCols;
    }

    private function hasChromedUrlColumn(): bool
    {
        if ($this->cachedHasChromedUrl !== null) return $this->cachedHasChromedUrl;
        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS c
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'system_layouts'
                    AND COLUMN_NAME  = 'chromed_url'"
            );
            $this->cachedHasChromedUrl = $row !== null && (int) $row['c'] > 0;
        } catch (\Throwable) {
            $this->cachedHasChromedUrl = false;
        }
        return $this->cachedHasChromedUrl;
    }

    private function decodeIntArray(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];
        return array_map(fn($v) => (int) $v, $arr);
    }

    private function normalisePercentArray(mixed $input, int $count): array
    {
        if (is_string($input)) {
            $input = array_filter(array_map('trim', explode(',', $input)), fn($s) => $s !== '');
        }
        if (!is_array($input)) $input = [];

        $out = [];
        $last = 0;
        foreach (array_values($input) as $v) {
            $n = max(1, min(99, (int) $v));
            $out[] = $n;
            $last  = $n;
        }
        $defaultPad = $last ?: max(1, (int) floor(95 / max(1, $count)));
        while (count($out) < $count) $out[] = $defaultPad;

        return array_slice($out, 0, $count);
    }

    /**
     * Trim a metadata string and clamp to a max length so a hostile or
     * accidental oversize input doesn't blow past the column width and
     * cause a truncation error.
     */
    private function coerceMetaString(mixed $raw, int $maxLen): ?string
    {
        if ($raw === null) return null;
        $s = trim((string) $raw);
        if ($s === '') return null;
        return mb_substr($s, 0, $maxLen);
    }
}
