<?php
// core/Console/Command.php
namespace Core\Console;

/**
 * Base class for artisan commands. Each command ships in its own file under
 * core/Console/Commands/ (or a module's Commands/ directory) and registers
 * itself with the CommandRegistry at bootstrap.
 *
 * A minimal command:
 *
 *   class HelloCommand extends Command {
 *       public function name(): string        { return 'hello'; }
 *       public function description(): string { return 'Say hi'; }
 *       public function handle(array $argv): int {
 *           $this->info("Hello, {$argv[2]}!");
 *           return 0;
 *       }
 *   }
 *
 * Invoke:  php artisan hello world
 */
abstract class Command
{
    /** Command name (e.g. "make:controller"). Kebab + colons; no spaces. */
    abstract public function name(): string;

    /** One-line description shown in `artisan help`. */
    abstract public function description(): string;

    /**
     * Run the command. $argv is the full original argv array; $argv[0] is
     * the script name, $argv[1] is the command itself, extras follow.
     * Return 0 on success, non-zero on failure.
     */
    abstract public function handle(array $argv): int;

    /** Optional usage hint shown in `artisan help <name>`. Default: name only. */
    public function usage(): string
    {
        return 'php artisan ' . $this->name();
    }

    // ── Output helpers ────────────────────────────────────────────────────────

    protected function info(string $msg): void    { $this->line($msg); }
    protected function success(string $msg): void { $this->line("  ✓ $msg"); }
    protected function warn(string $msg): void    { fwrite(STDERR, "  ! $msg\n"); }
    protected function error(string $msg): void   { fwrite(STDERR, "  ✗ $msg\n"); }
    protected function line(string $msg = ''): void { fwrite(STDOUT, "$msg\n"); }

    /** Pull a positional arg from $argv, with an optional default. */
    protected function arg(array $argv, int $index, ?string $default = null): ?string
    {
        return $argv[$index] ?? $default;
    }

    /**
     * Pull --flag=value or --flag value from $argv. Returns the first hit,
     * null if absent. `--flag` alone (no value) returns the string "".
     */
    protected function option(array $argv, string $name): ?string
    {
        for ($i = 2; $i < count($argv); $i++) {
            $a = $argv[$i];
            if ($a === "--$name") {
                return $argv[$i + 1] ?? '';
            }
            if (str_starts_with($a, "--$name=")) {
                return substr($a, strlen("--$name="));
            }
        }
        return null;
    }
}
