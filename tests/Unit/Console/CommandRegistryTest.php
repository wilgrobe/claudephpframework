<?php
// tests/Unit/Console/CommandRegistryTest.php
namespace Tests\Unit\Console;

use Core\Console\Command;
use Core\Console\CommandRegistry;
use Core\Container\Container;
use Tests\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function test_register_and_get_lookup(): void
    {
        $r = new CommandRegistry(new Container());
        $cmd = new class extends Command {
            public function name(): string        { return 'noop'; }
            public function description(): string { return 'no-op test command'; }
            public function handle(array $argv): int { return 0; }
        };
        $r->register($cmd);
        $this->assertSame($cmd, $r->get('noop'));
        $this->assertNull($r->get('nope'));
    }

    public function test_dispatch_runs_the_named_command(): void
    {
        $r = new CommandRegistry(new Container());
        $r->register(new class extends Command {
            public function name(): string        { return 'noop'; }
            public function description(): string { return 'returns 0'; }
            public function handle(array $argv): int { return 0; }
        });
        $code = $r->dispatch(['artisan', 'noop', 'hello', 'world']);
        $this->assertSame(0, $code);
    }

    public function test_dispatch_unknown_command_returns_nonzero(): void
    {
        $r = new CommandRegistry(new Container());
        // dispatch() writes "Unknown command: ..." to STDERR. Inject a
        // tempfile stream via setErrorStream so the banner doesn't bleed
        // into PHPUnit's reporter. The exit code is what we actually care
        // about; the banner content is also asserted on for completeness.
        $tmp = fopen('php://temp', 'w+');
        $r->setErrorStream($tmp);
        $code = $r->dispatch(['artisan', 'does-not-exist']);
        rewind($tmp);
        $captured = stream_get_contents($tmp) ?: '';
        fclose($tmp);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Unknown command: does-not-exist', $captured);
    }

    public function test_all_returns_commands_sorted_by_name(): void
    {
        $r = new CommandRegistry(new Container());
        $bCmd = new class extends Command {
            public function name(): string { return 'b'; }
            public function description(): string { return ''; }
            public function handle(array $argv): int { return 0; }
        };
        $aCmd = new class extends Command {
            public function name(): string { return 'a'; }
            public function description(): string { return ''; }
            public function handle(array $argv): int { return 0; }
        };
        $r->register($bCmd);
        $r->register($aCmd);
        $names = array_keys($r->all());
        $this->assertSame(['a', 'b'], $names);
    }
}
