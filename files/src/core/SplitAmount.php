<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use InvalidArgumentException;
use OverflowException;

/**
 * SplitAmount — Value object for split integer/fractional amount storage.
 *
 * Stores monetary amounts as two separate BIGINTs:
 *   - $whole: the integer part (e.g., 1234 for $1234.56)
 *   - $frac:  the fractional part × 10^8 (e.g., 56000000 for .56)
 *
 * This avoids the 92-billion ceiling imposed by storing the full amount as
 * a single BIGINT (whole * 10^8 + frac). With split storage the maximum
 * representable whole is PHP_INT_MAX (~9.2 quintillion), though the practical
 * transaction limit is lower to leave headroom for relay fee accumulation.
 *
 * Fee calculations use bcmath strings throughout — the whole and frac parts
 * are never recombined into a single PHP integer, so no intermediate overflow
 * is possible regardless of amount size.
 *
 * Fractional part is always in the range [0, FRAC_MODULUS).
 * Negative amounts are represented by a negative $whole with a non-negative $frac.
 * For example, -1.50 is stored as whole=-2, frac=50000000 (i.e., -2 + 0.50 = -1.50).
 *
 * REQUIRES: php-bcmath extension (always available in Docker image).
 */
class SplitAmount implements \JsonSerializable
{
    /** Fractional modulus — 10^8, same as INTERNAL_CONVERSION_FACTOR */
    public const FRAC_MODULUS = 100000000;

    /** Number of fractional digits (log10 of FRAC_MODULUS) */
    private const FRAC_MODULUS_DIGITS = 8;

    public int $whole;
    public int $frac;

    public function __construct(int $whole, int $frac)
    {
        if ($frac < 0 || $frac >= self::FRAC_MODULUS) {
            throw new InvalidArgumentException(
                "Fractional part must be in [0, " . self::FRAC_MODULUS . "), got {$frac}"
            );
        }
        $this->whole = $whole;
        $this->frac = $frac;
    }

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create a zero SplitAmount
     */
    public static function zero(): self
    {
        return new self(0, 0);
    }

    /**
     * Create from a float in major units (e.g., 1234.56).
     *
     * WARNING: PHP floats have ~15-16 significant digits of precision.
     * For values larger than ~10^15, use fromString() instead to avoid
     * silent precision loss from float conversion.
     */
    public static function fromMajorUnits(float $major): self
    {
        // Avoid scientific notation from (string) cast on very large/small floats
        // by formatting with full decimal precision
        $str = number_format($major, self::FRAC_MODULUS_DIGITS, '.', '');
        $parts = explode('.', $str);
        $whole = (int) $parts[0];

        if (count($parts) === 1) {
            return new self($whole, 0);
        }

        // Pad or truncate fractional string to 8 digits
        $fracStr = str_pad(substr($parts[1], 0, 8), 8, '0');
        $frac = (int) $fracStr;

        // Handle negative: e.g., -1.5 → whole=-1, fracStr="5" → need whole=-2, frac=50000000
        if ($major < 0 && $frac > 0) {
            $whole -= 1;
            $frac = self::FRAC_MODULUS - $frac;
        }

        return new self($whole, $frac);
    }

