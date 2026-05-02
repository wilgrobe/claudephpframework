<?php
// modules/coppa/Services/CoppaService.php
namespace Modules\Coppa\Services;

/**
 * Age-gate logic for COPPA / GDPR Art. 8 / UK Children's Code.
 *
 * Stateless — every method just reads settings or computes from inputs.
 * The actual rejection happens in AuthController; this service supplies
 * the predicate.
 */
class CoppaService
{
    /** Hard floor + ceiling for the configurable minimum age. */
    public const MIN_CONFIGURABLE = 1;
    public const MAX_CONFIGURABLE = 21;

    public function isEnabled(): bool
    {
        return (bool) (setting('coppa_enabled', false) ?? false);
    }

    public function minimumAge(): int
    {
        $n = (int) (setting('coppa_minimum_age', 13) ?? 13);
        if ($n < self::MIN_CONFIGURABLE) $n = self::MIN_CONFIGURABLE;
        if ($n > self::MAX_CONFIGURABLE) $n = self::MAX_CONFIGURABLE;
        return $n;
    }

    public function blockMessage(): string
    {
        $tpl = (string) (setting('coppa_block_message',
            'Sorry — you must be at least {age} years old to create an account on this site.'
        ) ?? '');
        return str_replace('{age}', (string) $this->minimumAge(), $tpl);
    }

    /**
     * Compute age in years from a Y-m-d date string. Returns null on
     * malformed input.
     */
    public function computeAge(string $dateOfBirth, ?\DateTimeImmutable $on = null): ?int
    {
        $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirth);
        if ($dob === false) return null;
        $on = $on ?? new \DateTimeImmutable('today');
        if ($dob > $on) return null; // future-dated DOB
        return (int) $dob->diff($on)->y;
    }

    /**
     * Does this birthdate clear the configured minimum?
     * Returns true when COPPA is disabled (gate is open).
     */
    public function passesAgeGate(string $dateOfBirth): bool
    {
        if (!$this->isEnabled()) return true;
        $age = $this->computeAge($dateOfBirth);
        if ($age === null) return false;
        return $age >= $this->minimumAge();
    }
}
