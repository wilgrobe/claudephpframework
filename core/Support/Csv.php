<?php
// core/Support/Csv.php
namespace Core\Support;

/**
 * CSV / spreadsheet formula-injection neutralizer (XSS-M1).
 *
 * Excel / LibreOffice / Google Sheets interpret a cell whose first character
 * is `= + - @` (or a leading TAB / CR) as a formula. An attacker who can get
 * text into an exported cell (a form answer, subscriber name, RSVP note, etc.)
 * can ship `=HYPERLINK(...)` / `=cmd|'/c calc'!A1` style payloads that fire
 * when an operator opens the export. Prefix such cells with a single quote so
 * the spreadsheet treats them as literal text.
 *
 * Use safeRow() to map a whole row before fputcsv(), or safeCell() per value.
 */
final class Csv
{
    public static function safeCell(mixed $value): string
    {
        $s = (string) $value;
        if ($s === '') return $s;
        $c = $s[0];
        if ($c === '=' || $c === '+' || $c === '@' || $c === "\t" || $c === "\r") {
            return "'" . $s;
        }
        // A leading '-' is a formula lead-in UNLESS the cell is a plain number
        // (don't mangle legit negative-number columns like prices/amounts).
        if ($c === '-' && !is_numeric($s)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * @param array<int|string,mixed> $row
     * @return array<int|string,string>
     */
    public static function safeRow(array $row): array
    {
        return array_map([self::class, 'safeCell'], $row);
    }
}
