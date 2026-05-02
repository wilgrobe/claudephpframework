<?php
// core/Services/SystemLayoutService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * SystemLayoutService — same job as PageLayoutService, but for layouts
 * keyed by string name instead of page_id. Used by surfaces that aren't
 * rows in the `pages` table (dashboard, /admin/modules, future admin
 * landing pages).
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
 */
class SystemLayoutService
{
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
                "SELECT `rows`, cols, col_widths, row_heights, gap_pct, max_width_px
                   FROM system_layouts WHERE name = ?",
                [$name]
            );
        } catch (\Throwable) {
            return null; // table missing on fresh install
        }
        if (!$layout) return null;

        $layout['rows']         = (int) $layout['rows'];
        $layout['cols']         = (int) $layout['cols'];
        $layout['col_widths']   = $this->decodeIntArray($layout['col_widths']);
        $layout['row_heights']  = $this->decodeIntArray($layout['row_heights']);
        $layout['gap_pct']      = (int) $layout['gap_pct'];
        $layout['max_width_px'] = (int) $layout['max_width_px'];

        $placements = $this->db->fetchAll(
            "SELECT id, row_index, col_index, sort_order, block_key, settings, visible_to
               FROM system_block_placements
              WHERE system_name = ?
              ORDER BY row_index, col_index, sort_order, id",
            [$name]
        );
        foreach ($placements as &$p) {
            $p['settings'] = $p['settings'] ? (json_decode($p['settings'], true) ?: []) : [];
        }
        unset($p);

        return ['layout' => $layout, 'placements' => $placements];
    }

    /**
     * Upsert a layout row. Same percent-array normalisation as
     * PageLayoutService — duplicated rather than shared via a trait
     * because the two services are intentionally independent (no
     * shared parent class) and the helper is small.
     */
    public function saveLayout(string $name, array $input): void
    {
        $rows  = max(1, min(6, (int) ($input['rows'] ?? 1)));
        $cols  = max(1, min(4, (int) ($input['cols'] ?? 1)));
        $colWidths  = $this->normalisePercentArray($input['col_widths']  ?? [], $cols);
        $rowHeights = $this->normalisePercentArray($input['row_heights'] ?? [], $rows);
        $gapPct     = max(0, min(20, (int) ($input['gap_pct'] ?? 3)));
        $maxWidthPx = max(320, min(4096, (int) ($input['max_width_px'] ?? 1280)));

        $this->db->query(
            "REPLACE INTO system_layouts
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

    /**
     * Replace ALL placements for a system layout in one transaction.
     * Same semantics as PageLayoutService::savePlacements.
     */
    public function savePlacements(string $name, array $placements): void
    {
        $this->db->transaction(function () use ($name, $placements) {
            $this->db->query("DELETE FROM system_block_placements WHERE system_name = ?", [$name]);
            foreach ($placements as $p) {
                $key = trim((string) ($p['block_key'] ?? ''));
                if ($key === '') continue;

                $vt = (string) ($p['visible_to'] ?? 'any');
                if (!in_array($vt, ['any','auth','guest'], true)) $vt = 'any';

                $settings = $p['settings'] ?? null;
                if (is_string($settings)) {
                    $decoded  = json_decode($settings, true);
                    $settings = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
                }

                $this->db->insert('system_block_placements', [
                    'system_name' => $name,
                    'row_index'   => max(0, (int) ($p['row'] ?? 0)),
                    'col_index'   => max(0, (int) ($p['col'] ?? 0)),
                    'sort_order'  => max(0, (int) ($p['sort_order'] ?? 0)),
                    'block_key'   => $key,
                    'settings'    => $settings !== null ? json_encode($settings) : null,
                    'visible_to'  => $vt,
                ]);
            }
        });
    }

    public function delete(string $name): void
    {
        // CASCADE on system_block_placements handles the children automatically
        $this->db->query("DELETE FROM system_layouts WHERE name = ?", [$name]);
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
}
