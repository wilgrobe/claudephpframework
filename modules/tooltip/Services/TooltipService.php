<?php
// modules/tooltip/Services/TooltipService.php
namespace Modules\Tooltip\Services;

use Core\Database\Database;
use Core\Module\SubmoduleRegistry;

/**
 * Tooltip read/write API. Content is stored raw (per content_format) and
 * rendered + sanitised at read time by TooltipRenderer, so a format change
 * re-renders without a migration.
 *
 * `get()` applies per-page/route/role overrides (the `per-page-overrides`
 * submodule) and is memoised per (slug, context) for the request.
 */
class TooltipService
{
    private Database $db;
    /** @var array<string, array|null> */
    private array $memo = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function find(int $id): ?array        { return $this->db->fetchOne("SELECT * FROM tooltips WHERE id = ?", [$id]); }
    public function findBySlug(string $s): ?array { return $this->db->fetchOne("SELECT * FROM tooltips WHERE slug = ?", [$s]); }

    /**
     * Resolve a slug to its (override-applied) tooltip row, active only.
     * The resolved display source is exposed as `_content` (raw — the caller /
     * renderer sanitises). Returns null when missing or inactive.
     *
     * @param array{page?:string,route?:string,roles?:string[]} $context
     */
    public function get(string $slug, array $context = []): ?array
    {
        $key = $slug . '|' . md5(json_encode($context) ?: '');
        if (array_key_exists($key, $this->memo)) return $this->memo[$key];

        $tip = $this->db->fetchOne("SELECT * FROM tooltips WHERE slug = ? AND is_active = 1", [$slug]);
        if (!$tip) return $this->memo[$key] = null;

        $tip['_content'] = (string) ($tip['content_html'] ?? '');
        if (SubmoduleRegistry::featureEnabled('tooltip', 'per-page-overrides')) {
            $ov = $this->resolveOverride((int) $tip['id'], $context);
            if ($ov !== null) $tip['_content'] = $ov;
        }
        return $this->memo[$key] = $tip;
    }

    /**
     * First active override matching the context. Precedence: page > route >
     * user_role (most specific first).
     */
    private function resolveOverride(int $tooltipId, array $context): ?string
    {
        $rows = $this->db->fetchAll(
            "SELECT scope, scope_value, content_html FROM tooltip_overrides
              WHERE tooltip_id = ? AND is_active = 1",
            [$tooltipId]
        );
        if (!$rows) return null;

        $page  = (string) ($context['page'] ?? '');
        $route = (string) ($context['route'] ?? '');
        $roles = array_map('strval', (array) ($context['roles'] ?? []));

        foreach (['page', 'route', 'user_role'] as $scope) {
            foreach ($rows as $r) {
                if ($r['scope'] !== $scope) continue;
                $val = (string) $r['scope_value'];
                $hit = match ($scope) {
                    'page'      => $val !== '' && $val === $page,
                    'route'     => $val !== '' && $val === $route,
                    'user_role' => in_array($val, $roles, true),
                    default     => false,
                };
                if ($hit) return (string) ($r['content_html'] ?? '');
            }
        }
        return null;
    }

    /** Render a slug to inline HTML. Empty string when missing / inactive / empty. */
    public function render(string $slug, string $triggerText, array $opts = [], array $context = []): string
    {
        $tip = $this->get($slug, $context);
        if (!$tip) return '';
        $renderer = new TooltipRenderer();
        $opts['content'] = $renderer->content($tip, $tip['_content'] ?? null);
        return $renderer->renderInline($tip, $triggerText, $opts);
    }

    /** Increment view_count (no-op unless the analytics submodule is on). */
    public function track(string $slug, string $event = 'view'): bool
    {
        if (!SubmoduleRegistry::featureEnabled('tooltip', 'analytics')) return false;
        $n = $this->db->query("UPDATE tooltips SET view_count = view_count + 1 WHERE slug = ? AND is_active = 1", [$slug])->rowCount();
        return $n > 0;
    }

