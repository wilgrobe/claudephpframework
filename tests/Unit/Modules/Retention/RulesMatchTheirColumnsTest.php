<?php
namespace Tests\Unit\Modules\Retention;

use Modules\Retention\Services\RetentionRegistry;
use Tests\TestCase;

/**
 * A retention rule that matches nothing looks exactly like one with nothing to do.
 *
 * ⚠⚠⚠ THE RULE THAT SHIPPED BROKEN SAID SO IN ITS OWN DESCRIPTION.
 * `core.security.sessions.expired` compared a TIMESTAMP column against an
 * integer — `last_activity < UNIX_TIMESTAMP({cutoff})` — while its description
 * asserted *"last_activity is stored as a Unix timestamp"*. The schema says
 * otherwise. MySQL coerces rather than erroring, so the clause quietly matched
 * **nothing** and expired sessions were never purged.
 *
 * ⚠⚠ IT IS BROKEN UPSTREAM TOO — the framework's own `0001_framework_schema.php`
 * declares `last_activity timestamp NULL DEFAULT CURRENT_TIMESTAMP` — so this is
 * not a porting artefact, and the confident description is why it survived:
 * anybody checking read the claim rather than the column.
 *
 * ⚠ AND IT IS UNOBSERVABLE FROM THE OUTPUT. A rule reporting "0 rows" is the
 * same output whether it is working on a clean table or comparing two
 * incompatible types. The live proof is a counted preview across an aged row
 * (0 → 1 → 0, done by hand at fix time); what this test guards is the class.
 */
final class RulesMatchTheirColumnsTest extends TestCase
{
    /** @return list<object> */
    private function rules(): array
    {
        return (new RetentionRegistry())->all();
    }

    /**
     * ⚠⚠⚠ A clause using `UNIX_TIMESTAMP()` is claiming its column holds an
     * INTEGER. One that compares the cutoff directly is claiming a date type.
     * Both claims are checkable against the schema, and one of them was wrong.
     */
    public function test_no_rule_compares_a_date_column_to_a_unix_timestamp(): void
    {
        $wrong = [];

        foreach ($this->rules() as $rule) {
            if (!str_contains($rule->whereClause, 'UNIX_TIMESTAMP')) { continue; }

            $type = $this->columnType($rule->tableName, (string) $rule->dateColumn);
            if ($type === null) { continue; }               // table absent in this build

            if (!in_array($type, ['int', 'bigint', 'smallint', 'mediumint'], true)) {
                $wrong[] = sprintf('%s: %s.%s is %s, but the clause wraps the cutoff in UNIX_TIMESTAMP()',
                    $rule->key, $rule->tableName, $rule->dateColumn, $type);
            }
        }

        $this->assertSame([], $wrong,
            "These rules compare a date column against an integer. MySQL coerces rather than "
            . "erroring, so they match NOTHING and delete NOTHING — silently, for ever:\n  - "
            . implode("\n  - ", $wrong));
    }

    /** ⚠ And the mirror: an integer column compared against a date string. */
    public function test_no_rule_compares_an_integer_column_to_a_date(): void
    {
        $wrong = [];

        foreach ($this->rules() as $rule) {
            if (str_contains($rule->whereClause, 'UNIX_TIMESTAMP')) { continue; }

            $type = $this->columnType($rule->tableName, (string) $rule->dateColumn);
            if ($type === null) { continue; }

            if (in_array($type, ['int', 'bigint', 'smallint', 'mediumint'], true)) {
                $wrong[] = sprintf('%s: %s.%s is %s, but the clause compares it to a datetime',
                    $rule->key, $rule->tableName, $rule->dateColumn, $type);
            }
        }

        $this->assertSame([], $wrong, implode("\n  - ", $wrong));
    }

    /**
     * ⚠⚠ THE DESCRIPTION IS PART OF THE RULE. The broken one survived because
     * it stated its assumption confidently in prose, so a reader checked the
     * sentence instead of the schema. A description that names a storage format
     * is an assertion, and it has to be true.
     */
    public function test_no_description_claims_a_storage_format_the_schema_denies(): void
    {
        $wrong = [];

        foreach ($this->rules() as $rule) {
            $claim = strtolower((string) $rule->description);
            if (!str_contains($claim, 'unix timestamp')) { continue; }

            $type = $this->columnType($rule->tableName, (string) $rule->dateColumn);
            if ($type !== null && !in_array($type, ['int', 'bigint'], true)) {
                $wrong[] = sprintf('%s says its column is a Unix timestamp; %s.%s is %s',
                    $rule->key, $rule->tableName, $rule->dateColumn, $type);
            }
        }

        $this->assertSame([], $wrong, implode("\n  - ", $wrong));
    }

