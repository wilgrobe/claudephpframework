<?php
// core/Container/Container.php
namespace Core\Container;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Container — PSR-11 compatible service container with reflection autowiring.
 *
 *   $c = new Container();
 *
 *   // Transient: new instance each call
 *   $c->bind(MailDriver::class, SmtpMailDriver::class);
 *
 *   // Shared instance
 *   $c->singleton(CacheService::class);
 *
 *   // Register an already-built object
 *   $c->instance(Database::class, Database::getInstance());
 *
 *   // Closure factory — full control over construction
 *   $c->singleton(Router::class, fn($c) => new Router($c->get(Request::class)));
 *
 *   // Resolve — reflection autowires constructor dependencies by type
 *   $controller = $c->get(UserController::class);
 *
 *   // Contextual binding
 *   $c->when(ReportsController::class)
 *     ->needs(SearchEngine::class)
 *     ->give(MeilisearchDriver::class);
 *
 * Not a full DI framework — deliberately thin. No compilation, no tags, no
 * parameter stores beyond what autowiring needs. Targets the 90% case of
 * "give me an instance of this class, wired up".
 *
 * Interface matches PSR-11 signatures so swapping to psr/container later is
 * a one-line `implements` change.
 */
class Container implements ContainerInterface
{
    /** @var array<string, Closure|string> abstract => factory/concrete */
    private array $bindings = [];

    /** @var array<string, bool> abstract => true when the binding is shared */
    private array $shared = [];

    /** @var array<string, mixed> resolved singleton instances */
    private array $instances = [];

    /** @var array<string, array<string, string|Closure>> caller => [abstract => concrete] */
    private array $contextual = [];

    /** Set once in bootstrap so helpers (app()) can find it. */
    private static ?Container $globalInstance = null;

    public function __construct()
    {
        // Let the container resolve itself when requested by type.
        $this->instance(self::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /** Transient binding: factory runs on every get(). */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        $this->shared[$abstract]   = false;
        unset($this->instances[$abstract]);
    }

    /** Shared binding: factory runs once, result cached. */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        $this->shared[$abstract]   = true;
        unset($this->instances[$abstract]);
    }

    /** Register an already-built object as the resolution for $abstract. */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->shared[$abstract]    = true;
    }

    /** Start a contextual binding: when(X)->needs(Y)->give(Z). */
    public function when(string $caller): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $caller);
    }

    /** Internal: contextual bindings register here. */
    public function addContextualBinding(string $caller, string $abstract, Closure|string $concrete): void
    {
        $this->contextual[$caller][$abstract] = $concrete;
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    /** PSR-11 has(): true when the abstract is bound or is a concrete class. */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /** PSR-11 get(): resolve $id, autowiring as needed. */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Resolve $abstract, optionally with overridden constructor parameters
     * (keyed by parameter name). Used by make('Foo', ['id' => 42]) to pass
     * runtime-only args without binding them.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } elseif (is_string($concrete)) {
            $object = $this->build($concrete, $parameters);
        } else {
            throw new ContainerException("Unresolvable binding for [$abstract]");
        }

        if (($this->shared[$abstract] ?? false) === true) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /** Instantiate $concrete, autowiring constructor dependencies. */
    private function build(string $concrete, array $parameters = [], ?string $forCaller = null): object
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new ContainerException("Class [$concrete] does not exist", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class [$concrete] is not instantiable (abstract or interface with no binding)");
        }

        $ctor = $reflector->getConstructor();
        if ($ctor === null) {
            return new $concrete();
        }

        $resolvedArgs = [];
        foreach ($ctor->getParameters() as $param) {
            $resolvedArgs[] = $this->resolveDependency($param, $parameters, $forCaller ?? $concrete);
        }

        return $reflector->newInstanceArgs($resolvedArgs);
    }

    /** Resolve one constructor parameter: overrides > contextual > type hint > default. */
    private function resolveDependency(ReflectionParameter $param, array $overrides, string $caller): mixed
    {
        $name = $param->getName();

        // 1. Explicit override wins
        if (array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }

        $type = $param->getType();

        // 2. No type or primitive type → use default or fail
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw new ContainerException(
                "Unresolvable primitive parameter [$name] in [{$param->getDeclaringClass()?->getName()}]"
            );
        }

        $abstract = $type->getName();

        // 3. Contextual binding for this caller?
        if (isset($this->contextual[$caller][$abstract])) {
            $concrete = $this->contextual[$caller][$abstract];
            return $concrete instanceof Closure ? $concrete($this) : $this->make($concrete);
        }

        // 4. Recursively resolve from the container
        try {
            return $this->make($abstract);
        } catch (ContainerException $e) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($type->allowsNull()) {
                return null;
            }
            throw $e;
        }
    }

    /** Invoke a callable, autowiring its parameters. Supports [Class, 'method']. */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $instance = is_object($target) ? $target : $this->make($target);
            $reflector = new \ReflectionMethod($instance, $method);
            $args = [];
            foreach ($reflector->getParameters() as $param) {
                $args[] = $this->resolveDependency($param, $parameters, $reflector->getDeclaringClass()->getName());
            }
            return $reflector->invokeArgs($instance, $args);
        }
        $reflector = new \ReflectionFunction(Closure::fromCallable($callable));
        $args = [];
        foreach ($reflector->getParameters() as $param) {
            $args[] = $this->resolveDependency($param, $parameters, '__closure__');
        }
        return $reflector->invokeArgs($args);
    }

    // ── Global accessor ───────────────────────────────────────────────────────

    public static function setGlobal(Container $container): void
    {
        self::$globalInstance = $container;
    }

    public static function global(): Container
    {
        return self::$globalInstance ??= new self();
    }
}
