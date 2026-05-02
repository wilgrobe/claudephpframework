<?php
// tests/bootstrap.php
/**
 * PHPUnit entrypoint. Sets up the autoloader + minimal environment tests
 * need without booting the full container / DB / session.
 *
 * Individual tests that DO need the container (Feature tests) can call
 * Tests\TestCase::bootContainer() — it's kept lazy so Unit tests stay fast.
 */

define('BASE_PATH', dirname(__DIR__));

// Composer autoloader. In normal dev + CI, `composer install` has run and
// vendor/autoload.php is intact. In the special case where someone is
// running tests without composer (e.g. the sandbox) we fall back to a
// manual PSR-4 autoloader so Unit tests can still pass.
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
// Detect a real composer-generated autoload.php by looking for its signature
// marker. A bare filesize check is unreliable — real composer autoloads are
// only ~700 bytes (they delegate to autoload_real.php), while the sandbox
// we develop in had a truncated 178-byte file that happened to be missing
// its tail. The 'ComposerAutoloaderInit' string only appears in real files.
$isRealComposerAutoload = is_file($composerAutoload)
    && str_contains((string) @file_get_contents($composerAutoload), 'ComposerAutoloaderInit');

if ($isRealComposerAutoload) {
    require $composerAutoload;
} else {
    fwrite(STDERR, "[tests/bootstrap] vendor/autoload.php missing or truncated;\n");
    fwrite(STDERR, "                  falling back to manual PSR-4 autoloader.\n");
    fwrite(STDERR, "                  Run `composer install` for full fidelity.\n\n");

    spl_autoload_register(function (string $class) {
        foreach ([
            'Core\\'    => 'core/',
            'App\\'     => 'app/',
            'Modules\\' => 'modules/',
            'Tests\\'   => 'tests/',
        ] as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $rel = substr($class, strlen($prefix));
                // Modules need lowercased module segment (matches ModuleRegistry convention)
                if ($prefix === 'Modules\\') {
                    $parts  = explode('\\', $rel);
                    $module = strtolower(array_shift($parts));
                    $path   = $module . '/' . implode('/', $parts);
                } else {
                    $path = str_replace('\\', '/', $rel);
                }
                $f = BASE_PATH . '/' . $dir . $path . '.php';
                if (is_file($f)) require $f;
                return;
            }
        }
    });

    // helpers.php isn't autoloaded via PSR-4 — it's loaded via composer
    // "files" in production. Pull it in manually.
    require BASE_PATH . '/core/helpers.php';
}

// Always autoload the Tests\ namespace (tests/TestCase.php, tests/Unit/..., etc.)
spl_autoload_register(function (string $class) {
    if (!str_starts_with($class, 'Tests\\')) return;
    $rel = substr($class, strlen('Tests\\'));
    $f = BASE_PATH . '/tests/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($f)) require $f;
});

// Always autoload the Modules\ namespace. At runtime the framework's
// ModuleRegistry::registerAutoloader() does this during bootstrap, but
// PHPUnit doesn't boot the framework — and composer.json's PSR-4 map
// only covers Core\ and App\. Without this, any test that references a
// class from modules/ fails with "Class ... not found".
//
// Convention (matches core/Module/ModuleRegistry::registerAutoloader):
//   Modules\Forms\Services\FormService
//     → <root>/forms/Services/FormService.php
//   First namespace segment after Modules\ is lowercased to the directory;
//   the rest maps literally to the filesystem.
//
// Multi-root: tests should be able to reach premium modules from the
// sibling claudephpframeworkpremium checkout when it's present. We pull
// the same config/modules.php list the runtime registry uses so the
// behaviours stay aligned. config/modules.php returns absolute paths,
// so we just iterate them.
$__moduleRoots = [];
if (is_file(BASE_PATH . '/config/modules.php')) {
    $cfg = require BASE_PATH . '/config/modules.php';
    if (is_array($cfg['paths'] ?? null)) {
        $__moduleRoots = $cfg['paths'];
    }
}
if (empty($__moduleRoots)) {
    $__moduleRoots = [BASE_PATH . '/modules'];
}
spl_autoload_register(function (string $class) use ($__moduleRoots) {
    if (!str_starts_with($class, 'Modules\\')) return;
    $parts = explode('\\', substr($class, strlen('Modules\\')));
    if (empty($parts)) return;
    $module = strtolower(array_shift($parts));
    $tail   = implode('/', $parts) . '.php';
    foreach ($__moduleRoots as $root) {
        $f = $root . '/' . $module . '/' . $tail;
        if (is_file($f)) {
            require $f;
            return;
        }
    }
});

// Load the minimal PHPUnit shim when the real one isn't around. The shim
// defines enough of PHPUnit\Framework\TestCase to run these unit tests via
// tests/_runner.php. When composer installed real PHPUnit, the autoloader
// resolves TestCase and we leave the shim alone.
//
// IMPORTANT: class_exists() here MUST allow autoloading (2nd arg true, or
// omitted). With autoload disabled, real PHPUnit — installed but not yet
// loaded — looks absent to us, so we'd register our shim's TestSuite stub
// *before* real PHPUnit's TestSuite class ever gets touched. Later, when
// PHPUnit's own TestSuiteMapper calls `TestSuite::empty()`, it finds our
// shim's empty-stub class and throws "undefined method ::empty()".
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    $shim = BASE_PATH . '/tests/_shim/phpunit-minimal.php';
    if (is_file($shim)) require $shim;
}

// ── Silence error_log() during tests ─────────────────────────────────────
// Several services (PaymentsService, BraintreeService, the Comment +
// Setting + Notification service trio when the test fakes don't supply a
// real PDO connection) defensively call error_log() to record diagnostic
// detail. PHPUnit's reporter would otherwise interleave those messages
// with the dot/dash test progress display. Redirecting to a per-run
// tempfile keeps the reporter clean while still letting any test that
// actually wants to assert on the messages read the file directly.
//
// Cross-platform: tempnam() picks the right OS temp dir (TEMP on Windows,
// /tmp or $TMPDIR on POSIX) so this works on Will's Windows + on a Linux
// CI runner without per-platform branches.
if (PHP_SAPI === 'cli' && getenv('PHPUNIT_KEEP_ERROR_LOG') !== '1') {
    $__phpunitErrLog = tempnam(sys_get_temp_dir(), 'phpunit_errlog_');
    if ($__phpunitErrLog !== false) {
        ini_set('error_log', $__phpunitErrLog);
        // Reap on shutdown so we don't leave one tempfile per run.
        register_shutdown_function(static function () use ($__phpunitErrLog) {
            @unlink($__phpunitErrLog);
        });
    }
}
