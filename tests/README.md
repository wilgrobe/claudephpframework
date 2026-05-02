# Test Suite

PHPUnit-based test suite for the framework core.

## Running

### Recommended (real PHPUnit)

```bash
composer require --dev phpunit/phpunit:^10.5
./vendor/bin/phpunit                  # all tests
./vendor/bin/phpunit --testsuite=Unit # just the unit suite
./vendor/bin/phpunit tests/Unit/Container/ContainerTest.php
./vendor/bin/phpunit --filter test_autowires_constructor_dependencies_by_type
```

### Sandbox fallback (no composer)

A minimal built-in runner works without PHPUnit installed — useful for
quick smoke checks or environments where `composer install` isn't available:

```bash
./bin/php tests/_runner.php
```

The runner loads `tests/_shim/phpunit-minimal.php` when the real
`PHPUnit\Framework\TestCase` isn't on the autoloader. It supports
`setUp`/`tearDown`, `assertSame`/`assertTrue`/`assertFalse`/`assertSame`/
`assertNotSame`/`assertInstanceOf`/`assertCount`/`assertStringContainsString`/
`assertArrayNotHasKey`/`assertFileExists`, `expectException`, and
`@dataProvider`. Test files don't need any changes to work under either.

## Layout

```
tests/
├── bootstrap.php       — PHPUnit entrypoint; loads composer autoload + Tests\ PSR-4
├── TestCase.php        — shared base with bootContainer() + mockDatabase() helpers
├── Unit/               — fast isolated tests, no DB or HTTP
│   ├── Container/      — container resolution, autowiring, contextual bindings
│   ├── Router/         — verbs, groups, named routes, urlFor
│   ├── Database/       — query builder SQL compilation, Migrator file scanning
│   ├── Http/           — HttpException factories, Resource transformers
│   ├── View/           — layouts, sections, components
│   └── Console/        — command registry + dispatch
└── Feature/            — end-to-end tests that may boot the container (empty for now)
```

## Writing Tests

Extend `Tests\TestCase`:

```php
namespace Tests\Unit\MyFeature;

use Tests\TestCase;

final class MyThingTest extends TestCase
{
    public function test_it_does_the_thing(): void
    {
        $this->assertTrue(true);
    }
}
```

For tests that need the framework container:

```php
protected function setUp(): void
{
    parent::setUp();
    $c = $this->bootContainer();
    $c->singleton(SomeService::class);
    // ...
}
```

For tests that would otherwise touch MySQL, pass a mock to `mockDatabase()`:

```php
$spy = new SpyDb();  // subclass of Core\Database\Database
$this->mockDatabase($spy);
```

`TestCase::tearDown()` resets the Container and Database singletons between
tests, so leakage from one test can't poison the next.
