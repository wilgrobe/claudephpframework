<?php
// core/Module/BlockRegistry.php
namespace Core\Module;

/**
 * Aggregates BlockDescriptors contributed by every loaded module.
 *
 * Populated by ModuleRegistry::loadAll() AFTER the dependency check —
 * modules that got skipped (disabled_dependency) don't contribute their
 * blocks, which is the whole point of the gate.
 *
 * The registry is process-local and re-built per request. Production
 * deploys with `php artisan module:cache` short-circuit the directory
 * scan, but block aggregation still runs because the block list isn't
 * cached (closures don't serialize).
 *
 * Lookup methods are intentionally read-only after registration. The
 * page composer calls render() with the resolved key; missing keys
 * return null so the renderer can fall back gracefully (e.g. show
 * "Block <key> is unavailable" placeholder rather than blowing up the
 * whole page when an admin orphans a placement by removing a module).
 */
class BlockRegistry
{
    /** @var array<string, BlockDescriptor> indexed by block key */
    private array $blocks = [];

    /**
     * Register every BlockDescriptor a module contributes. Throws if a key
     * is already registered — duplicate keys are a programmer error
     * (collision between two modules) and silently overwriting would
     * make the bug hard to spot.
     *
     * @param BlockDescriptor[] $descriptors
     */
    public function registerMany(array $descriptors): void
    {
        foreach ($descriptors as $d) {
            if (!$d instanceof BlockDescriptor) {
                throw new \InvalidArgumentException(
                    'BlockRegistry::registerMany expects BlockDescriptor instances'
                );
            }
            if (isset($this->blocks[$d->key])) {
                throw new \RuntimeException(
                    "Duplicate block key: {$d->key} (already registered by another module)"
                );
            }
            $this->blocks[$d->key] = $d;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->blocks[$key]);
    }

    public function get(string $key): ?BlockDescriptor
    {
        return $this->blocks[$key] ?? null;
    }

    /** @return array<string, BlockDescriptor> */
    public function all(): array
    {
        return $this->blocks;
    }

    /**
     * Render a block by key. Returns the rendered HTML string, or null if
     * the key isn't registered. Caller decides how to handle the missing
     * case — a placeholder for admins, silent skip for end users, etc.
     *
     * On exception, behavior splits by viewer:
     *   - Admins / SAs see a small inline error card with the exception
     *     class + truncated message. This is what we want during dev:
     *     a broken block doesn't blow up the page, but the failure
     *     surfaces to the people who can fix it instead of being
     *     silently swallowed (the swallow pattern bit us with both
     *     store.my_cart_summary's totals() arg-shape bug and the
     *     coreblocks SystemStatus probe message).
     *   - Everyone else gets the original silent '' so a buggy block
     *     never leaks an exception trace to end users.
     */
    public function render(string $key, array $context = [], array $settings = []): ?string
    {
        $d = $this->get($key);
        if ($d === null) return null;

        $merged = array_replace($d->defaultSettings, $settings);
        try {
            return ($d->render)($context, $merged);
        } catch (\Throwable $e) {
            error_log("[BlockRegistry] render failed for {$key}: " . $e->getMessage());

            // Admin-visible error card. Auth is a singleton; calling it
            // here is fine and keeps the call site self-contained
            // (callers don't need to know about the admin/non-admin
            // split). If Auth isn't booted (CLI?) we fall through to ''.
            try {
                $auth = \Core\Auth\Auth::getInstance();
                if ($auth->hasRole(['super-admin', 'admin'])) {
                    $exClass = htmlspecialchars((new \ReflectionClass($e))->getShortName(), ENT_QUOTES | ENT_HTML5);
                    $msg     = $e->getMessage();
                    if (strlen($msg) > 200) $msg = substr($msg, 0, 197) . '…';
                    $msg     = htmlspecialchars($msg, ENT_QUOTES | ENT_HTML5);
                    $keyEsc  = htmlspecialchars($key, ENT_QUOTES | ENT_HTML5);
                    return '<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:6px;padding:.65rem .85rem;font-size:12.5px;line-height:1.45;font-family:ui-monospace,Menlo,Consolas,monospace">'
                         . '<div style="color:#991b1b;font-weight:700;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em">⚠ Block render failed (admin-only message)</div>'
                         . '<div style="color:#7f1d1d;margin-top:.3rem"><strong>' . $keyEsc . '</strong> threw <strong>' . $exClass . '</strong>:</div>'
                         . '<div style="color:#374151;margin-top:.2rem;word-break:break-word">' . $msg . '</div>'
                         . '</div>';
                }
            } catch (\Throwable) {
                // Auth not booted, registry not bound, etc — fall through
                // to silent '' so we don't escalate one failure into two.
            }
            return '';
        }
    }

    /**
     * Group blocks by category for the page-composer picker. Categories
     * are arbitrary module-chosen strings (e.g. "Groups", "Commerce",
     * "Stats"); blocks within a category sort by label.
     *
     * @return array<string, BlockDescriptor[]>
     */
    public function byCategory(): array
    {
        $grouped = [];
        foreach ($this->blocks as $d) {
            $grouped[$d->category][] = $d;
        }
        foreach ($grouped as &$arr) {
            usort($arr, fn(BlockDescriptor $a, BlockDescriptor $b) => strcasecmp($a->label, $b->label));
        }
        ksort($grouped);
        return $grouped;
    }
}
