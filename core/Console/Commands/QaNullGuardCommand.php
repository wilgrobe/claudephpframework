<?php

namespace Core\Console\Commands;

use Core\Console\Command;

/**
 * Find unguarded reads of a value that can be null.
 *
 *   php artisan qa:null-guards                 scan modules/
 *   php artisan qa:null-guards app core        scan those paths instead
 *   php artisan qa:null-guards --all           include low-confidence hits
 *   php artisan qa:null-guards --self-test     prove the scanner can find a planted bug
 *
 * The shape it hunts: a call that can return null — a method declared
 * `: ?array`, or the framework's fetchOne()/fetch(), which return null on
 * no-row — assigned to a variable, and that variable then indexed WITHOUT a
 * guard. `$x['k'] ?? null` is fine. A bare `$x['k']` on a null $x emits
 * "Trying to access array offset on null", and if the value is then passed to
 * a parameter typed `array` it is a TypeError — a fatal, not a warning.
 *
 * ⚠ WHY --self-test EXISTS, AND WHY IT RUNS BY DEFAULT BEFORE EVERY SCAN.
 * Three separate versions of this scanner reported ZERO findings across the
 * whole codebase, and each zero read like a clean bill of health:
 *   1. whitespace tokens were left in the stream, so `$v = …` never matched
 *      (the token after `$v` is a SPACE) and nothing was detected anywhere;
 *   2. a per-access `$v['k'] ?? null` was treated as a scope guard, so twelve
 *      guarded reads marked a variable safe for an unguarded thirteenth —
 *      which is exactly the bug being hunted, so it was blind to its target;
 *   3. variables were tracked file-wide, inventing cross-function hits and
 *      losing tracking early.
 * A scanner that finds nothing is worth precisely as much as its proof that
 * it can find something. The self-test plants the known-bug shape in a
 * temporary file and fails the run if it is not reported.
 *
 * Resolution of "can this return null" is per CLASS, not per method name.
 * Name-only matching taints every unrelated get(): array with one unrelated
 * get(): ?array, which is most of what a first pass reports. Receivers that
 * can be resolved statically ($this->, self::, Foo::, (new Foo)->) are
 * decided from the declaration; anything else is reported only under --all
 * and labelled, because the receiver is a guess.
 *
 * Known blind spots, stated so a clean run is not over-read: direct chaining
 * `->method(…)['k']`, property reads, nullability implied by a body rather
 * than declared in the signature, and guards in a branch the use is not in.
 */
final class QaNullGuardCommand extends Command
{
    public function name(): string        { return 'qa:null-guards'; }
    public function description(): string { return 'Find unguarded array reads of values that can be null.'; }
    public function usage(): string
    {
        return "php artisan qa:null-guards [path ...] [--all] [--self-test] [--quiet-pass]\n"
             . "         --all         include hits whose receiver could not be resolved\n"
             . "         --self-test   only prove the scanner detects a planted bug, then exit";
    }

    /** Framework accessors that return null when there is no row. */
    private const ALWAYS_NULLABLE = ['fetchOne' => true, 'fetch' => true];

