<?php
// tests/Unit/Router/RouteFileClassNamesResolveTest.php
namespace Tests\Unit\Router;

use Tests\TestCase;

/**
 * A routes file names a middleware it never imported, and nothing notices
 * until a request arrives.
 *
 * Routes files have no namespace. `CsrfExempt::class` with no `use` for it is
 * the bare string "CsrfExempt": Router::dispatch does not recognise it as
 * the exemption (so CSRF is prepended), and runMiddleware then throws
 * "Middleware class not found: CsrfExempt". Found 3 Nov 2026 in dev-portal:
 * POST /oauth/token and /oauth/revoke fataled on every call, so no OAuth
 * client could ever get or revoke a token. Lint passes, the module loads,
 * every GET works — only the request itself fails.
 *
 * So: read every routes file with the tokenizer (comments ignored), resolve
 * each `Name::class` through that file's imports, grouped `use A\{B, C};`
 * included, and require that it resolves — and that App\ and Core\ classes
 * (middleware lives there) actually exist, which also catches a typo.
 * Module classes are left to their own tests: a module's autoloader is only
 * registered when the module is discovered.
 *
 * Reads the source rather than loading the files into a Router, because
 * loading runs each file's tenant checks and submodule lookups.
 */
final class RouteFileClassNamesResolveTest extends TestCase
{
    /** @return list<string> */
    private function routeFiles(): array
    {
        $base = dirname(__DIR__, 3);
        $roots = [$base . '/modules', $base . '/modules-operator', $base . '/routes'];
        // Premium modules run on the Builder and in its kits, which carry Builder-only
        // classes (App\Middleware\RequireBusinessCapability, App\Tenancy\Tenant). Check
        // them where those exist; on a bare framework checkout they would all "fail".
        $premium = dirname($base) . '/claudephpframeworkpremium/modules';
        if (is_dir($premium) && is_file($base . '/app/Tenancy/Tenant.php')) { $roots[] = $premium; }

        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) { continue; }
            if (basename($root) === 'routes') {
                foreach (glob($root . '/*.php') ?: [] as $f) { $files[] = $f; }
                continue;
            }
            foreach (glob($root . '/*/routes.php') ?: [] as $f) { $files[] = $f; }
        }
        return $files;
    }

    /**
     * @return array{0: array<string,string>, 1: list<string>, 2: bool}
     *         [short name => FQN imported, short names used as Name::class, has namespace]
     */
    private function read(string $src): array
    {
        $toks = array_values(array_filter(
            token_get_all($src),
            fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $imports = [];
        $used = [];
        $hasNamespace = false;
        $depth = 0;
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if ($t === '{') { $depth++; continue; }
            if ($t === '}') { $depth--; continue; }
            if (!is_array($t)) { continue; }
            if ($t[0] === T_NAMESPACE) { $hasNamespace = true; continue; }
            // Top-level `use` only — inside a closure body `use` is the closure's capture list.
            if ($t[0] === T_USE && $depth === 0 && !$this->isClosureUse($toks, $i)) {
                $i = $this->readUse($toks, $i + 1, $imports);
                continue;
            }
            // After `::`, `class` is its own token (T_CLASS), not a T_STRING.
            if (($t[0] === T_CLASS || ($t[0] === T_STRING && strtolower($t[1]) === 'class')) && $i >= 2
                && is_array($toks[$i - 1]) && $toks[$i - 1][0] === T_DOUBLE_COLON
                && is_array($toks[$i - 2]) && $toks[$i - 2][0] === T_STRING) {
                $used[] = $toks[$i - 2][1];   // unqualified: `Name::class`
            }
        }
        return [$imports, array_values(array_unique($used)), $hasNamespace];
    }

    private function isClosureUse(array $toks, int $i): bool
    {
        return $i > 0 && $toks[$i - 1] === ')';
    }

    /** @param array<string,string> $imports */
    private function readUse(array $toks, int $i, array &$imports): int
    {
        $prefix = '';
        $n = count($toks);
        for (; $i < $n && $toks[$i] !== ';'; $i++) {
            $t = $toks[$i];
            if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                $name = ltrim($t[1], '\\');
                if (($toks[$i + 1] ?? null) === '{' || (is_array($toks[$i + 1] ?? null) && $toks[$i + 1][0] === T_NS_SEPARATOR)) {
                    $prefix = rtrim($name, '\\') . '\\';
                    continue;
                }
                $fqn = $prefix . $name;
                $alias = substr(strrchr('\\' . $fqn, '\\'), 1);
                if (is_array($toks[$i + 1] ?? null) && $toks[$i + 1][0] === T_AS && is_array($toks[$i + 2] ?? null)) {
                    $alias = $toks[$i + 2][1];
                    $i += 2;
                }
                $imports[$alias] = $fqn;
            }
        }
        return $i;
    }

    /** @return list<string> one line per problem */
    private function problems(string $file, string $src): array
    {
        [$imports, $used, $hasNamespace] = $this->read($src);
        if ($hasNamespace) { return []; }
        $out = [];
        foreach ($used as $short) {
            if (!isset($imports[$short])) {
                $out[] = "$file: $short::class has no `use` — it is the bare string \"$short\"";
                continue;
            }
            $fqn = $imports[$short];
            if ((str_starts_with($fqn, 'App\\') || str_starts_with($fqn, 'Core\\'))
                && !class_exists($fqn) && !interface_exists($fqn)) {
                $out[] = "$file: $short::class imports $fqn, which does not exist";
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ the real files

    public function test_every_class_name_in_every_routes_file_resolves(): void
    {
        $files = $this->routeFiles();
        $this->assertTrue(count($files) > 20, 'expected the module routes files to be found, got ' . count($files));

        $problems = [];
        foreach ($files as $f) {
            array_push($problems, ...$this->problems($f, (string) file_get_contents($f)));
        }
        $this->assertSame([], $problems);
    }

    // ------------------------------------------------- the check itself can fail

    public function test_a_middleware_used_without_its_use_line_is_caught(): void
    {
        $src = "<?php\nuse App\\Middleware\\AuthMiddleware;\n"
             . "\$router->post('/oauth/token', 'X@y', [CsrfExempt::class]);\n";
        $this->assertCount(1, $this->problems('t.php', $src));
    }

    public function test_a_misspelt_import_is_caught(): void
    {
        $src = "<?php\nuse App\\Middleware\\CsrfExemt;\n"
             . "\$router->post('/a', 'X@y', [CsrfExemt::class]);\n";
        $this->assertCount(1, $this->problems('t.php', $src));
    }

    public function test_grouped_imports_aliases_and_comments_are_understood(): void
    {
        $src = "<?php\nuse App\\Middleware\\{AuthMiddleware, CsrfExempt};\n"
             . "use App\\Middleware\\RequireAdmin as Admin;\n"
             . "// the [Class::class, 'method'] form is not supported\n"
             . "\$router->post('/a', 'X@y', [CsrfExempt::class, AuthMiddleware::class, Admin::class]);\n"
             . "\$router->get('/b', function () use (\$router) { return 1; });\n"
             . "\$router->get('/c', 'X@y', [\\App\\Middleware\\CsrfExempt::class]);\n";
        $this->assertSame([], $this->problems('t.php', $src));
    }
}
