<?php
// tests/_shim/phpunit-minimal.php
//
// Minimal PHPUnit-compatible shim loaded ONLY when real PHPUnit isn't on
// the composer autoloader (e.g. running tests before `composer require
// --dev phpunit/phpunit`, or inside a sandbox where we can't install it).
//
// Supports the assertion + lifecycle surface this codebase's tests use.
// When real PHPUnit is available, this file is skipped entirely and the
// tests run against the real framework with full reporter, filters, etc.

namespace PHPUnit\Framework {

    class AssertionFailedError extends \Exception {}
    class ExpectationFailedException extends AssertionFailedError {}

    abstract class TestCase
    {
        /** Name of the method currently executing — set by the runner. */
        public string $name = '';

        /** Expected exception (class name), set via expectException(). */
        private ?string $expectedException = null;

        protected function setUp(): void {}
        protected function tearDown(): void {}

        // ── Expectation helpers ──────────────────────────────────────────
        protected function expectException(string $class): void
        {
            $this->expectedException = $class;
        }

        /** @internal Called by the runner AFTER the test body. */
        public function __assertExceptionExpectationMet(?\Throwable $thrown): void
        {
            if ($this->expectedException === null) return;
            if ($thrown === null) {
                throw new AssertionFailedError(
                    "Expected exception [{$this->expectedException}] was not thrown."
                );
            }
            if (!($thrown instanceof $this->expectedException)) {
                throw new AssertionFailedError(
                    "Expected [{$this->expectedException}], got [" . get_class($thrown) . ']: ' . $thrown->getMessage()
                );
            }
        }

        public function expectedExceptionClass(): ?string
        {
            return $this->expectedException;
        }

        // ── Assertions (subset used by this codebase) ─────────────────────
        public static function assertTrue(mixed $actual, string $message = ''): void
        {
            if ($actual !== true) self::fail($message ?: 'Failed asserting true; got ' . self::dump($actual));
        }

        public static function assertFalse(mixed $actual, string $message = ''): void
        {
            if ($actual !== false) self::fail($message ?: 'Failed asserting false; got ' . self::dump($actual));
        }

        public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                self::fail($message ?: 'Failed asserting identical:' .
                    "\n  expected: " . self::dump($expected) .
                    "\n  actual:   " . self::dump($actual));
            }
        }

        public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            if ($expected === $actual) self::fail($message ?: 'Failed asserting not-same: both were ' . self::dump($actual));
        }

        public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
        {
            if (!($actual instanceof $expected)) {
                self::fail($message ?: "Failed asserting instance of [$expected]; got " . self::dump($actual));
            }
        }

        public static function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
        {
            $c = count($actual);
            if ($c !== $expected) self::fail($message ?: "Failed asserting count $expected; got $c");
        }

        public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (!str_contains($haystack, $needle)) {
                self::fail($message ?: "Failed asserting [$haystack] contains [$needle]");
            }
        }

        public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
        {
            if (array_key_exists($key, $array)) self::fail($message ?: "Array has key [$key] but should not");
        }

        public static function assertFileExists(string $path, string $message = ''): void
        {
            if (!is_file($path)) self::fail($message ?: "File does not exist: $path");
        }

        public static function fail(string $message): never
        {
            throw new AssertionFailedError($message);
        }

        private static function dump(mixed $v): string
        {
            if (is_object($v)) return get_class($v) . '#';
            if (is_array($v))  return 'array(' . count($v) . ')';
            return var_export($v, true);
        }
    }

    class TestSuite {}
    class TestResult {}
}

namespace PHPUnit\Framework\Attributes {
    // Tests that use #[DataProvider] attributes would need a shim for that too,
    // but our codebase uses @dataProvider docblocks which the runner parses directly.
}
