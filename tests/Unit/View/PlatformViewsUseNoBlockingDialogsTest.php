<?php
// tests/Unit/View/PlatformViewsUseNoBlockingDialogsTest.php
namespace Tests\Unit\View;

use Tests\TestCase;

/**
 * Board #421 (2026-10-08): the platform's own pages used the browser's blocking dialogs — alert / confirm / prompt —
 * in ~40 places (delete forms, the page composer, the cookie banner, the WYSIWYG link button, …), while the
 * custom-module validator rejects them in generated code. They freeze the page and cannot be themed. Every one is
 * now inline, through ONE shared partial (app/Views/partials/_inline_dialogs.php: moDialog + data-confirm /
 * data-mo-confirm / data-mo-ask). This file is shared byte-for-byte by the framework and the Builder.
 */
final class PlatformViewsUseNoBlockingDialogsTest extends TestCase
{
    /** Same per-line rule as the custom-module validator: a call, not a property, a helper or a string. */
    private static function dialogs(string $js): array
    {
        $hits = [];
        foreach (preg_split('/\R/', $js) as $line) {
            $t = ltrim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
            $t = preg_replace('/([\'"`])(?:\\\\.|(?!\1).)*\1/s', '""', $t) ?? $t;
            if (preg_match_all('/(?<![\w.$-])(?:window\s*\.\s*)?(alert|confirm|prompt)\s*\(/', $t, $m)) {
                foreach ($m[1] as $k) $hits[$k] = ($hits[$k] ?? 0) + 1;
            }
        }
        return $hits;
    }

    /** The browser code of one file: a .js asset whole; a view's <script> bodies and its on…= handlers. */
    private static function browserCode(string $path): array
    {
        $src = (string) file_get_contents($path);
        if (str_ends_with($path, '.js')) return [$src];
        $chunks = preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $src, $m) ? $m[1] : [];
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $src);
        // A handler inside a PHP string (onsubmit="return confirm(\'…\')") reads fine as it is: the string-blanking
        // rule above starts at the bare quote and still leaves the call. The `echoed` fixture below holds that case.
        if (preg_match_all('/\son[a-z]+\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html, $h)) {
            foreach ($h[0] as $i => $_) $chunks[] = html_entity_decode($h[2][$i] !== '' ? $h[2][$i] : $h[3][$i], ENT_QUOTES);
        }
        return $chunks;
    }

    /** @return array<string,array<string,int>> file => dialog kind => count, for every hit under $root */
    private static function scan(string $root): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            $rel = substr($p, strlen($root) + 1);
            $isView = str_ends_with($p, '.php') && (str_starts_with($rel, 'app/Views/') || preg_match('#^modules(-operator)?/[^/]+/Views/#', $rel));
            $isJs = str_ends_with($p, '.js') && !str_contains($p, '.min.') && (str_starts_with($rel, 'public/assets/') || preg_match('#^modules(-operator)?/[^/]+/assets/#', $rel));
            if (!$isView && !$isJs) continue;
            $hits = [];
            foreach (self::browserCode($p) as $c) foreach (self::dialogs($c) as $k => $n) $hits[$k] = ($hits[$k] ?? 0) + $n;
            if ($hits) $found[$rel] = $hits;
        }
        ksort($found);
        return $found;
    }

    public function test_no_platform_view_or_script_uses_a_blocking_dialog(): void
    {
        $base = str_replace('\\', '/', BASE_PATH);
        $roots = array_filter(['app/Views', 'modules', 'modules-operator', 'public/assets'], fn ($d) => is_dir("$base/$d"));
        $this->assertContains('app/Views', $roots);
        $found = self::scan($base);
        $this->assertSame([], $found, "Blocking dialogs in platform code — use partials/_inline_dialogs.php "
            . "(data-confirm / data-mo-confirm on the form or button, moDialog.confirm / .notice / .ask in script):\n"
            . json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_the_scan_sees_every_shape_and_nothing_that_only_looks_like_one(): void
    {
        $dir = sys_get_temp_dir() . '/pvd421-' . bin2hex(random_bytes(4));
        $put = function (string $rel, string $body) use ($dir) { @mkdir(dirname("$dir/$rel"), 0777, true); file_put_contents("$dir/$rel", $body); };
        try {
            // the real shapes that shipped
            $put('modules/a/Views/handler.php', '<form onsubmit="return confirm(\'Delete flag + all overrides?\')"></form>');
            $put('modules/a/Views/echoed.php', "<?php echo '<form onsubmit=\"return confirm(\\'Delete node and children?\\')\">'; ?>");
            $put('modules/a/Views/script.php', "<script>\nif (!confirm('Remove?')) return;\nalert(v.__error);\n</script>");
            $put('app/Views/partials/link.php', "<script>\nconst url = window.prompt('Link URL:', 'https://');\n</script>");
            $put('public/assets/js/app.js', "function f() {\n    alert(res.error);\n}\n");
            // only look like one
            $put('modules/b/Views/clean.php', "<form data-confirm=\"Delete?\" data-mo-confirm=\"Sure?\"></form>\n<script>\n// alert('no')\nmoDialog.confirm(b, 'call confirm(x) if unsure', go);\nshowConfirm();\nthis.alert('x');\n</script>");
            $put('modules/b/Views/notes.php', '<p>Do not use alert() here.</p>');
            $put('modules/b/Services/Mailer.php', '<?php // not browser code: confirm(1);');
            $this->assertSame([
                'app/Views/partials/link.php'  => ['prompt' => 1],
                'modules/a/Views/echoed.php'   => ['confirm' => 1],
                'modules/a/Views/handler.php'  => ['confirm' => 1],
                'modules/a/Views/script.php'   => ['confirm' => 1, 'alert' => 1],
                'public/assets/js/app.js'      => ['alert' => 1],
            ], self::scan(str_replace('\\', '/', $dir)));
        } finally {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            rmdir($dir);
        }
    }

    public function test_the_partial_prints_once_and_answers_both_hooks(): void
    {
        unset($GLOBALS['__cpf_inline_dialogs']);
        ob_start();
        include BASE_PATH . '/app/Views/partials/_inline_dialogs.php';
        include BASE_PATH . '/app/Views/partials/_inline_dialogs.php';
        $out = (string) ob_get_clean();
        unset($GLOBALS['__cpf_inline_dialogs']);
        $this->assertSame(1, substr_count($out, 'window.moDialog = {'), 'the partial must print once per request');
        $this->assertStringContainsString("form[data-mo-confirm],form[data-confirm]", $out, 'the framework\'s data-confirm forms must ask inline');
        $this->assertStringContainsString("[data-mo-confirm]:not(form)", $out);
        $this->assertStringContainsString("form[data-mo-ask]", $out);
        $this->assertSame([], self::dialogs(preg_replace('#^.*?<script>|</script>.*$#s', '', $out)), 'the replacement must not call one itself');
    }

    public function test_every_page_shell_carries_the_partial(): void
    {
        $inc = "include BASE_PATH . '/app/Views/partials/_inline_dialogs.php';";
        foreach (['app/Views/layout/footer.php', 'app/Views/public/page.php', 'modules/cookieconsent/Views/banner.php'] as $f) {
            $this->assertStringContainsString($inc, (string) file_get_contents(BASE_PATH . "/$f"), "$f must include the inline dialogs");
        }
        $this->assertStringNotContainsString('confirm(form.dataset.confirm', (string) file_get_contents(BASE_PATH . '/app/Views/layout/footer.php'));
    }
}
