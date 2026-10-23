<?php
// tests/Unit/Views/PartialIncludesExistTest.php
namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * Every app/Views/partials/*.php that a view includes must exist in this repo. 2fa_disable.php and
 * 2fa_recovery_codes.php `require`d _password_toggle.php, which lived only in the Builder — so on a bare
 * framework install (and every kit built from it) both pages died with a missing-file fatal until 2026-10-23.
 */
final class PartialIncludesExistTest extends TestCase
{
    public function test_every_included_partial_exists(): void
    {
        $root = rtrim(str_replace('\\', '/', BASE_PATH), '/');
        $missing = [];
        $scanned = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/app/Views", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $scanned++;
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            $src = (string) file_get_contents($file->getPathname());
            $want = [];
            if (preg_match_all('~(?:include|require)(?:_once)?\s*\(?\s*(?:BASE_PATH\s*\.\s*)?[\'"]/?(app/Views/partials/[A-Za-z0-9_\-]+\.php)~', $src, $m)) {
                $want = $m[1];
            }
            if (preg_match_all('~(?:include|require)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]/\.\./partials/([A-Za-z0-9_\-]+\.php)~', $src, $m)) {
                foreach ($m[1] as $p) $want[] = "app/Views/partials/$p";
            }
            foreach (array_unique($want) as $p) {
                if (!is_file("$root/$p")) $missing[] = "  $p  <- $rel";
            }
        }
        $this->assertGreaterThan(20, $scanned, 'Scanned suspiciously few views — the test is broken.');
        sort($missing);
        $this->assertSame([], $missing, "A view includes a partial this repo does not have (a fatal on every page view):\n" . implode("\n", $missing));
    }
}
