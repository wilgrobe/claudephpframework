<?php
// core/Console/Commands/HelpCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\CommandRegistry;

/**
 * `php artisan help`           — list all commands, grouped by category
 * `php artisan help <name>`    — detail page for one command (usage + description)
 *
 * Grouping is inferred from the command name: everything before the first
 * colon is the group (e.g. "make:" or "migrate:"), ungrouped commands go
 * under "general".
 */
class HelpCommand extends Command
{
    private CommandRegistry $registry;

    public function __construct(CommandRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function name(): string        { return 'help'; }
    public function description(): string { return 'List commands, or show details for one'; }
    public function usage(): string       { return 'php artisan help [command]'; }

    public function handle(array $argv): int
    {
        $target = $argv[2] ?? null;

        if ($target !== null) {
            $cmd = $this->registry->get($target);
            if ($cmd === null) {
                $this->error("Unknown command: $target");
                return 1;
            }
            $this->line($cmd->name() . '  —  ' . $cmd->description());
            $this->line('');
            $this->line('Usage: ' . $cmd->usage());
            return 0;
        }

        $this->line('PHP Framework — artisan CLI');
        $this->line('');

        // Group by prefix before the first ':'. Ungrouped -> 'general'.
        $groups = [];
        foreach ($this->registry->all() as $cmd) {
            $name  = $cmd->name();
            $group = str_contains($name, ':') ? explode(':', $name, 2)[0] : 'general';
            $groups[$group][] = $cmd;
        }
        ksort($groups);

        // Always render 'general' first when present.
        if (isset($groups['general'])) {
            $g = $groups['general'];
            unset($groups['general']);
            $groups = ['general' => $g] + $groups;
        }

        foreach ($groups as $group => $cmds) {
            $this->line(strtoupper($group));
            foreach ($cmds as $cmd) {
                $this->line(sprintf('  %-22s  %s', $cmd->name(), $cmd->description()));
            }
            $this->line('');
        }
        return 0;
    }
}
