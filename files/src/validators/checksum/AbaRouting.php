<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Validators\Checksum;

/**
 * US ABA routing number checksum validator.
 *
 * Format: exactly 9 digits.
 * Checksum: sum of (digit * weight) for weights [3,7,1,3,7,1,3,7,1] must be
 * divisible by 10. The 9th digit is the check digit derived from the first 8.
 */
class AbaRouting
{
    private const WEIGHTS = [3, 7, 1, 3, 7, 1, 3, 7, 1];

    public static function isValid(string $routing): bool
    {
        $normalised = preg_replace('/\s+/', '', $routing);
        if (!preg_match('/^\d{9}$/', $normalised)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $normalised[$i]) * self::WEIGHTS[$i];
        }
        return $sum % 10 === 0;
    }
}
