<?php
// tests/Unit/Http/RequestMethodCallsResolveTest.php
namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Every `$request->name(...)` in the app must name a method Request has.
 *
 * 2026-10-10: the cookie banner's "Save my choices" answered 500 on StoriesDen.
 * CookieConsentController called `$request->server('HTTP_REFERER')`, and Request
 * had no server() — on every site built from this framework, since the first
 * commit. The banner's script path returned JSON before reaching that line, so
 * only the no-script / Customize form path ever ran it, and nothing tested
 * that path. Four other call sites had the same missing method (the policy
 * acceptance middleware among them).
 *
 * ThisMethodCallsResolveTest covers `$this->x()`; this is the same idea for the
 * one object every controller is handed. It reads the code instead of running
 * it, so an action nobody exercises is covered too.
 */
final class RequestMethodCallsResolveTest extends TestCase
{
    /** Folders whose PHP is scanned, relative to the app root. Missing ones are skipped. */
    private const ROOTS = ['app', 'core', 'modules', 'modules-operator'];

    public function test_every_request_method_call_resolves(): void
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        require_once $base . '/core/Http/Request.php';
        $known = array_map('strtolower', get_class_methods(\Core\Http\Request::class));

        $missing = [];
        $checked = 0;
        foreach (self::files($base) as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, '$request->')) { continue; }
            foreach (explode("\n", $src) as $n => $line) {
                if (!preg_match_all('/\$request->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m)) { continue; }
                // Prompt text telling a model what NOT to call ("There is NO `$request->get()`") is not a call.
                if (preg_match('/\bNO\s+`\\\\?\$request->/', $line)) { continue; }
                foreach ($m[1] as $method) {
                    $checked++;
                    if (!in_array(strtolower($method), $known, true)) {
                        $missing[] = substr($file, strlen($base) + 1) . ':' . ($n + 1) . '  $request->' . $method . '()';
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the scan found almost nothing to check — it is not reading the code');
        $this->assertSame([], $missing,
            "Calls to methods Request does not have (a fatal the moment the line runs):\n  " . implode("\n  ", $missing));
    }

    public function test_server_reads_what_was_captured(): void
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        require_once $base . '/core/Http/Request.php';
        $saved = $_SERVER;
        try {
            $_SERVER['HTTP_REFERER'] = 'https://example.test/back';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $r = \Core\Http\Request::capture();
            $this->assertSame('https://example.test/back', $r->server('HTTP_REFERER'));
            $this->assertNull($r->server('NOT_A_KEY'));
            $this->assertSame('/', $r->server('NOT_A_KEY', '/'));
            $this->assertIsArray($r->server());
        } finally {
            $_SERVER = $saved;
        }
    }

    /** @return list<string> */
    private static function files(string $base): array
    {
        $out = [];
        foreach (self::ROOTS as $root) {
            $dir = $base . '/' . $root;
            if (!is_dir($dir)) { continue; }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = str_replace('\\', '/', (string) $f);
                if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/') || str_contains($p, '/node_modules/')) { continue; }
                $out[] = $p;
            }
        }

        return $out;
    }
}
