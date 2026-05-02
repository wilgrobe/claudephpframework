<?php
// core/Services/PageLayoutService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * PageLayoutService — read/write helper around `page_layouts` and
 * `page_block_placements`. Hides the JSON encode/decode + the join from
 * controllers and the public renderer.
 *
 * Layouts are opt-in per page: a page row with no matching layout row
 * falls back to rendering its `body` field exactly as before. That's
 * what makes adding the composer non-breaking for existing static pages.
 */
class PageLayoutService
{
    /** Defaults Will set when this feature was specced. */
    public const DEFAULT_ROWS         = 2;
    public const DEFAULT_COLS         = 2;
    public const DEFAULT_COL_WIDTHS   = [65, 32];
    public const DEFAULT_ROW_HEIGHTS  = [32, 65];
    public const DEFAULT_GAP_PCT      = 3;
    public const DEFAULT_MAX_WIDTH_PX = 1280;

    /** Hard caps from the schema CHECK constraints. */
    public const MAX_ROWS = 6;
    public const MAX_COLS = 4;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Return the layout + placements for a page, or null when no layout
     * is configured. Shape:
     *   [
     *     'layout'     => [rows, cols, col_widths, row_heights, gap_pct, max_width_px],
     *     'placements' => [ [row, col, sort_order, block_key, settings, visible_to], ... ]
     *   ]
     */
    public function getForPage(int $pageId): ?array
    {
        $layout = $this->db->fetchOne(
            "SELECT `rows`, cols, col_widths, row_heights, row_styles, cell_styles, gap_pct, max_width_px
               FROM page_layouts WHERE page_id = ?",
            [$pageId]
        );
        if (!$layout) return null;

        $layout['col_widths']  = $this->decodeIntArray($layout['col_widths']);
        $layout['row_heights'] = $this->decodeIntArray($layout['row_heights']);
        $layout['row_styles']  = $this->decodeJsonArray($layout['row_styles']  ?? null);
        $layout['cell_styles'] = $this->decodeJsonArray($layout['cell_styles'] ?? null);
        $layout['rows']        = (int) $layout['rows'];
        $layout['cols']        = (int) $layout['cols'];
        $layout['gap_pct']     = (int) $layout['gap_pct'];
        $layout['max_width_px']= (int) $layout['max_width_px'];

        $placements = $this->db->fetchAll(
            "SELECT id, row_index, col_index, sort_order, block_key, settings, style, visible_to
               FROM page_block_placements
              WHERE page_id = ?
              ORDER BY row_index, col_index, sort_order, id",
            [$pageId]
        );
        foreach ($placements as &$p) {
            $p['settings'] = $p['settings'] ? (json_decode($p['settings'], true) ?: []) : [];
            $p['style']    = $this->decodeJsonArray($p['style'] ?? null);
        }
        unset($p);

        return ['layout' => $layout, 'placements' => $placements];
    }

    /**
     * Insert or update the layout row for a page. Validates ranges +
     * normalises arrays to integers so a ?width=garbage or oversized
     * value can't sneak past the controller's form validation.
     */
    public function saveLayout(int $pageId, array $input): void
    {
        $rows        = max(1, min(self::MAX_ROWS, (int) ($input['rows'] ?? self::DEFAULT_ROWS)));
        $cols        = max(1, min(self::MAX_COLS, (int) ($input['cols'] ?? self::DEFAULT_COLS)));
        $colWidths   = $this->normalisePercentArray($input['col_widths']  ?? self::DEFAULT_COL_WIDTHS,  $cols);
        $rowHeights  = $this->normalisePercentArray($input['row_heights'] ?? self::DEFAULT_ROW_HEIGHTS, $rows);
        $gapPct      = max(0, min(20, (int) ($input['gap_pct'] ?? self::DEFAULT_GAP_PCT)));
        $maxWidthPx  = max(320, min(4096, (int) ($input['max_width_px'] ?? self::DEFAULT_MAX_WIDTH_PX)));

        // Per-row styling: 0-indexed, sanitised through self::sanitiseRowStyle
        // so admins can't paste `bg_color: javascript:…` and have it land in
        // rendered CSS.
        $rowStyles = [];
        foreach ($this->coerceJsonInput($input['row_styles'] ?? null) as $idx => $raw) {
            if (!is_array($raw)) continue;
            $clean = self::sanitiseRowStyle($raw);
            if ($clean) $rowStyles[(int) $idx] = $clean;
        }

        // Per-cell styling: keyed by "row-col". Same sanitisation.
        $cellStyles = [];
        foreach ($this->coerceJsonInput($input['cell_styles'] ?? null) as $key => $raw) {
            if (!is_array($raw)) continue;
            // Accept either "r-c" string keys (admin form) or [r,c] tuples.
            if (!is_string($key) || !preg_match('/^\d+-\d+$/', $key)) continue;
            $clean = self::sanitiseCellStyle($raw);
            if ($clean) $cellStyles[$key] = $clean;
        }

        $this->db->query(
            "REPLACE INTO page_layouts
                (page_id, `rows`, cols, col_widths, row_heights, row_styles, cell_styles, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $pageId, $rows, $cols,
                json_encode($colWidths),
                json_encode($rowHeights),
                empty($rowStyles)  ? null : json_encode($rowStyles,  JSON_FORCE_OBJECT),
                empty($cellStyles) ? null : json_encode($cellStyles, JSON_FORCE_OBJECT),
                $gapPct, $maxWidthPx,
            ]
        );
    }

