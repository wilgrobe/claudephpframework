<?php
// tests/TestCase.php
namespace Tests;

use Core\Container\Container;
use Core\Database\Database;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared base for framework tests.
 *
 * Unit tests don't need anything beyond PHPUnit — they extend this mostly
 * for consistency and the shared helpers.
 *
 * Feature tests that exercise the container or router can call
 * bootContainer() in setUp() to get a fresh container populated with the
 * minimum bindings they need.
 *
 * We avoid booting the full `core/bootstrap.php` here because it wires
 * Database::getInstance() (which expects a live MySQL connection), the
 * module registry (which scans modules/ every call), and sentry.
 * Tests should compose only the pieces they actually need.
 */
abstract class TestCase extends BaseTestCase
{
    protected ?Container $container = null;

    /**
     * Build a fresh container. Feature tests that want
     * auto-wiring through the framework can call this in setUp().
     */
    protected function bootContainer(): Container
    {
        $this->container = new Container();
        Container::setGlobal($this->container);
        return $this->container;
    }

    /**
     * Install a test double for Database::getInstance() so code under test
     * sees our mock instead of trying to connect to MySQL.
     */
    protected function mockDatabase(Database $mock): void
    {
        $ref = new \ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, $mock);
    }

    protected function tearDown(): void
    {
        // Reset Container + Database singletons between tests so leakage
        // from one test can't poison the next.
        Container::setGlobal(new Container());

        $ref = new \ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        parent::tearDown();
    }
}