    /** @return array{rows:array,total:int,page:int,pages:int} */
    public function listForAdmin(?int $categoryId, ?string $search, int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $args  = [];
        if ($categoryId !== null && $categoryId > 0) { $where[] = 't.category_id = ?'; $args[] = $categoryId; }
        if ($search !== null && trim($search) !== '') {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)) . '%';
            $where[] = '(t.slug LIKE ? OR t.label LIKE ? OR t.content_html LIKE ?)';
            array_push($args, $needle, $needle, $needle);
        }
        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM tooltips t $clause", $args);

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->fetchAll(
            "SELECT t.*, c.name AS category_name
               FROM tooltips t
               LEFT JOIN tooltip_categories c ON c.id = t.category_id
               $clause
              ORDER BY t.updated_at DESC
              LIMIT $perPage OFFSET $offset",
            $args
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function create(int $userId, array $data): int
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') throw new \InvalidArgumentException('Tooltip label is required.');
        $slug = $this->uniqueSlug((string) ($data['slug'] ?? $label));
        return $this->db->insert('tooltips', $this->columns($data, [
            'slug'               => $slug,
            'label'              => $label,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]));
    }

    public function update(int $id, int $userId, array $data): bool
    {
        $tip = $this->find($id);
        if (!$tip) return false;
        $set = $this->columns($data, ['updated_by_user_id' => $userId]);
        // slug may change but must stay unique
        if (isset($data['slug']) && trim((string) $data['slug']) !== '' && $data['slug'] !== $tip['slug']) {
            $set['slug'] = $this->uniqueSlug((string) $data['slug'], $id);
        }
        if (isset($data['label'])) $set['label'] = trim((string) $data['label']);
        $this->db->update('tooltips', $set, 'id = ?', [$id]);
        return true;
    }

    public function delete(int $id): bool { $this->db->delete('tooltips', 'id = ?', [$id]); return true; }

    public function toggleActive(int $id): bool
    {
        $this->db->query("UPDATE tooltips SET is_active = 1 - is_active WHERE id = ?", [$id]);
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        return $this->db->fetchAll("SELECT * FROM tooltip_categories ORDER BY sort_order ASC, name ASC");
    }

    /** @return array<int,array<string,mixed>> */
    public function overridesFor(int $tooltipId): array
    {
        return $this->db->fetchAll("SELECT * FROM tooltip_overrides WHERE tooltip_id = ? ORDER BY scope, scope_value", [$tooltipId]);
    }

    /** Whitelist the writable columns from a form payload. */
    private function columns(array $data, array $forced): array
    {
        $out = $forced;
        if (array_key_exists('content_html', $data))   $out['content_html'] = (string) $data['content_html'];
        if (isset($data['content_format'])) $out['content_format'] = $this->enum((string) $data['content_format'], ['plain','markdown','html'], 'markdown');
        if (array_key_exists('category_id', $data))     $out['category_id'] = ($data['category_id'] ?? '') !== '' ? (int) $data['category_id'] : null;
        if (isset($data['placement'])) $out['placement'] = $this->enum((string) $data['placement'], ['top','right','bottom','left','auto'], 'auto');
        if (isset($data['theme']))     $out['theme']     = $this->enum((string) $data['theme'], ['default','dark','light','accent','warning','info'], 'default');
        if (isset($data['trigger']))   $out['trigger']   = $this->enum((string) $data['trigger'], ['hover','click','focus','manual'], 'hover');
        if (isset($data['max_width_px']))  $out['max_width_px']  = max(80, min(640, (int) $data['max_width_px']));
        if (isset($data['show_delay_ms'])) $out['show_delay_ms'] = max(0, min(5000, (int) $data['show_delay_ms']));
        if (isset($data['hide_delay_ms'])) $out['hide_delay_ms'] = max(0, min(5000, (int) $data['hide_delay_ms']));
        if (isset($data['is_active']))     $out['is_active']     = (int) (bool) $data['is_active'];
        return $out;
    }

    private function enum(string $v, array $allowed, string $default): string
    {
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private function uniqueSlug(string $base, int $ignoreId = 0): string
    {
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', strtolower(trim($base)));
        $slug = trim((string) $slug, '-._') ?: 'tooltip';
        $slug = substr($slug, 0, 80);
        $try = $slug; $i = 1;
        while (true) {
            $row = $this->db->fetchOne("SELECT id FROM tooltips WHERE slug = ?", [$try]);
            if (!$row || (int) $row['id'] === $ignoreId) return $try;
            $try = substr($slug, 0, 76) . '-' . (++$i);
        }
    }
}
