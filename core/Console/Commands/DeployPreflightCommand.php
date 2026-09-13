<?php
// core/Console/Commands/DeployPreflightCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;

/**
 * `php artisan deploy:preflight [--since=<ref>] [--skip-tests]`
 *
 * The gate a deploy has to pass before code goes live.
 *
 * Motivated by 2026-09-12, when a rebuild of project 36 silently shipped a
 * seven-day-old gate because the dist zips it draws from were stale. The fix
 * was correct, committed, and simply not in the artifact — and the only reason
 * anyone found out was unzipping the thing by hand. A deploy process that never
 * looks inside what it ships will keep doing that.
 *
 * Lives in core so a generated site gets it too: the builder, the framework and
 * every kit run the same command. Each check decides for itself whether it
 * applies here and says SKIP rather than failing when a prerequisite is absent —
 * a kit has no dist/ directory and should not be told it has a stale artifact.
 *
 * Scope is deliberately PRE-deploy and offline: no HTTP, no login, nothing that
 * needs the app serving. Checks that need a running site (`qa:admin-smoke`,
 * `storage-check`, `system:doctor`) verify the deploy AFTERWARDS and are a
 * separate phase — running them here would test the code being replaced.
 *
 * Exit 0 when nothing failed, 1 otherwise, so a deploy hook can gate on it.
 */
final class DeployPreflightCommand extends Command
{
    public function name(): string        { return 'deploy:preflight'; }
    public function description(): string { return 'Pre-deploy gate: tests, migration integrity, changed-file syntax, artifact freshness. Exit 1 on any failure.'; }

    private const OK = 'OK  ';
    private const SKIP = 'SKIP';
    private const FAIL = 'FAIL';

    /** @var array<int, array{0:string,1:string,2:string}> status, label, detail */
    private array $results = [];

    public function handle(array $argv): int
    {
        $since     = $this->option($argv, 'since');
        $skipTests = in_array('--skip-tests', $argv, true);

        $this->line('');
        $this->line('deploy:preflight — checking what is about to ship');
        $this->line(str_repeat('-', 62));

        $this->checkTests($skipTests);
        $this->checkMigrations();
        $this->checkChangedSyntax($since);
        $this->checkArtifactFreshness();

        $this->line('');
        $failed = 0;
        foreach ($this->results as [$status, $label, $detail]) {
            if ($status === self::FAIL) $failed++;
            $this->line(sprintf('  [%s] %-26s %s', $status, $label, $detail));
        }
        $this->line('');

        if ($failed > 0) {
            $this->line("REFUSED — $failed check(s) failed. Nothing was deployed.");
            return 1;
        }
        $this->line('Preflight passed.');
        return 0;
    }

    // ── checks ──────────────────────────────────────────────────────────────