    /**
     * Create from a decimal string (e.g., "1234.56" or "9223372036854775807").
     *
     * Uses pure string operations — no float conversion — so arbitrarily large
     * whole parts (up to PHP_INT_MAX) are handled without precision loss.
     * This is the preferred factory for user-supplied input.
     *
     * @param string $value Decimal string (e.g., "1234.56789012")
     * @return self
     * @throws \OverflowException if whole part exceeds PHP_INT_MAX
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return self::zero();
        }

        // Handle scientific notation by normalising to a plain decimal string
        if (stripos($value, 'e') !== false) {
            $value = \bcadd($value, '0', self::FRAC_MODULUS_DIGITS);
        }

        // Detect and strip sign
        $negative = false;
        if ($value[0] === '-') {
            $negative = true;
            $value = substr($value, 1);
        }

        // Split on decimal point
        $parts = explode('.', $value, 2);
        $wholeStr = ltrim($parts[0], '0') ?: '0';

        // Validate whole part fits in PHP int
        $maxStr = (string) PHP_INT_MAX;
        if (strlen($wholeStr) > strlen($maxStr)
            || (strlen($wholeStr) === strlen($maxStr) && strcmp($wholeStr, $maxStr) > 0)) {
            throw new \OverflowException(
                "Whole part exceeds PHP_INT_MAX: {$wholeStr}"
            );
        }

        $whole = (int) $wholeStr;
        $frac = 0;

        if (isset($parts[1]) && $parts[1] !== '') {
            $fracStr = str_pad(substr($parts[1], 0, 8), 8, '0');
            $frac = (int) $fracStr;
        }

        if ($negative) {
            $whole = -$whole;
            if ($frac > 0) {
                $whole -= 1;
                $frac = self::FRAC_MODULUS - $frac;
            }
        }

        return new self($whole, $frac);
    }

    /**
     * Create from the old single-integer minor units representation.
     * E.g., 123456000000 → whole=1234, frac=56000000.
     *
     * Only works for amounts where whole * FRAC_MODULUS + frac fits in a PHP int
     * (i.e., amounts up to ~92 billion). For larger amounts, use fromDb() or fromArray().
     */
    public static function fromMinorUnits(int $minor): self
    {
        if ($minor >= 0) {
            $whole = intdiv($minor, self::FRAC_MODULUS);
            $frac = $minor % self::FRAC_MODULUS;
        } else {
            $whole = intdiv($minor, self::FRAC_MODULUS);
            $frac = $minor - ($whole * self::FRAC_MODULUS);
            if ($frac < 0) {
                $whole -= 1;
                $frac += self::FRAC_MODULUS;
            }
        }
        return new self($whole, $frac);
    }

    /**
     * Create from a bcmath minor-units string.
     * Splits into whole/frac using bcmath — no PHP int overflow possible
     * on the intermediate value, only the final whole and frac must fit.
     */
    private static function fromMinorUnitsString(string $minorStr): self
    {
        $mod = (string) self::FRAC_MODULUS;
        $wholeStr = \bcdiv($minorStr, $mod, 0);
        $fracStr = \bcmod($minorStr, $mod);

        // Handle negative frac from bcmod
        if (\bccomp($fracStr, '0') < 0) {
            $wholeStr = \bcsub($wholeStr, '1', 0);
            $fracStr = \bcadd($fracStr, $mod, 0);
        }

        return new self((int) $wholeStr, (int) $fracStr);
    }

