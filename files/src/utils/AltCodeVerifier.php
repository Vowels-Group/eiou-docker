<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

/**
 * Constant-timing verifier for the alternate auth code.
 *
 * `Session::authenticate()` and `ApiKeysController::verify()` need a
 * verification path whose wall-clock time is the same whether or not
 * the user has set an alt code. The natural shape — "skip
 * password_verify when no hash is configured" — leaks alt-code
 * presence to a network attacker timing failed logins: ~50 ms when an
 * Argon2id hash is present, ~µs when not.
 *
 * This helper always runs `password_verify` so the timing profile is
 * indistinguishable, against a per-process placeholder hash when no
 * real hash is configured. The placeholder is computed once at first
 * use (Argon2id with the same default cost as the real path) and
 * cached for the lifetime of the process — generating it again on
 * every probe would be wasteful, and an attacker who can introspect
 * process memory has already won.
 *
 * The placeholder's plaintext is `random_bytes(32)` — never disclosed
 * anywhere and never compared to user input, so brute-forcing it would
 * gain the attacker nothing.
 */
class AltCodeVerifier
{
    /** @var string|null Lazily-initialized Argon2id placeholder. */
    private static ?string $placeholder = null;

    /**
     * Return true iff $submitted is the plaintext that produced
     * $altCodeHash. When $altCodeHash is null/empty, runs an
     * equivalent-cost password_verify against the placeholder hash and
     * returns false — same wall-clock time as a real failed check.
     */
    public static function verify(string $submitted, ?string $altCodeHash): bool
    {
        $realHashConfigured = $altCodeHash !== null && $altCodeHash !== '';
        $candidate = $realHashConfigured ? $altCodeHash : self::placeholder();

        // Always run password_verify regardless of whether a real hash
        // is configured. The placeholder path discards its result.
        $verified = password_verify($submitted, $candidate);

        return $realHashConfigured && $verified;
    }

    /**
     * Lazily initialize the per-process placeholder hash. Uses Argon2id
     * with PHP's current defaults so the work factor tracks the real
     * verification path automatically when PHP's defaults are tightened
     * in future releases.
     */
    private static function placeholder(): string
    {
        if (self::$placeholder === null) {
            // Plaintext is unrecoverable and unused — pure timing scaffolding.
            self::$placeholder = password_hash(random_bytes(32), PASSWORD_ARGON2ID);
        }
        return self::$placeholder;
    }
}