    /**
     * ⚠⚠ A GUARD OVER AN EMPTY LIST PROVES NOTHING — and this one caught itself.
     * It first asserted "more than 10 rules" because the LIVE registry returns
     * 17. In the test harness it returns **9**: modules are not discovered
     * here, so only the registry's own built-in `core.*` rules are present, and
     * the 8 contributed by module `retentionRules()` hooks are not.
     *
     * ⚠ The threshold is 9 and NAMED rather than lowered quietly, because the
     * difference is the point: **the checks above only see the core rules**.
     * The rule that shipped broken was a core one, so they do cover it — but a
     * module contributing a mismatched rule would pass this file unnoticed, and
     * whoever reads it next should know that before trusting a green run. The
     * live sweep across all 17 was done by hand at fix time.
     */
    public function test_there_are_rules_to_check(): void
    {
        $rules = $this->rules();

        $this->assertGreaterThanOrEqual(9, count($rules),
            'The retention registry returned almost nothing, so the checks above are vacuous.');

        foreach ($rules as $r) {
            $this->assertStringStartsWith('core.', $r->key,
                "A non-core rule appeared in the test harness ({$r->key}). Module discovery now "
                . 'runs here, so this file sees more than it used to — raise the threshold and '
                . 'say so, rather than leaving a stale comment claiming it sees only 9.');
        }
    }

    /**
     * The declared type of a column, read from the MIGRATIONS.
     *
     * ⚠⚠⚠ THIS ASKED THE DATABASE FIRST, AND THE WHOLE FILE WAS VACUOUS. The
     * unit harness has no database connection: every lookup threw, a `catch`
     * returned `null`, and `null` meant "table not in this build — skip". So
     * all three checks iterated, skipped everything, and passed over an EMPTY
     * SET. A mutation putting the original bug back was not caught, which is
     * the only reason I found out.
     *
     * ⚠⚠ That is exactly the silent-failure shape I have written down twice:
     * one value meaning both "handled" and "I could not tell". My own catch
     * turned "no database" into "nothing to check".
     *
     * ⚠ Reading the migrations is also the RIGHT source: a column's declared
     * type is a static fact about the code, so this now holds on a fresh
     * checkout with no database at all — same reasoning as the GDPR roster
     * test.
     *
     * @return ?string null only when no migration declares this column
     */
    private function columnType(string $table, string $column): ?string
    {
        if ($column === '') { return null; }

        static $types = null;
        if ($types === null) { $types = self::readSchema(); }

        return $types[$table][$column] ?? null;
    }

    /**
     * Every `table.column => type` this codebase declares.
     *
     * @return array<string, array<string, string>>
     */
    private static function readSchema(): array
    {
        $out   = [];
        $files = array_merge(
            glob(BASE_PATH . '/database/migrations/*.php') ?: [],
            glob(BASE_PATH . '/modules/*/migrations/*.php') ?: []
        );

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);

            // CREATE TABLE x ( ... ) — take the body and read each column line.
            if (preg_match_all(
                '~CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE~is',
                $src, $tables, PREG_SET_ORDER
            )) {
                foreach ($tables as [, $table, $body]) {
                    foreach (preg_split('~,\s*\n~', $body) ?: [] as $line) {
                        if (!preg_match('~^\s*`?(\w+)`?\s+(\w+)~', $line, $col)) { continue; }
                        $type = strtolower($col[2]);
                        if (in_array($type, ['primary', 'unique', 'key', 'index', 'constraint', 'foreign'], true)) { continue; }
                        $out[$table][$col[1]] = $type;
                    }
                }
            }

            // ALTER TABLE x ADD COLUMN y <type>
            if (preg_match_all(
                '~ALTER TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?`?(\w+)`?\s+(\w+)~i',
                $src, $alters, PREG_SET_ORDER
            )) {
                foreach ($alters as [, $table, $column, $type]) {
                    $out[$table][$column] = strtolower($type);
                }
            }
        }

        return $out;
    }
}
