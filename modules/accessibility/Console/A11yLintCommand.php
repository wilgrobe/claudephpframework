<?php
// modules/accessibility/Console/A11yLintCommand.php
namespace Modules\Accessibility\Console;

use Core\Console\Command;
use Modules\Accessibility\Services\A11yLintService;

/**
 * `php artisan a11y:lint [--json] [--errors-only]`
 *
 * Walks app/Views/ + every modules/{name}/Views/ directory and reports
 * WCAG 2.1 AA template-level violations. Exits non-zero on any error
 * finding so it slots into CI pipelines as a gating check.
 *
 * Flags:
 *   --json          machine-readable output (for CI integration)
 *   --errors-only   suppress warnings; only fail on errors
 *   --root=PATH     scan a specific directory instead of the defaults
 */
class A11yLintCommand extends Command
{
    public function name(): string        { return 'a11y:lint'; }
    public function description(): string { return 'Lint templates for WCAG 2.1 AA accessibility violations'; }

    public function handle(array $argv): int
    {
        $svc        = new A11yLintService();
        $jsonOut    = in_array('--json', $argv, true);
        $errorsOnly = in_array('--errors-only', $argv, true);

        $roots = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--root=')) {
                $roots = [substr($arg, 7)];
            }
        }

        $findings = $svc->lintAll($roots);
        if ($errorsOnly) {
            $findings = array_values(array_filter(
                $findings,
                fn($f) => $f['severity'] === A11yLintService::SEVERITY_ERROR
            ));
        }
        $summary = $svc->summarise($findings);

        if ($jsonOut) {
            $this->line(json_encode([
                'summary'  => $summary,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $summary['errors'] > 0 ? 1 : 0;
        }

        if (empty($findings)) {
            $this->line('No accessibility issues found across ' . count($svc->defaultRoots()) . ' template root(s). Clean.');
            return 0;
        }

        // Group findings by file for readable output.
        $byFile = [];
        foreach ($findings as $f) {
            $byFile[$f['file']][] = $f;
        }
        ksort($byFile);

        foreach ($byFile as $file => $items) {
            // Trim BASE_PATH prefix for readability.
            $relFile = $file;
            if (defined('BASE_PATH')) {
                $base = rtrim(BASE_PATH, '/\\');
                if (str_starts_with($file, $base)) {
                    $relFile = ltrim(substr($file, strlen($base)), '/\\');
                }
            }
            $this->line('');
            $this->line($relFile);
            foreach ($items as $f) {
                $sev = $f['severity'] === A11yLintService::SEVERITY_ERROR ? 'ERROR  ' : 'warn   ';
                $this->line(sprintf('  %s %s:%d  [%s]  %s',
                    $sev, basename($file), $f['line'], $f['rule'], $f['message']
                ));
            }
        }

        $this->line('');
        $this->line(sprintf(
            '%d finding(s): %d error(s), %d warning(s) across %d file(s).',
            $summary['total'], $summary['errors'], $summary['warnings'], count($byFile)
        ));
        $this->line('Top rules:');
        foreach (array_slice($summary['by_rule'], 0, 5, true) as $rule => $n) {
            $this->line(sprintf('  %-30s %d', $rule, $n));
        }

        return $summary['errors'] > 0 ? 1 : 0;
    }
}
