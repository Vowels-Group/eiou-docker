<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

/**
 * Strength rules for the user-chosen alternate authentication code.
 *
 * The alt code lives alongside the BIP39-derived primary authcode. The
 * primary has ~80 bits of machine-generated entropy; the alt is user-
 * chosen and inherently weaker, so we enforce explicit complexity rules
 * here before letting it become a valid login credential.
 *
 * The rules are deliberately conservative — see docs/ARCHITECTURE.md for
 * the threat-model discussion (rate-limit-only online resistance vs.
 * Argon2id-backed offline resistance).
 */
class AltCodeValidator
{
    /**
     * Minimum acceptable length. 12 is the floor — see threat-model notes
     * in CHANGELOG.md / docs/ARCHITECTURE.md.
     */
    public const MIN_LENGTH = 12;

    /**
     * Hard upper bound. Larger inputs are rejected to bound Argon2id work
     * and avoid pathological inputs (a 1MB "password" is almost certainly
     * not what the user meant).
     */
    public const MAX_LENGTH = 256;

    /**
     * Small bundled list of frequently-observed weak passwords. Not a
     * comprehensive dictionary — just a tripwire for the most obvious
     * choices. A full zxcvbn-style dictionary lives outside this class so
     * the file stays manageable; the strength-meter UI mirrors this list
     * and adds its own client-side checks.
     */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', 'qwerty', 'qwerty123',
        'letmein', 'welcome', 'monkey', 'dragon', 'master', 'admin',
        '123456', '12345678', '123456789', '1234567890',
        'iloveyou', 'sunshine', 'princess', 'football',
        'changeme', 'passw0rd', 'p@ssword', 'p@ssw0rd',
        'eiou', 'wallet', 'bitcoin', 'satoshi',
    ];

    /**
     * Validate a candidate alt code.
     *
     * Returns an associative array with `valid` (bool) and `errors` (a
     * list of human-readable strings; empty when valid). Multiple rules
     * may fail at once — every failure is reported in one pass so the
     * GUI/CLI can render them together.
     */
    public static function validate(string $candidate): array
    {
        $errors = [];

        $len = strlen($candidate);

        if ($len < self::MIN_LENGTH) {
            $errors[] = 'Alt code must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if ($len > self::MAX_LENGTH) {
            $errors[] = 'Alt code must be at most ' . self::MAX_LENGTH . ' characters.';
        }
        if (!preg_match('/[a-z]/', $candidate)) {
            $errors[] = 'Alt code must include at least one lowercase letter.';
        }
        if (!preg_match('/[A-Z]/', $candidate)) {
            $errors[] = 'Alt code must include at least one uppercase letter.';
        }
        if (!preg_match('/[0-9]/', $candidate)) {
            $errors[] = 'Alt code must include at least one digit.';
        }
        // Any non-alphanumeric printable character counts as a symbol.
        // Whitespace inside the code is intentionally allowed — passphrase
        // patterns like "correct horse battery 9!" should not be rejected
        // for using spaces.
        if (!preg_match('/[^A-Za-z0-9]/', $candidate)) {
            $errors[] = 'Alt code must include at least one symbol.';
        }

        // Reject runs of three or more identical characters (aaa, 111).
        if (preg_match('/(.)\1\1/', $candidate)) {
            $errors[] = 'Alt code must not contain three or more repeated characters in a row.';
        }

        // Reject straight ascending/descending sequences of length ≥ 4
        // (abcd, 1234, dcba). Heuristic — not a full zxcvbn analysis.
        if (self::hasSequentialRun($candidate, 4)) {
            $errors[] = 'Alt code must not contain a long sequence like "1234" or "abcd".';
        }

        // Common-password tripwire (case-insensitive substring match on
        // the normalized candidate). This catches "Password1!" and the
        // small obvious cases — not a substitute for a real strength
        // meter, but cheap and high-signal.
        $normalized = strtolower($candidate);
        foreach (self::COMMON_PASSWORDS as $common) {
            if (strpos($normalized, $common) !== false) {
                $errors[] = 'Alt code is too close to a commonly-used password.';
                break;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Whether $s contains a run of $minRun characters whose ordinals are
     * strictly monotonic (all +1 or all -1). Used to catch "abcd", "4321".
     */
    private static function hasSequentialRun(string $s, int $minRun): bool
    {
        $n = strlen($s);
        if ($n < $minRun) {
            return false;
        }
        $run = 1;
        $dir = 0; // 0 = unset, +1 ascending, -1 descending
        for ($i = 1; $i < $n; $i++) {
            $delta = ord($s[$i]) - ord($s[$i - 1]);
            if ($delta === 1 || $delta === -1) {
                if ($dir === 0 || $dir === $delta) {
                    $run++;
                    $dir = $delta;
                    if ($run >= $minRun) {
                        return true;
                    }
                    continue;
                }
            }
            $run = 1;
            $dir = 0;
        }
        return false;
    }
}