    /**
     * Replace ALL placements for a page in one transaction. Caller
     * passes the desired final state; this method clears + reinserts.
     * Simpler than a per-row diff and safe because placements are
     * lightweight (no foreign keys pointing at them).
     *
     * Each placement input is shaped like:
     *   ['row'=>int, 'col'=>int, 'sort_order'=>int, 'block_key'=>string,
     *    'settings'=>array|null, 'visible_to'=>'any'|'auth'|'guest']
     */
    public function savePlacements(int $pageId, array $placements): void
    {
        $this->db->transaction(function () use ($pageId, $placements) {
            $this->db->query("DELETE FROM page_block_placements WHERE page_id = ?", [$pageId]);
            foreach ($placements as $p) {
                $key = trim((string) ($p['block_key'] ?? ''));
                if ($key === '') continue;

                $vt = (string) ($p['visible_to'] ?? 'any');
                if (!in_array($vt, ['any','auth','guest'], true)) $vt = 'any';

                $settings = $p['settings'] ?? null;
                if (is_string($settings)) {
                    // Form arrives as a JSON string; decode to validate
                    // before re-encoding, so malformed JSON stores as null
                    // rather than a literal "{bad" that breaks readers.
                    $decoded  = json_decode($settings, true);
                    $settings = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
                }

                // Per-placement wrapper styling. Same JSON-or-array
                // round-trip as settings + same sanitiser as cell styles.
                $style = $p['style'] ?? null;
                if (is_string($style)) {
                    $decoded = json_decode($style, true);
                    $style   = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
                }
                if (is_array($style)) {
                    $style = self::sanitiseCellStyle($style);
                    if (empty($style)) $style = null;
                }

                $this->db->insert('page_block_placements', [
                    'page_id'    => $pageId,
                    'row_index'  => max(0, (int) ($p['row'] ?? 0)),
                    'col_index'  => max(0, (int) ($p['col'] ?? 0)),
                    'sort_order' => max(0, (int) ($p['sort_order'] ?? 0)),
                    'block_key'  => $key,
                    'settings'   => $settings !== null ? json_encode($settings) : null,
                    'style'      => $style    !== null ? json_encode($style)    : null,
                    'visible_to' => $vt,
                ]);
            }
        });
    }

    /** Wipe layout + placements for a page (used by page delete cascade fallback). */
    public function deleteLayout(int $pageId): void
    {
        $this->db->query("DELETE FROM page_block_placements WHERE page_id = ?", [$pageId]);
        $this->db->query("DELETE FROM page_layouts          WHERE page_id = ?", [$pageId]);
    }

