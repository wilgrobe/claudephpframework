<?php
// core/Console/CommandRegistry.php
namespace Core\Console;

use Core\Container\Container;

/**
 * Registers commands and dispatches `artisan <name> [args]` to the right one.
 *
 * Typical wiring (see artisan bootstrap):
 *
 *   $registry = new CommandRegistry($container);
 *   $registry->register(CleanupCommand::class);
 *   $registry->register(new HelloCommand());         // instance is fine too
 *   exit($registry->dispatch($argv));
 *
 * Commands can also be contributed by modules via
 * `ModuleProvider::commands(): array` — the registry pulls those in during
 * discovery so a module can ship its own CLI tooling without editing artisan.
 */
class CommandRegistry
{
    /** @var array<string, Command|string> name => Command instance or class name */
    private array $commands = [];

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register a command by class name (lazy) or instance (eager).
     * Lazy class-name registration defers construction until dispatch, which
     * matters for commands that touch heavy services in their constructor.
     */
    public function register(Command|string $command): void
    {
        if (is_string($command)) {
            // Peek at the name without full construction — we need it for
            // routing. Commands shouldn't do expensive work in __construct.
            $instance = $this->container->make($command);
        } else {
            $instance = $command;
        }
        $this->commands[$instance->name()] = $instance;
    }

    /** Look up a registered command by name. */
    public function get(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /** @return Command[] — all registered commands, sorted by name */
    public function all(): array
    {
        ksort($this->commands);
        return $this->commands;
    }

    /**
     * Run the appropriate command for this invocation. Falls back to 'help'
     * when no command is given or the name doesn't match.
     */
    /**
     * Stream errors get written to. Defaults to PHP's STDERR. Tests
     * replace this with a tempfile handle to keep the "Unknown command"
     * banner out of PHPUnit's reporter output.
     *
     * @var resource|null
     */
    private $errStream = null;

    /** @param resource $stream */
    public function setErrorStream($stream): void
    {
        $this->errStream = $stream;
    }

    private function err(): mixed
    {
        return $this->errStream ?? STDERR;
    }

    public function dispatch(array $argv): int
    {
        $name = $argv[1] ?? 'help';

        $cmd = $this->get($name);
        if ($cmd === null) {
            fwrite($this->err(), "Unknown command: $name\n");
            fwrite($this->err(), "Run `php artisan help` to see the command list.\n");
            return 1;
        }

        try {
            return (int) $cmd->handle($argv);
        } catch (\Throwable $e) {
            // Forward to Sentry so cron-driven failures (schedule:run,
            // retry-messages, search:reindex) don't die silently. No-op when
            // SENTRY_DSN is unset, so dev + test runs incur zero network
            // cost. Wrapped in its own try/catch: a Sentry transport error
            // must never change what the CLI user sees.
            try {
                \Core\Services\SentryService::captureException($e);
            } catch (\Throwable $_) {
                // swallow — see SentryService::captureException itself.
            }

            fwrite($this->err(), "\n✗ " . $e::class . ": " . $e->getMessage() . "\n");
            // Stack trace only when APP_DEBUG is on; production CLI runs
            // shouldn't dump internals to any log tailing the stderr stream.
            if (!empty($_ENV['APP_DEBUG']) && filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN)) {
                fwrite($this->err(), $e->getTraceAsString() . "\n");
            }
            return 1;
        }
    }
}
