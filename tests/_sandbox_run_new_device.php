<?php
// tests/_sandbox_run_new_device.php
//
// Sandbox-only runner for NewDeviceLoginNotifyTest. The composer autoloader
// is pinned to PHP 8.4 but bin/php is 8.1, so we bypass composer and use a
// stripped-down manual PSR-4 autoloader + the local phpunit-minimal shim.
// Not meant for CI — real PHPUnit is the CI path.

declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class) {
    foreach ([
        'Core\\'    => 'core/',
        'App\\'     => 'app/',
        'Modules\\' => 'modules/',
        'Tests\\'   => 'tests/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel = substr($class, strlen($prefix));
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

require BASE_PATH . '/core/helpers.php';
require BASE_PATH . '/tests/_shim/phpunit-minimal.php';

require __DIR__ . '/Unit/Services/NewDeviceLoginNotifyTest.php';

$class = \Tests\Unit\Services\NewDeviceLoginNotifyTest::class;
$ref   = new \ReflectionClass($class);

$pass = 0; $fail = 0; $failures = [];
foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
    if (!str_starts_with($m->getName(), 'test')) continue;

    $instance = $ref->newInstanceWithoutConstructor();
    $instance->name = $m->getName();
    try {
        $ref->getMethod('setUp')->invoke($instance);
        $m->invoke($instance);
        $ref->getMethod('tearDown')->invoke($instance);
        echo "  ✓ " . $m->getName() . "\n";
        $pass++;
    } catch (\Throwable $e) {
        try { $ref->getMethod('tearDown')->invoke($instance); } catch (\Throwable $_) {}
        echo "  ✗ " . $m->getName() . " — " . $e->getMessage() . "\n";
        $fail++;
        $failures[] = $m->getName() . " — " . $e->getMessage();
    }
}

echo "\n── Summary ──\n";
echo "  Passed: $pass   Failed: $fail\n";
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($fail ? 1 : 0);
