<?php
/**
 * storage/tmp/ci-test-smoke.php
 *
 * Standalone sanity runner for CorePremiumIntegrityTest. The sandbox PHP
 * is 8.1 but vendor's composer platform_check insists on 8.4, so the
 * normal phpunit path doesn't work here. This script bypasses composer
 * and invokes the test methods directly with a tiny PHPUnit stub.
 *
 * Will runs the real test via vendor/bin/phpunit on Windows + PHP 8.4
 * where composer is happy. This script only validates the test logic
 * against the current code in the sandbox.
 */

declare(strict_types=1);

namespace PHPUnit\Framework {
    class TestCase
    {
        public function __construct(string $name = '') {}

        protected function assertSame($expected, $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                throw new \RuntimeException(
                    "assertSame failed.\n  expected: " . var_export($expected, true)
                    . "\n  actual:   " . var_export($actual, true)
                    . ($message !== '' ? "\n\n$message" : '')
                );
            }
        }

        public function markTestSkipped(string $message = ''): void
        {
            throw new SkippedTestException($message);
        }
    }

    class SkippedTestException extends \RuntimeException {}
}

namespace Tests {
    abstract class TestCase extends \PHPUnit\Framework\TestCase {}
}

namespace {
    define('BASE_PATH', dirname(__DIR__, 2));

    // Core / Tests autoloader
    spl_autoload_register(function (string $class) {
        $map = [
            'Core\\'  => BASE_PATH . '/core/',
            'Tests\\' => BASE_PATH . '/tests/',
        ];
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $rel = substr($class, strlen($prefix));
                $f = $dir . str_replace('\\', '/', $rel) . '.php';
                if (is_file($f)) require $f;
                return;
            }
        }
    });

    // Modules autoloader (covers both core + premium roots)
    $moduleRoots = [BASE_PATH . '/modules'];
    $premiumPath = realpath(BASE_PATH . '/../claudephpframeworkpremium/modules');
    if ($premiumPath !== false && is_dir($premiumPath)) {
        $moduleRoots[] = $premiumPath;
    }
    spl_autoload_register(function (string $class) use ($moduleRoots) {
        if (!str_starts_with($class, 'Modules\\')) return;
        $parts = explode('\\', substr($class, strlen('Modules\\')));
        if (empty($parts)) return;
        $folder = strtolower(array_shift($parts));
        $tail = implode('/', $parts) . '.php';
        foreach ($moduleRoots as $root) {
            $f = $root . '/' . $folder . '/' . $tail;
            if (is_file($f)) {
                require $f;
                return;
            }
        }
    });

    require BASE_PATH . '/tests/Unit/Module/CorePremiumIntegrityTest.php';

    $cls = \Tests\Unit\Module\CorePremiumIntegrityTest::class;
    $tc = new $cls('runner');

    $methods = [
        'test_no_core_module_requires_a_premium_module',
        'test_no_module_in_core_repo_declares_premium_tier',
        'test_known_premium_list_matches_premium_repo_when_mounted',
    ];

    $pass = 0; $fail = 0; $skip = 0;
    foreach ($methods as $m) {
        try {
            $tc->$m();
            echo "  PASS  $m\n";
            $pass++;
        } catch (\PHPUnit\Framework\SkippedTestException $e) {
            echo "  SKIP  $m — " . $e->getMessage() . "\n";
            $skip++;
        } catch (\Throwable $e) {
            echo "  FAIL  $m\n";
            echo "        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
            $fail++;
        }
    }

    echo "\nResult: $pass passed, $fail failed, $skip skipped\n";
    exit($fail > 0 ? 1 : 0);
}