    /** Decode a stored JSON array into int[]. Empty / malformed returns []. */
    private function decodeIntArray(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];
        return array_map(fn($v) => (int) $v, $arr);
    }

    /** Decode a stored JSON object/array. Empty / malformed returns []. */
    private function decodeJsonArray($json): array
    {
        if (is_array($json)) return $json;
        if ($json === null || $json === '') return [];
        $arr = json_decode((string) $json, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * Style inputs may arrive from POST as a JSON-encoded string OR an
     * already-decoded associative array. Normalise to array form so the
     * caller doesn't need to think about it.
     */
    private function coerceJsonInput($input): array
    {
        if (is_array($input))  return $input;
        if (is_string($input) && trim($input) !== '') {
            $arr = json_decode($input, true);
            if (is_array($arr)) return $arr;
        }
        return [];
    }

    /**
     * Whitelist a CSS color value. Accepts hex (#rgb / #rrggbb / #rrggbbaa),
     * rgb()/rgba(), hsl()/hsla(), and the CSS named colors. Anything else
     * (including `javascript:`, expression(), url(), or arbitrary strings)
     * comes back as ''. Defense in depth — Will paste a color into a text
     * input, this is what stops it from leaking arbitrary CSS.
     */
    public static function sanitiseColor(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';

        // Hex: #rgb, #rrggbb, #rrggbbaa
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v)) return strtolower($v);

        // rgb(a) / hsl(a) — digits, percent, decimals, commas, spaces, slashes only.
        if (preg_match('/^(rgb|rgba|hsl|hsla)\([\d\s,.\/%-]+\)$/i', $v)) return strtolower($v);

        // CSS named colors. Compact list — covers the common picks; admins
        // who want anything obscure can paste a hex value.
        $named = ['transparent','black','white','silver','gray','red','maroon','yellow','olive',
                  'lime','green','aqua','teal','blue','navy','fuchsia','purple','orange',
                  'pink','brown','beige','gold','indigo','violet','tan','khaki','salmon',
                  'crimson','tomato','coral','linen','ivory'];
        $lc = strtolower($v);
        if (in_array($lc, $named, true)) return $lc;

        return '';
    }

    /**
     * Whitelist a background-image URL. Allows http(s), root-relative
     * paths, or `data:image/...` URIs (small inlined images). Anything
     * else comes back as ''.
     */
    public static function sanitiseBgUrl(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('#^(?:https?://|/|data:image/(?:png|jpeg|gif|svg\+xml|webp);)#i', $v)) {
            return $v;
        }
        return '';
    }

    /** Common style fields shared by row/cell/placement wrappers. */
    private static function sanitiseCommonStyle(array $raw): array
    {
        $out = [];
        $color = self::sanitiseColor((string) ($raw['bg_color'] ?? ''));
        if ($color !== '') $out['bg_color'] = $color;

        $img = self::sanitiseBgUrl((string) ($raw['bg_image'] ?? ''));
        if ($img !== '') $out['bg_image'] = $img;

        $pad = (int) ($raw['padding_px'] ?? 0);
        if ($pad > 0 && $pad <= 200) $out['padding_px'] = $pad;

        $radius = (int) ($raw['radius_px'] ?? 0);
        if ($radius > 0 && $radius <= 200) $out['radius_px'] = $radius;

        $textColor = self::sanitiseColor((string) ($raw['text_color'] ?? ''));
        if ($textColor !== '') $out['text_color'] = $textColor;

        return $out;
    }

    /**
     * Sanitise a row style entry. Adds row-only fields on top of the
     * common ones — full_bleed (bool) and content_padding_px (int).
     */
    public static function sanitiseRowStyle(array $raw): array
    {
        $out = self::sanitiseCommonStyle($raw);

        if (!empty($raw['full_bleed'])) $out['full_bleed'] = true;

        $cpad = (int) ($raw['content_padding_px'] ?? 0);
        if ($cpad > 0 && $cpad <= 200) $out['content_padding_px'] = $cpad;

        return $out;
    }

    /** Sanitise a cell or placement style. Common fields only. */
    public static function sanitiseCellStyle(array $raw): array
    {
        return self::sanitiseCommonStyle($raw);
    }

    /**
     * Coerce input into a $count-length array of clamped percentages.
     * Accepts arrays OR comma-separated strings like "65,32" so the
     * admin form can post either shape. Pads short arrays with the
     * last seen value; truncates long arrays.
     */
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
        // Pad to length using the last seen value (or 50/N split if empty).
        $defaultPad = $last ?: max(1, (int) floor(95 / max(1, $count)));
        while (count($out) < $count) $out[] = $defaultPad;

        return array_slice($out, 0, $count);
    }
}
