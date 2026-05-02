<?php
// core/Container/ContextualBindingBuilder.php
namespace Core\Container;

use Closure;

/**
 * Fluent helper for contextual bindings:
 *
 *   $c->when(AnalyticsController::class)
 *     ->needs(SearchEngine::class)
 *     ->give(MeilisearchDriver::class);
 *
 * Returned by Container::when(). Not created directly.
 */
class ContextualBindingBuilder
{
    private Container $container;
    private string $caller;
    private ?string $abstract = null;

    public function __construct(Container $container, string $caller)
    {
        $this->container = $container;
        $this->caller    = $caller;
    }

    public function needs(string $abstract): self
    {
        $this->abstract = $abstract;
        return $this;
    }

    public function give(Closure|string $concrete): void
    {
        if ($this->abstract === null) {
            throw new ContainerException('Contextual binding requires needs() before give()');
        }
        $this->container->addContextualBinding($this->caller, $this->abstract, $concrete);
    }
}
