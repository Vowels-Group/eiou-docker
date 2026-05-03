<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Validators\Checksum;

/**
 * IBAN (International Bank Account Number) checksum validator.
 *
 * ISO-13616 mod-97 algorithm:
 *   1. Move the first 4 characters (country code + check digits) to the end.
 *   2. Replace letters with digits (A=10, B=11 ... Z=35).
 *   3. The resulting number modulo 97 must equal 1.
 *
 * Also enforces per-country length (e.g. DE=22, GB=22, FR=27, ES=24, IT=27).
 */
class Iban
{
    /**
     * Country-code → total IBAN length. Source: SWIFT IBAN Registry.
     * Kept intentionally compact — covers every country that has assigned an IBAN.
     */
    private const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28,
        'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BI' => 27,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DJ' => 27, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18,
        'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18,
        'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30,
        'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21,
        'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27,
        'MD' => 24, 'ME' => 22, 'MK' => 19, 'MN' => 20, 'MR' => 27,
        'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15,
        'OM' => 23, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25,
        'QA' => 29, 'RO' => 24, 'RS' => 22, 'RU' => 33, 'SA' => 24,
        'SC' => 31, 'SD' => 18, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'SO' => 23, 'ST' => 25, 'SV' => 28, 'TL' => 23,
        'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24,
        'XK' => 20, 'YE' => 30,
    ];

    /**
     * Return true iff $iban is syntactically a valid IBAN with a passing mod-97 checksum.
     * Whitespace and dashes are tolerated and stripped before validation.
     */
    public static function isValid(string $iban): bool
    {
        $normalised = self::normalise($iban);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $normalised)) {
            return false;
        }

        $country = substr($normalised, 0, 2);
        if (!isset(self::LENGTHS[$country])) {
            return false;
        }
        if (strlen($normalised) !== self::LENGTHS[$country]) {
            return false;
        }

        // Move the first 4 characters to the end.
        $rearranged = substr($normalised, 4) . substr($normalised, 0, 4);

        // Replace each letter with its numeric value (A=10 ... Z=35).
        $numeric = '';
        $len = strlen($rearranged);
        for ($i = 0; $i < $len; $i++) {
            $c = $rearranged[$i];
            if (ctype_digit($c)) {
                $numeric .= $c;
            } elseif (ctype_alpha($c)) {
                $numeric .= (string) (ord($c) - ord('A') + 10);
            } else {
                return false;
            }
        }

        return self::mod97($numeric) === 1;
    }

    /**
     * Strip whitespace/dashes and upper-case the IBAN for consistent handling.
     */
    public static function normalise(string $iban): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $iban));
    }

    /**
     * Compute the mod-97 of an arbitrarily long decimal string.
     * Avoids bcmath by chunking.
     */
    private static function mod97(string $numeric): int
    {
        $remainder = 0;
        $len = strlen($numeric);
        for ($i = 0; $i < $len; $i += 7) {
            $chunk = (string) $remainder . substr($numeric, $i, 7);
            $remainder = (int) $chunk % 97;
        }
        return $remainder;
    }
}
