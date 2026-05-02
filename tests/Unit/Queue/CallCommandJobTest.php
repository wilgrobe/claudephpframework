<?php
// tests/Unit/Queue/CallCommandJobTest.php
namespace Tests\Unit\Queue;

use Core\Console\Command;
use Core\Console\CommandRegistry;
use Core\Container\Container;
use Core\Queue\Jobs\CallCommandJob;
use Tests\TestCase;

/** Spy command that records its argv and returns whatever was set on it. */
final class SpyCommand extends Command
{
    public array $seenArgv = [];
    public int   $exit      = 0;

    public function name(): string        { return 'spy-cmd'; }
    public function description(): string { return 'spy'; }
    public function handle(array $argv): int
    {
        $this->seenArgv = $argv;
        return $this->exit;
    }
}

final class CallCommandJobTest extends TestCase
{
    public function test_handle_dispatches_to_registered_command(): void
    {
        $c        = $this->bootContainer();
        $registry = new CommandRegistry($c);
        $spy      = new SpyCommand();
        $registry->register($spy);
        $c->instance(CommandRegistry::class, $registry);

        $job = new CallCommandJob();
        $job->command = 'spy-cmd';
        $job->args    = ['alpha', 'beta'];
        $job->handle();

        $this->assertSame(['artisan', 'spy-cmd', 'alpha', 'beta'], $spy->seenArgv);
    }

    public function test_handle_throws_on_unknown_command(): void
    {
        $c        = $this->bootContainer();
        $registry = new CommandRegistry($c);
        $c->instance(CommandRegistry::class, $registry);

        $job = new CallCommandJob();
        $job->command = 'no-such-command';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown command: no-such-command');
        $job->handle();
    }

    public function test_handle_throws_when_command_returns_nonzero(): void
    {
        $c        = $this->bootContainer();
        $registry = new CommandRegistry($c);
        $spy      = new SpyCommand();
        $spy->exit = 7;
        $registry->register($spy);
        $c->instance(CommandRegistry::class, $registry);

        $job = new CallCommandJob();
        $job->command = 'spy-cmd';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("exited with code 7");
        $job->handle();
    }
}