    /** The suite carries the consistency + environment checks, so this is the big one. */
    private function checkTests(bool $skip): void
    {
        if ($skip) {
            $this->add(self::SKIP, 'test suite', 'skipped by --skip-tests');
            return;
        }
        $root    = BASE_PATH;
        $phpunit = $root . '/vendor/bin/phpunit';
        if (!is_file($phpunit) || !is_file($root . '/phpunit.xml')) {
            $this->add(self::SKIP, 'test suite', 'no vendor/bin/phpunit or phpunit.xml here');
            return;
        }
        $out  = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' --no-coverage 2>&1', $out, $code);
        $summary = '';
        foreach (array_reverse($out) as $l) {
            if (preg_match('~^(OK|Tests:|FAILURES|ERRORS)~', trim($l))) { $summary = trim($l); break; }
        }
        $this->add($code === 0 ? self::OK : self::FAIL, 'test suite', $summary !== '' ? $summary : "exit $code");
    }

    /**
     * A migration that will not parse breaks the deploy after the code is already
     * live — the worst possible moment. And a basename COLLISION is worse than
     * that, because it breaks nothing visibly: Migrator::discover() builds
     * $found[$basename] across every registered path and ksorts it, so when two
     * paths contribute the same basename the second silently replaces the first
     * and that migration simply never runs. No error, no skipped-migration
     * notice, just a table that isn't there later.
     *
     * NOT checked: a shared ordering PREFIX. Several migrations share
     * 2026_06_20_000000 and that is fine — discover() keys on the full basename,
     * so their relative order is still deterministic. Flagging it produced five
     * findings, none of them real, which is the fastest way to get a check
     * ignored.
     */
    private function checkMigrations(): void
    {
        // A standalone kit bundles its premium modules into its own modules/ and
        // must NOT also scan a sibling premium checkout — config/modules.php
        // refuses to for the same reason, and a dev machine that happens to have
        // the sibling next door would otherwise report every bundled module as
        // colliding with itself. The flag is the same one ZipBuilder writes.
        $sibling = is_file(BASE_PATH . '/config/standalone.flag')
            ? []
            : (array) glob(dirname(BASE_PATH) . '/claudephpframeworkpremium/modules/*/migrations');

        $dirs = array_filter(array_merge(
            [BASE_PATH . '/database/migrations', BASE_PATH . '/database/central_migrations'],
            (array) glob(BASE_PATH . '/modules/*/migrations'),
            $sibling,
        ), 'is_dir');

        if ($dirs === []) {
            $this->add(self::SKIP, 'migration integrity', 'no migration directories');
            return;
        }

        $problems = [];
        $seen     = [];
        $checked  = 0;

        foreach ($dirs as $dir) {
            foreach ((array) glob($dir . '/*.php') as $file) {
                $file = (string) $file;
                $checked++;
                $base = basename($file, '.php');

                if (isset($seen[$base])) {
                    $problems[] = "basename collision '$base' — one of these never runs: "
                                . $seen[$base] . ' / ' . $file;
                } else {
                    $seen[$base] = $file;
                }

                $out = [];
                $code = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
                if ($code !== 0) $problems[] = 'syntax error in ' . basename($file);
            }
        }

        $this->add(
            $problems === [] ? self::OK : self::FAIL,
            'migration integrity',
            $problems === [] ? "$checked files, basenames unique, all parse"
                             : implode('; ', array_slice($problems, 0, 3))
                               . (count($problems) > 3 ? ' (+' . (count($problems) - 3) . ' more)' : '')
        );
    }

    /**
     * Lint only what this deploy actually changes. A full sweep is ~900 files and
     * around a minute of process spawning here, far longer on a small droplet —
     * slow enough that people would start passing --skip. The changed set is the
     * part a deploy is putting at risk, and it costs a fraction of a second.
     */
    private function checkChangedSyntax(?string $since): void
    {
        if ($since === null || $since === '') {
            $this->add(self::SKIP, 'changed-file syntax', 'pass --since=<ref> to lint the diff');
            return;
        }
        $out = [];
        $code = 0;
        exec('git -C ' . escapeshellarg(BASE_PATH) . ' diff --name-only --diff-filter=ACMR '
            . escapeshellarg($since) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $this->add(self::SKIP, 'changed-file syntax', "git could not resolve '$since'");
            return;
        }

        $bad = [];
        $n = 0;
        foreach ($out as $rel) {
            $rel = trim($rel);
            if ($rel === '' || !str_ends_with(strtolower($rel), '.php')) continue;
            $abs = BASE_PATH . '/' . $rel;
            if (!is_file($abs)) continue;      // deleted or moved away
            $n++;
            $l = []; $c = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($abs) . ' 2>&1', $l, $c);
            if ($c !== 0) $bad[] = $rel;
        }

        if ($n === 0) {
            $this->add(self::OK, 'changed-file syntax', "no PHP changed since $since");
            return;
        }
        $this->add($bad === [] ? self::OK : self::FAIL, 'changed-file syntax',
            $bad === [] ? "$n changed file(s) parse" : 'syntax errors: ' . implode(', ', $bad));
    }

    /**
     * A dist zip older than the source it was built from is a fix that will not
     * reach whoever installs it. This is the check that would have caught the
     * project-36 rebuild shipping a seven-day-old gate.
     *
     * Not a pre-push test on purpose: it is red for as long as a module has been
     * edited and not yet rebuilt, which is most of the time while working. As a
     * commit gate it would only teach people to bypass the gate. At deploy time
     * it is exactly right.
     */
    private function checkArtifactFreshness(): void
    {
        $dist = BASE_PATH . '/dist';
        if (!is_dir($dist)) {
            $this->add(self::SKIP, 'artifact freshness', 'no dist/ here (not a kit builder)');
            return;
        }
        $zips = (array) glob($dist . '/claudephpframework-module-*.zip');
        if ($zips === []) {
            $this->add(self::SKIP, 'artifact freshness', 'no module zips built yet');
            return;
        }

        $roots = array_filter([
            dirname(BASE_PATH) . '/claudephpframeworkpremium/modules',
            BASE_PATH . '/modules',
        ], 'is_dir');

        $stale = [];
        $compared = 0;
        foreach ($zips as $zip) {
            $zip  = (string) $zip;
            $slug = (string) preg_replace('~^claudephpframework-module-|\.zip$~', '', basename($zip));
            $src  = self::findModuleDir($slug, $roots);
            if ($src === null) continue;                    // no source visible; not our call
            $compared++;
            $zipTime = (int) filemtime($zip);
            $newest  = self::newestFileTime($src);
            if ($newest > $zipTime) {
                $stale[] = $slug . ' (source ' . self::ago($newest - $zipTime) . ' newer)';
            }
        }

        if ($compared === 0) {
            $this->add(self::SKIP, 'artifact freshness', 'no module sources visible to compare');
            return;
        }
        $this->add($stale === [] ? self::OK : self::FAIL, 'artifact freshness',
            $stale === []
                ? "$compared zip(s) newer than their source"
                : 'STALE, rebuild with dist:build-all — ' . implode(', ', array_slice($stale, 0, 4))
                  . (count($stale) > 4 ? ' (+' . (count($stale) - 4) . ' more)' : ''));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Premium folder names are a mix — dev-portal keeps its hyphen, activity-feed
     * collapses to activityfeed — so try the same name forms the entitlement gate
     * tries rather than assuming one transform.
     */
    private static function findModuleDir(string $slug, array $roots): ?string
    {
        $s = strtolower(trim($slug));
        if ($s === '') return null;
        $forms = array_unique([
            $s,
            str_replace('-', '_', $s),
            str_replace('_', '-', $s),
            str_replace(['-', '_'], '', $s),
        ]);
        foreach ($roots as $root) {
            foreach ($forms as $f) {
                if (is_dir("$root/$f")) return "$root/$f";
            }
        }
        return null;
    }

    private static function newestFileTime(string $dir): int
    {
        $newest = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $t = (int) $f->getMTime();
            if ($t > $newest) $newest = $t;
        }
        return $newest;
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 3600)  return max(1, intdiv($seconds, 60)) . 'm';
        if ($seconds < 86400) return intdiv($seconds, 3600) . 'h';
        return intdiv($seconds, 86400) . 'd';
    }

    private function add(string $status, string $label, string $detail): void
    {
        $this->results[] = [$status, $label, $detail];
    }
}
