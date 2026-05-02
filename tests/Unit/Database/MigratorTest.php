<?php
// tests/Unit/Database/MigratorTest.php
namespace Tests\Unit\Database;

use Core\Database\Database;
use Core\Database\Migration;
use Core\Database\Migrator;
use Tests\TestCase;

final class MigratorTest extends TestCase
{
    private string $tmpMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpMigrations = sys_get_temp_dir() . '/migtest_' . uniqid();
        mkdir($this->tmpMigrations, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("$this->tmpMigrations/*.php") ?: [] as $f) unlink($f);
        @rmdir($this->tmpMigrations);
        parent::tearDown();
    }

    public function test_discover_finds_php_files_and_sorts_by_filename(): void
    {
        file_put_contents("$this->tmpMigrations/2026_01_02_000000_b.php", "<?php\n");
        file_put_contents("$this->tmpMigrations/2026_01_01_000000_a.php", "<?php\n");

        $db = $this->makeMockDatabase();
        $m  = new Migrator($db, [$this->tmpMigrations]);
        $found = array_keys($m->discover());

        $this->assertSame(['2026_01_01_000000_a', '2026_01_02_000000_b'], $found);
    }

    public function test_make_creates_a_stub_file_and_returns_its_path(): void
    {
        $db = $this->makeMockDatabase();
        $m  = new Migrator($db, [$this->tmpMigrations]);
        $path = $m->make('create_widgets_table');

        $this->assertFileExists($path);
        $this->assertStringContainsString('create_widgets_table', $path);
        $this->assertStringContainsString('extends Migration', file_get_contents($path));
    }

    public function test_make_returns_file_that_evaluates_to_Migration_instance(): void
    {
        $db   = $this->makeMockDatabase();
        $m    = new Migrator($db, [$this->tmpMigrations]);
        $path = $m->make('sample');
        $obj  = require $path;
        $this->assertInstanceOf(Migration::class, $obj);
    }

    /** Build a Database stub that bypasses the (network-touching) constructor. */
    private function makeMockDatabase(): Database
    {
        return (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    }
}
