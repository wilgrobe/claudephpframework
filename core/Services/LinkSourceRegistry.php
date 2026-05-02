<?php
// core/Services/LinkSourceRegistry.php
namespace Core\Services;

use Core\Module\ModuleRegistry;

/**
 * Aggregates every active LinkSource into a single grouped catalogue
 * the menu-builder palette can render.
 *
 * Discovery: walks every active ModuleProvider, calls its linkSources()
 * hook, instantiates each returned class lazily, indexes the resulting
 * sources by their name(). One framework-level source ("framework", for
 * built-in routes like /dashboard, /profile, /login) is registered
 * inline before module discovery so the palette always shows it even
 * on a fresh install with no other modules contributing.
 *
 * Result is cached in-memory per request; the menu admin page typically
 * needs it once. We don't persist the cache because the underlying queries
 * (pages, forms, etc.) are cheap and can change between requests via
 * other admin actions - re-running them on each appearance page load
 * keeps the palette fresh.
 */
class LinkSourceRegistry
{
    /** @var LinkSource[] keyed by name() */
    private array $sources = [];
    private bool  $bootstrapped = false;

    public function __construct(
        private ?ModuleRegistry $modules = null
    ) {}

    /** Register a LinkSource directly (also called internally during bootstrap). */
    public function register(LinkSource $source): void
    {
        $this->sources[$source->name()] = $source;
    }

    /**
     * Run the bootstrap once: register the framework-level source + walk
     * every active ModuleProvider's linkSources() hook. Idempotent.
     */
    private function bootstrap(): void
    {
        if ($this->bootstrapped) return;
        $this->bootstrapped = true;

        // Framework source - well-known built-in routes, hardcoded so an
        // empty install still has Dashboard / Profile / Login etc. in the
        // palette without depending on any module.
        $this->register(new FrameworkLinkSource());

        // Module-contributed sources. Resolved via the container so the
        // registry stays usable from any context that has access to the
        // global container (admin controllers, CLI commands, etc.).
        $modules = $this->modules ?? \Core\Container\Container::global()->get(ModuleRegistry::class);
        if (!$modules instanceof ModuleRegistry) return;

        foreach ($modules->all() as $provider) {
            $classes = (array) $provider->linkSources();
            foreach ($classes as $class) {
                if (!is_string($class) || !class_exists($class)) continue;
                try {
                    $instance = new $class();
                    if ($instance instanceof LinkSource) {
                        $this->register($instance);
                    }
                } catch (\Throwable $e) {
                    error_log('[LinkSourceRegistry] failed to bootstrap ' . $class . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return array<string, array{label:string,items:array}> grouped by source name
     */
    public function all(): array
    {
        $this->bootstrap();
        $out = [];
        foreach ($this->sources as $name => $source) {
            try {
                $items = $source->items();
            } catch (\Throwable $e) {
                error_log('[LinkSourceRegistry] ' . $name . '->items() failed: ' . $e->getMessage());
                $items = [];
            }
            $out[$name] = [
                'label' => $source->label(),
                'items' => $items,
            ];
        }
        return $out;
    }
}
