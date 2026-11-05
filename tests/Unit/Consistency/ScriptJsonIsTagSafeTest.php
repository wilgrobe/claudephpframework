<?php
// tests/Unit/Consistency/ScriptJsonIsTagSafeTest.php
namespace Tests\Unit\Consistency;

use Tests\TestCase;

/**
 * #466 (4 Nov 2026): several premium views echoed json_encode(..., JSON_UNESCAPED_SLASHES) straight into a <script>. With
 * slashes unescaped, a stored "</script><script>…" in any value closes the script block and runs — on the
 * gamification progress page that was a member's own badge/goal text. JSON_HEX_TAG turns < and > into < /
 * >, which no HTML parser reads as a tag. Without UNESCAPED_SLASHES, "/" is escaped and "</script" cannot form.
 *
 * Rule: an echo (<?= … ?>) that json_encodes with JSON_UNESCAPED_SLASHES must also pass JSON_HEX_TAG, unless it is
 * HTML-escaped (htmlspecialchars / e()) in the same echo.
 */
final class ScriptJsonIsTagSafeTest extends TestCase
{
    public function test_unescaped_slash_json_in_an_echo_is_tag_safe(): void
    {
        $root = dirname(__DIR__, 3);
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php' || !str_contains(str_replace('\\', '/', $file->getPathname()), '/Views/')) continue;
            $src = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('/<\?=(.*?)\?>/s', $src, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[1] as [$echo, $off]) {
                if (!str_contains($echo, 'json_encode') || !str_contains($echo, 'JSON_UNESCAPED_SLASHES')) continue;
                if (str_contains($echo, 'JSON_HEX_TAG') || preg_match('/\b(htmlspecialchars|e)\s*\(/', $echo)) continue;
                $bad[] = substr($file->getPathname(), strlen($root) + 1) . ':' . (substr_count($src, "\n", 0, $off) + 1);
            }
        }
        $this->assertSame([], $bad, "json_encode with JSON_UNESCAPED_SLASHES echoed without JSON_HEX_TAG:\n" . implode("\n", $bad));
    }
}
