<?php
// core/Database/Migration.php
namespace Core\Database;

/**
 * Base class for PHP migrations. Each migration file under
 * database/migrations/ (or any module's migrations/ directory) should
 * return an anonymous class that extends this:
 *
 *   // database/migrations/2026_04_21_120000_create_widgets.php
 *   use Core\Database\Migration;
 *
 *   return new class extends Migration {
 *       public function up(): void {
 *           $this->db->query("
 *               CREATE TABLE widgets (
 *                 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *                 name VARCHAR(120) NOT NULL,
 *                 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 *       }
 *       public function down(): void {
 *           $this->db->query("DROP TABLE IF EXISTS widgets");
 *       }
 *   };
 *
 * The Database instance is injected so you have the same prepared-statement
 * safety net the rest of the app relies on. Raw DDL goes through $db->query()
 * and still benefits from the connection's error mode / strict SQL mode.
 */
abstract class Migration
{
    /**
     * Set by the Migrator via reflection immediately after instantiation.
     * Not a constructor parameter so user-land migrations can write
     * `new class extends Migration { ... }` without knowing about DI.
     */
    protected Database $db;

    /** Apply the change. */
    abstract public function up(): void;

    /** Reverse it. Should be a safe inverse of up(); no-op is acceptable. */
    abstract public function down(): void;
}