    public function handle(array $argv): int
    {
        $all      = in_array('--all', $argv, true);
        $selfOnly = in_array('--self-test', $argv, true);
        // argv[0] is 'artisan' and argv[1] the command name - positional args
        // start at 2, matching arg($argv, 2) as the other commands use it.
        $paths    = array_values(array_filter(array_slice($argv, 2), static fn($a) => $a !== '' && $a[0] !== '-'));
        if (!$paths) $paths = ['modules'];

        // Always self-test first. A silent scanner is the failure mode here.
        if (!$this->selfTest()) {
            $this->error('Self-test FAILED — the scanner did not detect a planted bug.');
            $this->line('  Its output cannot be trusted; do not read a clean run as clean.');
            return 1;
        }
        $this->success('Self-test passed — the scanner detects the planted bug shape.');
        if ($selfOnly) return 0;

        $files = [];
        foreach ($paths as $p) {
            $root = $this->resolve($p);
            if (!is_dir($root)) { $this->error("Not a directory: $p"); return 1; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    $files[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
        sort($files);
        $this->line('');
        $this->line('Scanning ' . count($files) . ' file(s) in ' . implode(', ', $paths) . ' …');

        $nullable = $this->declaredNullability($files);
        $findings = [];
        foreach ($files as $f) {
            foreach ($this->scanFile($f, $nullable) as $hit) $findings[] = $hit;
        }

        $sure  = array_values(array_filter($findings, static fn($f) => $f['confident']));
        $maybe = array_values(array_filter($findings, static fn($f) => !$f['confident']));

        $this->line('');
        foreach ($sure as $f) $this->report($f, false);
        if ($all) foreach ($maybe as $f) $this->report($f, true);

        $this->line('');
        $this->line(sprintf('%d finding(s)%s.', count($sure),
            $maybe ? sprintf('  (+%d with an unresolved receiver — rerun with --all)', count($maybe)) : ''));
        if (!$sure) $this->line('  Note the blind spots in this command\'s docblock before reading that as clean.');

        return $sure ? 1 : 0;   // non-zero so CI can gate on it
    }

    private function report(array $f, bool $weak): void
    {
        $this->line(sprintf('  %s:%d', $f['file'], $f['line']));
        $this->line(sprintf('      $%s from %s()%s — indexed without a guard (assigned line %d)',
            ltrim($f['var'], '$'), $f['call'], $weak ? ' [receiver unresolved]' : '', $f['at']));
        if ($f['snip'] !== '') $this->line('      ' . $f['snip']);
    }

    private function resolve(string $p): string
    {
        if (preg_match('~^([A-Za-z]:[\\\\/]|/)~', $p)) return rtrim($p, '/\\');
        return rtrim((defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/' . $p, '/\\');
    }

    /** Tokens minus whitespace/comments: [text, line, isVariable, tokenId]. */
    private function tokens(string $src): array
    {
        $out = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out[] = [$t[1], $t[2], $t[0] === T_VARIABLE, $t[0]];
            } else {
                $out[] = [$t, $out ? end($out)[1] : 0, false, null];
            }
        }
        return $out;
    }

    /**
     * Map "ShortClassName::method" => true|false (nullable-array or not).
     * Both answers are recorded: knowing a method is NOT nullable is what
     * clears the false positives a name-only match invents.
     */
    private function declaredNullability(array $files): array
    {
        $map = [];
        foreach ($files as $path) {
            $src = @file_get_contents($path);
            if ($src === false) continue;
            $toks = $this->tokens($src);
            $class = null; $depth = 0; $classDepth = null;
            for ($i = 0, $n = count($toks); $i < $n; $i++) {
                $t = $toks[$i];
                if ($t[0] === '{') { $depth++; continue; }
                if ($t[0] === '}') { $depth--; if ($classDepth !== null && $depth < $classDepth) { $class = null; $classDepth = null; } continue; }
                if (in_array($t[3], [T_CLASS, T_TRAIT, T_INTERFACE], true) && ($toks[$i + 1][3] ?? null) === T_STRING) {
                    $class = $toks[$i + 1][0]; $classDepth = $depth + 1; continue;
                }
                if ($t[3] !== T_FUNCTION || ($toks[$i + 1][3] ?? null) !== T_STRING) continue;
                $method = $toks[$i + 1][0];
                // Walk the signature to the ':' return type (or the body).
                $j = $i + 2; $d = 0; $ret = '';
                for (; $j < $n; $j++) {
                    if ($toks[$j][0] === '(') $d++;
                    elseif ($toks[$j][0] === ')') { $d--; if ($d === 0) { $j++; break; } }
                }
                if (($toks[$j][0] ?? '') === ':') {
                    for ($k = $j + 1; $k < $n && !in_array($toks[$k][0], ['{', ';'], true); $k++) $ret .= $toks[$k][0];
                }
                $isNullable = (bool) preg_match('/^\?\s*array$/i', trim($ret))
                           || (bool) preg_match('/(^|\|)\s*null\s*(\||$)/i', trim($ret)) && stripos($ret, 'array') !== false;
                if ($class !== null) $map[$class . '::' . $method] = $isNullable;
                // Also keep a name-only view for unresolved receivers.
                $map['*::' . $method] = ($map['*::' . $method] ?? false) || $isNullable;
            }
        }
        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function scanFile(string $path, array $nullable): array
    {
        $src  = @file_get_contents($path);
        if ($src === false) return [];
        $flat = $this->tokens($src);
        $N    = count($flat);
        if ($N === 0) return [];

        // Enclosing class per token index, for resolving $this->/self::.
        $classAt = [];
        $cur = null; $depth = 0; $cd = null;
        for ($i = 0; $i < $N; $i++) {
            if ($flat[$i][0] === '{') $depth++;
            elseif ($flat[$i][0] === '}') { $depth--; if ($cd !== null && $depth < $cd) { $cur = null; $cd = null; } }
            elseif (in_array($flat[$i][3], [T_CLASS, T_TRAIT], true) && ($flat[$i + 1][3] ?? null) === T_STRING) {
                $cur = $flat[$i + 1][0]; $cd = $depth + 1;
            }
            $classAt[$i] = $cur;
        }

        // Function bodies, innermost wins; plus a top-level scope for views.
        $scopes = []; $sigRanges = [];
        for ($i = 0; $i < $N; $i++) {
            if ($flat[$i][3] !== T_FUNCTION) continue;
            $b = $i;
            while ($b < $N && $flat[$b][0] !== '{' && $flat[$b][0] !== ';') $b++;
            if ($b >= $N || $flat[$b][0] === ';') continue;
            $d = 0;
            for ($e = $b; $e < $N; $e++) {
                if ($flat[$e][0] === '{') $d++;
                elseif ($flat[$e][0] === '}') { $d--; if ($d === 0) break; }
            }
            $scopes[] = [$b, min($e, $N - 1)];
            $sigRanges[] = [$i, $b];   // function keyword .. opening brace
        }
        $scopes[] = [0, $N - 1];

        // Scope guards ONLY — a per-access `?? null` is deliberately not one.
        $guardAt = [];
        for ($i = 0; $i < $N; $i++) {
            if (!$flat[$i][2]) continue;
            $back = ''; for ($j = max(0, $i - 4); $j < $i; $j++) $back .= $flat[$j][0];
            $fwd  = ''; for ($j = $i + 1; $j < min($N, $i + 10); $j++) $fwd .= $flat[$j][0];
            // Back-context guards are ONLY the forms that actually TEST $v:
            // isset($v / empty($v / if ($v / while ($v. An earlier version also
            // treated a preceding `return`, `:`, `&&` or `?` as a guard, which is
            // wrong in both directions - `return $v['k']` is a plain access, and
            // `$a && $v['k']` tests $a, not $v. It silently suppressed real
            // findings, and the self-test is what caught it.
            if (preg_match('/(isset|empty)\s*\(\s*!?$/', $back)
             || preg_match('/\b(if|while|elseif)\s*\(\s*!?$/', $back)
             || preg_match('/^\s*(&&|\?[^?]|instanceof|!==\s*null|===\s*null|\?\s*\w)/', $fwd)
             // `!$v || $v['k']` short-circuits exactly like `$v && $v['k']`.
             // Only the NEGATED form guards through `||`; a bare `$v || $v['k']`
             // does not, so the trailing `!` in $back is required.
             || (preg_match('/^\s*\|\|/', $fwd) && preg_match('/!$/', $back))) {
                $guardAt[$flat[$i][0]][] = $i;
            }
        }

        $rel  = $this->relative($path);
        $hits = [];
        for ($i = 0; $i < $N - 2; $i++) {
            if (!$flat[$i][2] || $flat[$i + 1][0] !== '=' || ($flat[$i + 2][0] ?? '') === '=') continue;
            // A parameter DEFAULT (`array $opts = []`) is not an assignment from a
            // call. Treating it as one made the RHS scan run past the signature into
            // the body, pick up the first nullable call there, and blame the
            // parameter for it.
            $inSig = false;
            foreach ($sigRanges as $sr) if ($i > $sr[0] && $i < $sr[1]) { $inSig = true; break; }
            if ($inSig) continue;
            $var = $flat[$i][0];

            $rhs = ''; $j = $i + 2; $d = 0;
            while ($j < $N) {
                $tx = $flat[$j][0];
                if ($tx === '(') $d++;
                if ($tx === ')') $d--;
                if ($tx === ';' && $d <= 0) break;
                $rhs .= $tx; $j++;
            }
            // `$x = [ 'k' => $v->get('a') ]` is an array LITERAL - the calls inside
            // it produce values, not $x. Without this the literal is blamed for the
            // first nullable call it happens to contain.
            if (str_starts_with(ltrim($rhs), '[')) continue;
            [$call, $confident] = $this->nullableCallIn($rhs, $classAt[$i] ?? null, $nullable);
            if ($call === null) continue;
            if (preg_match('/\?\?\s*\[|\?:\s*\[|\?\?\s*\w+\s*\(|\?:\s*\w+\s*\(/', $rhs)) continue;  // explicit fallback

            $scope = [0, $N - 1];
            foreach ($scopes as $s) {
                if ($i >= $s[0] && $i <= $s[1] && ($s[1] - $s[0]) < ($scope[1] - $scope[0])) $scope = $s;
            }

            for ($k = $j; $k <= $scope[1] && $k < $N; $k++) {
                if (!$flat[$k][2] || $flat[$k][0] !== $var) continue;
                if (($flat[$k + 1][0] ?? '') === '=' && ($flat[$k + 2][0] ?? '') !== '=') break;   // reassigned
                if (($flat[$k + 1][0] ?? '') !== '[') continue;
                // A WRITE (`$v['k'] = …`) is not this bug class: PHP auto-vivifies an
                // array from null on assignment. Only reads warn.
                $close = $k + 1; $bd = 0;
                for ($w = $k + 1; $w < $N; $w++) {
                    if ($flat[$w][0] === '[') $bd++;
                    elseif ($flat[$w][0] === ']') { $bd--; if ($bd === 0) { $close = $w; break; } }
                }
                if (($flat[$close + 1][0] ?? '') === '=' && ($flat[$close + 2][0] ?? '') !== '=') continue;

                $guarded = false;
                foreach ($guardAt[$var] ?? [] as $g) {
                    // >= not > : `while ($row = $stmt->fetch())` records its guard AT
                    // the assignment index, and that condition genuinely guards every
                    // use inside the loop body.
                    if ($g >= $i && $g <= $k && $g >= $scope[0] && $g <= $scope[1]) { $guarded = true; break; }
                }
                $fwd = ''; for ($z = $k + 1; $z < min($N, $k + 14); $z++) $fwd .= $flat[$z][0];
                if (preg_match('/^(\s*\[[^\]]*\])+\s*\?\?/', $fwd)) $guarded = true;   // access-local guard

                // Guarded-then-reused: `is_array($v['d'] ?? null) ? $v['d'] : null`.
                // The re-read is unreachable when $v is null, because the SAME key was
                // just null-tested in the same statement. Requiring the same key keeps
                // this tight - a guard on $v['a'] never excuses a bare $v['b'].
                $key = $flat[$k + 2][0] ?? '';
                if ($key !== '') {
                    for ($z = $k - 1; $z > $i; $z--) {
                        if ($flat[$z][0] === ';') break;                 // statement boundary
                        if (!$flat[$z][2] || $flat[$z][0] !== $var) continue;
                        if (($flat[$z + 1][0] ?? '') !== '[' || ($flat[$z + 2][0] ?? '') !== $key) continue;
                        $after = ''; for ($w = $z + 3; $w < min($N, $z + 9); $w++) $after .= $flat[$w][0];
                        if (preg_match('/^\]\s*\?\?/', $after)) { $guarded = true; break; }
                    }
                }

                // Explicit opt-out for a guard this scanner cannot see (e.g. a paired
                // supports()/pack() idiom where one call proves the other non-null).
                $srcLines = $srcLines ?? preg_split('/
|
|/', $src);
                $ln = $flat[$k][1];
                // Window of 5: the marker usually sits above a short comment saying
                // WHY, so 1-2 lines is not enough to reach it.
                for ($b = 1; $b <= 5; $b++) {
                    if (str_contains($srcLines[$ln - $b] ?? '', '@null-guard-ok')) { $guarded = true; break; }
                }

                if ($guarded) continue;

                $hits[] = ['file' => $rel, 'line' => $flat[$k][1], 'var' => $var, 'call' => $call,
                           'at' => $flat[$i][1], 'confident' => $confident,
                           'snip' => trim(preg_replace('/\s+/', ' ', $var . substr($fwd, 0, 60)))];
                break;
            }
        }
        return $hits;
    }

    /**
     * Does this right-hand side call something that can return null?
     * @return array{0:?string,1:bool} [label, receiver-was-resolvable]
     */
    private function nullableCallIn(string $rhs, ?string $enclosing, array $nullable): array
    {
        if (preg_match_all('/(?:(\$this|self|static|new\s+\\\\?[\w\\\\]+|\\\\?[\w\\\\]+)\s*\)?\s*(?:->|::)\s*)?(\w+)\s*\(/', $rhs, $m, PREG_SET_ORDER)) {
            foreach ($m as $set) {
                $recv   = $set[1] ?? '';
                $method = $set[2];
                if (isset(self::ALWAYS_NULLABLE[$method])) return [$method, true];

                $class = null;
                if ($recv === '$this' || $recv === 'self' || $recv === 'static') $class = $enclosing;
                elseif ($recv !== '' && $recv[0] !== '$') {
                    $class = preg_replace('/^new\s+/', '', $recv);
                    $class = substr((string) strrchr('\\' . $class, '\\'), 1);
                }
                if ($class !== null && isset($nullable[$class . '::' . $method])) {
                    if ($nullable[$class . '::' . $method]) return [$class . '::' . $method, true];
                    continue;   // declared non-nullable — definitively not this one
                }
                if (!empty($nullable['*::' . $method])) return [$method, false];   // receiver unknown
            }
        }
        return [null, false];
    }

    /** Plant the known-bug shape and require the scanner to find it. */
    private function selfTest(): bool
    {
        $dir = sys_get_temp_dir() . '/qa_nullguard_' . getmypid();
        @mkdir($dir, 0777, true);
        $file = $dir . '/Planted.php';
        file_put_contents($file, <<<'PHP'
<?php
class Planted {
    public function get(int $id): ?array { return null; }
    public function bad(int $id): ?string {
        $existing = $this->get($id);
        $a = $existing['first'] ?? null;          // guarded - must NOT be reported
        return $existing['second'];               // UNGUARDED - must be reported
    }
    public function good(int $id): ?string {
        $row = $this->get($id);
        if (!$row) return null;
        return $row['second'];                    // guarded by early return
    }
}
PHP);
        $nullable = $this->declaredNullability([$file]);
        $hits     = $this->scanFile($file, $nullable);
        @unlink($file); @rmdir($dir);

        $lines = array_map(static fn($h) => $h['line'], $hits);
        return in_array(7, $lines, true) && !in_array(6, $lines, true) && !in_array(12, $lines, true);
    }

    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
