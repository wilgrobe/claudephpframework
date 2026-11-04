<?php
// tests/Unit/Consistency/NoSwallowedFailTest.php
namespace Tests\Unit\Consistency;

use Tests\TestCase;

/**
 * #466 (4 Nov 2026): `try { $code(); $this->fail('should throw'); } catch (\RuntimeException) {}` can never fail —
 * PHPUnit's AssertionFailedError extends \RuntimeException, so the catch swallows the failure. Two premium tests
 * (and two framework ones) were silently vacuous that way; one was found only because a mutation test stayed green.
 *
 * Rule: a try block that calls fail() may only catch RuntimeException / Exception / Throwable if the catch body
 * then asserts on the caught message (PHPUnit's "should throw" text will not contain what the test expects).
 * Otherwise use a flag: $threw = false; try { … } catch (\RuntimeException) { $threw = true; } assertTrue($threw).
 */
final class NoSwallowedFailTest extends TestCase
{
    public function test_no_try_block_swallows_its_own_fail_call(): void
    {
        $root = dirname(__DIR__, 2);
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php' || $f->getRealPath() === __FILE__) continue;
            $tokens = token_get_all((string) file_get_contents($f->getPathname()));
            foreach (self::tryBlocks($tokens) as [$line, $body, $catchType, $catchBody]) {
                if (!preg_match('/(\$this->|self::|static::)fail\(/', $body)) continue;
                if (!preg_match('/(?<![A-Za-z_])\\\\?(RuntimeException|Exception|Throwable)\b/', $catchType)) continue;
                if (str_contains($catchBody, 'getMessage()')) continue;
                $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $line;
            }
        }
        $this->assertSame([], $bad, "fail() inside a try whose catch swallows PHPUnit's own failure:\n" . implode("\n", $bad));
    }

    /** @return list<array{int, string, string, string}> [try line, try body, first catch's type list, its body] */
    private static function tryBlocks(array $t): array
    {
        $out = []; $n = count($t);
        $text = static fn ($x) => is_array($x) ? $x[1] : $x;
        $block = static function (int $j) use ($t, $n, $text): array {   // from the token at/after $j that is '{'
            while ($j < $n && $t[$j] !== '{') $j++;
            $depth = 0; $s = '';
            for (; $j < $n; $j++) {
                $isOpen = $t[$j] === '{' || (is_array($t[$j]) && in_array($t[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
                if ($isOpen) $depth++;
                if ($t[$j] === '}' && --$depth === 0) return [$s, $j];
                if ($depth > 0 && !($isOpen && $depth === 1)) $s .= $text($t[$j]);
            }
            return [$s, $n];
        };
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($t[$i]) || $t[$i][0] !== T_TRY) continue;
            [$body, $end] = $block($i);
            $k = $end + 1;
            while ($k < $n && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) $k++;
            if (!($k < $n && is_array($t[$k]) && $t[$k][0] === T_CATCH)) continue;
            $type = '';
            for ($m = $k + 1; $m < $n && $t[$m] !== ')'; $m++) $type .= $text($t[$m]);
            [$catchBody] = $block($m);
            $out[] = [$t[$i][2], $body, $type, $catchBody];
        }
        return $out;
    }
}
