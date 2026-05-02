<?php
// tests/_runner.php
//
// Minimal test runner — discovers tests/Unit/**/*Test.php, instantiates each
// class, runs every test_* method with setUp/tearDown, handles @dataProvider
// and expectException. Used when real PHPUnit isn't available.
//
// The real PHPUnit has a vastly richer feature set; this runner exists so
// the sandbox can verify tests are wired correctly end-to-end. In prod dev
// environments run `./vendor/bin/phpunit` for the full experience.

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
// bootstrap.php already loads _shim/phpunit-minimal.php conditionally when
// real PHPUnit isn't available.

echo "── PHP Framework test runner (sandbox shim) ──\n\n";

$tests = [];
$dir   = new RecursiveDirectoryIterator(__DIR__ . '/Unit');
$it    = new RecursiveIteratorIterator($dir);
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
        $tests[] = $f->getPathname();
    }
}
sort($tests);

$totalPass = 0;
$totalFail = 0;
$failures  = [];

foreach ($tests as $file) {
    $before = get_declared_classes();
    require_once $file;
    $after = get_declared_classes();
    $newClasses = array_values(array_diff($after, $before));

    foreach ($newClasses as $class) {
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract() || !$ref->isSubclassOf(\PHPUnit\Framework\TestCase::class)) continue;

        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        // Collect data providers up-front
        $providers = [];
        foreach ($methods as $m) {
            $doc = $m->getDocComment() ?: '';
            if (preg_match('/@dataProvider\s+(\S+)/', $doc, $match)) {
                $providers[$m->getName()] = $match[1];
            }
        }

        echo "  " . substr($class, strrpos($class, '\\') + 1) . "\n";

        foreach ($methods as $m) {
            $name = $m->getName();
            if (!str_starts_with($name, 'test')) continue;

            $cases = [[]]; // default: run once with no args
            if (isset($providers[$name])) {
                $providerName = $providers[$name];
                $providerMethod = new ReflectionMethod($class, $providerName);
                $cases = $providerMethod->invoke(null);
            }

            foreach ($cases as $i => $args) {
                // Explicit concat: `"$name[$i]"` parses $name[$i] as a
                // PHP string-subscript (a single char), not as the literal
                // we want (e.g. "test_factory_produces_expected_status[0]").
                $label = count($cases) > 1 ? $name . '[' . $i . ']' : $name;
                $instance = $ref->newInstanceWithoutConstructor();
                $instance->name = $name;
                $setUp = $ref->getMethod('setUp');
                $tearDown = $ref->getMethod('tearDown');

                try {
                    $setUp->invoke($instance);
                    $thrown = null;
                    try {
                        $m->invokeArgs($instance, (array) $args);
                    } catch (\Throwable $e) {
                        // An AssertionFailedError is a real failure, not an
                        // expected exception. Rethrow unless the test wanted it.
                        if ($instance->expectedExceptionClass() === null
                            && !($e instanceof \PHPUnit\Framework\AssertionFailedError)) {
                            // If expectedException wasn't set, surface the throwable as a failure.
                            $thrown = $e;
                        } elseif ($instance->expectedExceptionClass() === null) {
                            throw $e;
                        } else {
                            $thrown = $e;
                        }
                    }
                    $instance->__assertExceptionExpectationMet($thrown);
                    // If no exception was expected and something was caught,
                    // surface it as a failure rather than silently passing.
                    if ($instance->expectedExceptionClass() === null && $thrown !== null) {
                        throw $thrown;
                    }
                    $tearDown->invoke($instance);
                    echo "    ✓ $label\n";
                    $totalPass++;
                } catch (\Throwable $e) {
                    try { $tearDown->invoke($instance); } catch (\Throwable $_) {}
                    echo "    ✗ $label — " . $e->getMessage() . "\n";
                    $totalFail++;
                    $failures[] = "$class::$label — " . $e->getMessage();
                }
            }
        }
    }
}

echo "\n── Summary ──\n";
echo "  Passed: $totalPass   Failed: $totalFail\n";
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($totalFail ? 1 : 0);
