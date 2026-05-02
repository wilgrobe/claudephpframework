<?php
// modules/retention/Services/RetentionRule.php
namespace Modules\Retention\Services;

/**
 * Value object describing one retention rule a module declares from
 * its `retentionRules()` hook.
 *
 * Two action modes:
 *   purge     — DELETE FROM {table_name} WHERE {where_clause expanded}
 *   anonymize — UPDATE {table_name} SET col=val,... WHERE {where_clause}
 *
 * The `where_clause` is a SQL fragment using the `{cutoff}` placeholder,
 * expanded at run time to a parameterised value bound from `days_keep`:
 *
 *   where_clause: "created_at < {cutoff}"
 *   days_keep:    365
 *
 *   Becomes: WHERE created_at < ? with [date('Y-m-d H:i:s', NOW() - 1 year)]
 *
 * The keep-it-narrow design (one table per rule, one cutoff column)
 * intentionally forbids cross-table joins / multi-cutoff logic in the
 * rule itself. Modules that need that complexity can declare a
 * customExec callable instead.
 *
 * @property string  $key                Stable identifier — module.table.purpose
 * @property string  $module             Owning module name
 * @property string  $label              Human-readable name shown in admin
 * @property ?string $description        Optional explanation (e.g. why this default value)
 * @property string  $tableName
 * @property string  $whereClause        Uses {cutoff} placeholder
 * @property ?string $dateColumn         Name of date column for display
 * @property int     $daysKeep           Default retention; admin can override
 * @property string  $action             'purge' | 'anonymize'
 * @property array<string, string|int|null|bool> $anonymizeColumns  column => replacement
 * @property bool    $defaultEnabled     Whether the rule should be on after first sync
 */
final class RetentionRule
{
    public const ACTION_PURGE     = 'purge';
    public const ACTION_ANONYMIZE = 'anonymize';

    public function __construct(
        public string  $key,
        public string  $module,
        public string  $label,
        public string  $tableName,
        public string  $whereClause,
        public int     $daysKeep,
        public string  $action            = self::ACTION_PURGE,
        public array   $anonymizeColumns  = [],
        public ?string $dateColumn        = null,
        public ?string $description       = null,
        public bool    $defaultEnabled    = true,
    ) {}
}