    /**
     * Universal factory — accepts any representation and returns a SplitAmount.
     *
     * Handles: SplitAmount (passthrough), {whole,frac} array (from JSON/payload),
     * string (decimal via fromString — preserves precision for large numbers),
     * int/float (from legacy code or major units), or null (returns zero).
     * This is the single conversion point for all SplitAmount ↔ JSON/array boundaries.
     *
     * @param self|array|int|float|string|null $value Any amount representation
     * @return self
     */
    public static function from(self|array|int|float|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_array($value) && (isset($value['whole']) || isset($value['frac']))) {
            return new self((int) ($value['whole'] ?? 0), (int) ($value['frac'] ?? 0));
        }
        if (is_string($value) && is_numeric($value)) {
            return self::fromString($value);
        }
        if (is_int($value)) {
            return new self($value, 0);
        }
        if (is_float($value)) {
            return self::fromMajorUnits($value);
        }
        return self::zero();
    }

    /**
     * Create from database row columns (whole + frac).
     */
    public static function fromDb(int $whole, int $frac): self
    {
        return new self($whole, $frac);
    }

    /**
     * Extract a SplitAmount from a database row using column prefix.
     * Reads {$prefix}_whole and {$prefix}_frac from the row.
     * Returns null if neither column exists.
     *
     * @param array $row Database row (associative array)
     * @param string $prefix Column name prefix (e.g., 'amount', 'my_fee_amount')
     * @return self|null SplitAmount or null if columns don't exist
     */
    public static function fromDbRow(array $row, string $prefix): ?self
    {
        $wholeKey = $prefix . '_whole';
        $fracKey = $prefix . '_frac';

        if (!array_key_exists($wholeKey, $row) && !array_key_exists($fracKey, $row)) {
            return null;
        }

        return new self(
            (int) ($row[$wholeKey] ?? 0),
            (int) ($row[$fracKey] ?? 0)
        );
    }

    /**
     * Map split columns in a database row back to logical field names as SplitAmount objects.
     *
     * Converts {prefix}_whole/{prefix}_frac column pairs into a single $row[prefix]
     * containing a SplitAmount object. This allows callers that access $row['amount']
     * to work transparently after the column split.
     *
     * @param array $row Database row
     * @param string[] $prefixes Column prefixes to map (e.g., ['amount', 'my_fee_amount'])
     * @return array Row with SplitAmount objects added for each prefix
     */
    public static function mapDbRow(array $row, array $prefixes): array
    {
        foreach ($prefixes as $prefix) {
            $amount = self::fromDbRow($row, $prefix);
            if ($amount !== null) {
                $row[$prefix] = $amount;
            }
        }
        return $row;
    }

    /**
     * Create from payload array with 'whole' and 'frac' keys.
     */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['whole'] ?? 0), (int) ($data['frac'] ?? 0));
    }

    // =========================================================================
    // CONVERSION METHODS
    // =========================================================================

    /**
     * Convert back to the old single-integer minor units representation.
     *
     * WARNING: Throws OverflowException for amounts > ~92 billion.
     * Use only for contexts where the amount is known to be small
     * (e.g., fee percentages stored as scaled integers).
     *
     * @throws OverflowException if the amount cannot fit in a single PHP int
     */
    public function toMinorUnits(): int
    {
        // Check overflow before computing
        if ($this->whole > 0 && $this->whole > intdiv(PHP_INT_MAX - $this->frac, self::FRAC_MODULUS)) {
            throw new OverflowException(
                "SplitAmount {$this} exceeds single-integer representation (~92 billion max)"
            );
        }
        if ($this->whole < 0 && $this->whole < intdiv(PHP_INT_MIN + $this->frac, self::FRAC_MODULUS)) {
            throw new OverflowException(
                "SplitAmount {$this} exceeds single-integer representation (~92 billion max)"
            );
        }
        return $this->whole * self::FRAC_MODULUS + $this->frac;
    }

    /**
     * Convert to major units (float) for display.
     */
    public function toMajorUnits(): float
    {
        return $this->whole + ($this->frac / self::FRAC_MODULUS);
    }

    /**
     * Convert to display string with specified decimal places.
     */
    public function toDisplayString(int $decimals = 2): string
    {
        return number_format($this->toMajorUnits(), $decimals, '.', '');
    }

    /**
     * Return as array for payload serialization.
     */
    public function toArray(): array
    {
        return ['whole' => $this->whole, 'frac' => $this->frac];
    }

    /**
     * JSON serialization — always emits {whole, frac}.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // =========================================================================
    // ARITHMETIC OPERATIONS
    //
    // All operations keep whole and frac separate. Fee calculations use bcmath
    // strings internally so no intermediate PHP integer can overflow,
    // regardless of how large the amounts are.
    // =========================================================================

    /**
     * Add two SplitAmounts.
     *
     * Frac carry is at most 1 (two fracs sum to < 2 * FRAC_MODULUS).
     *
     * @throws OverflowException if whole part overflows PHP_INT_MAX
     */
    public function add(self $other): self
    {
        $frac = $this->frac + $other->frac;
        $carry = intdiv($frac, self::FRAC_MODULUS);
        $frac = $frac % self::FRAC_MODULUS;

        // Overflow guard: check BEFORE adding to avoid PHP silent int→float conversion
        if ($other->whole > 0 && $this->whole > PHP_INT_MAX - $other->whole) {
            throw new OverflowException(
                "SplitAmount::add overflow: {$this->whole} + {$other->whole} exceeds PHP_INT_MAX"
            );
        }
        if ($other->whole < 0 && $this->whole < PHP_INT_MIN - $other->whole) {
            throw new OverflowException(
                "SplitAmount::add underflow: {$this->whole} + {$other->whole} exceeds PHP_INT_MIN"
            );
        }
        $whole = $this->whole + $other->whole;

        // carry is 0 or 1, so this can only overflow if whole is already PHP_INT_MAX
        if ($carry > 0 && $whole >= PHP_INT_MAX) {
            throw new OverflowException(
                "SplitAmount::add overflow: whole {$whole} + carry {$carry} exceeds PHP_INT_MAX"
            );
        }
        $whole += $carry;

        return new self($whole, $frac);
    }

    /**
     * Subtract another SplitAmount from this one.
     */
    public function subtract(self $other): self
    {
        $frac = $this->frac - $other->frac;
        $whole = $this->whole - $other->whole;

        if ($frac < 0) {
            $whole -= 1;
            $frac += self::FRAC_MODULUS;
        }

        return new self($whole, $frac);
    }

    /**
     * Multiply by a percentage (e.g., 2.5 for 2.5%) and return the result.
     *
     * All intermediate values are bcmath strings — no PHP int overflow
     * is possible regardless of how large this amount is. Only the final
     * whole and frac parts are converted to PHP ints (they individually
     * must fit in BIGINT, which is the DB storage constraint).
     *
     * @param float $percent Percentage (e.g., 2.5 means 2.5%)
     * @return self The result of amount * percent / 100
     */
    public function multiplyPercent(float $percent): self
    {
        if ($percent == 0.0) {
            return self::zero();
        }

        $mod = (string) self::FRAC_MODULUS;

        // Total minor units as bcmath string (never a PHP int)
        $totalMinor = \bcadd(
            \bcmul((string) $this->whole, $mod, 0),
            (string) $this->frac,
            0
        );

        // fee in minor units as bcmath string (truncated toward zero)
        $feeMinor = \bcdiv(
            \bcmul($totalMinor, (string) $percent, 8),
            '100',
            0
        );

        // Split back to whole/frac via bcmath — never touches PHP int limits
        return self::fromMinorUnitsString($feeMinor);
    }

    /**
     * Exact multiply-then-divide.
     * Computes floor(amount * multiplier / divisor).
     *
     * All intermediate values are bcmath strings.
     */
    public function mulDiv(float $multiplier, float $divisor): self
    {
        if ($multiplier == 0.0) {
            return self::zero();
        }

        $mod = (string) self::FRAC_MODULUS;

        $totalMinor = \bcadd(
            \bcmul((string) $this->whole, $mod, 0),
            (string) $this->frac,
            0
        );

        $resultMinor = \bcdiv(
            \bcmul($totalMinor, (string) $multiplier, 8),
            (string) $divisor,
            0
        );

        return self::fromMinorUnitsString($resultMinor);
    }

    // =========================================================================
    // COMPARISON OPERATIONS
    // =========================================================================

    /**
     * Check if this amount is zero.
     */
    public function isZero(): bool
    {
        return $this->whole === 0 && $this->frac === 0;
    }

    /**
     * Check if this amount is negative.
     */
    public function isNegative(): bool
    {
        return $this->whole < 0;
    }

    /**
     * Check if this amount is positive (greater than zero).
     */
    public function isPositive(): bool
    {
        return $this->whole > 0 || ($this->whole === 0 && $this->frac > 0);
    }

    /**
     * Compare to another SplitAmount.
     * Returns -1, 0, or 1.
     */
    public function compareTo(self $other): int
    {
        if ($this->whole !== $other->whole) {
            return $this->whole <=> $other->whole;
        }
        return $this->frac <=> $other->frac;
    }

    /**
     * Check if this amount is greater than or equal to another.
     */
    public function gte(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /**
     * Check if this amount is less than another.
     */
    public function lt(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    public function __toString(): string
    {
        return $this->whole . '.' . str_pad((string) $this->frac, 8, '0', STR_PAD_LEFT);
    }
}
