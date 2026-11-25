<?php
// tests/Unit/Consistency/EscapedQuoteInCodeTest.php
namespace Tests\Unit\Consistency;

use PHPUnit\Framework\TestCase;

/**
 * A shell that writes '\x27' instead of a quote produces PHP that still parses: module_installed(\x27groups\x27)
 * is a namespaced constant, so `php -l` passes and the page dies only when someone opens it. Two settings pages
 * did that from 24 September to 25 November 2026.
 */
final class EscapedQuoteInCodeTest extends TestCase
{
    /** @return list<string> "line: code" for each escaped quote used as code */
    private static function offences(string $php): array
    {
        $out = [];
        foreach (token_get_all($php) as $t) {
            if (is_array($t) && in_array($t[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && preg_match('/\\\\x2[27]/', $t[1])) {
                $out[] = $t[2] . ': ' . $t[1];
            }
        }
        return $out;
    }

    public function test_the_check_sees_the_fault_and_ignores_strings(): void
    {
        self::assertNotSame([], self::offences('<?php if (f(\x27groups\x27)): endif;'));
        self::assertSame([], self::offences('<?php $a = "\x27"; $b = \'\x27groups\x27\'; new \App\X();'));
    }

    public function test_no_shipped_file_uses_an_escaped_quote_as_code(): void
    {
        $root = dirname(__DIR__, 3);
        $bad = [];
        $seen = 0;
        foreach (['app', 'core', 'modules', 'config', 'routes', 'database'] as $dir) {
            if (!is_dir("$root/$dir")) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') continue;
                $seen++;
                $src = (string) file_get_contents($f->getPathname());
                if (!preg_match('/\\\\x2[27]/', $src)) continue;
                foreach (self::offences($src) as $o) $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $o;
            }
        }
        self::assertGreaterThan(200, $seen);
        self::assertSame([], $bad);
    }
}
