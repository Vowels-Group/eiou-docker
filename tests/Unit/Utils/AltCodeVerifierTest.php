<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Tests\Utils;

use Eiou\Utils\AltCodeVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AltCodeVerifier::class)]
class AltCodeVerifierTest extends TestCase
{
    public function testVerifyReturnsTrueOnMatch(): void
    {
        $plain = 'CorrectAltCode12!';
        $hash = password_hash($plain, PASSWORD_ARGON2ID);

        $this->assertTrue(AltCodeVerifier::verify($plain, $hash));
    }

    public function testVerifyReturnsFalseOnMismatch(): void
    {
        $plain = 'CorrectAltCode12!';
        $hash = password_hash($plain, PASSWORD_ARGON2ID);

        $this->assertFalse(AltCodeVerifier::verify('wrong_candidate', $hash));
    }

    public function testVerifyReturnsFalseWhenHashIsNull(): void
    {
        $this->assertFalse(AltCodeVerifier::verify('anything', null));
    }

    public function testVerifyReturnsFalseWhenHashIsEmpty(): void
    {
        $this->assertFalse(AltCodeVerifier::verify('anything', ''));
    }

    /**
     * The contract: calling verify() with a null hash should run an
     * equivalent-cost Argon2id check internally so timing observers
     * cannot distinguish "no alt code configured" from "alt code wrong".
     *
     * We can't unit-test timing reliably (CI noise dwarfs the signal),
     * but we CAN assert the wall-clock time when no hash is configured
     * is at least an order of magnitude longer than a free trip
     * through the no-op codepath. ~50 ms (Argon2id default) is well
     * above the ~1 ms ceiling we'd see if password_verify were being
     * skipped.
     */
    public function testVerifyRunsArgon2idWhenHashIsNull(): void
    {
        // Prime the placeholder so the first call's lazy-init cost
        // doesn't skew the timed sample.
        AltCodeVerifier::verify('warmup', null);

        $start = hrtime(true);
        AltCodeVerifier::verify('any_candidate', null);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // Argon2id at PHP defaults takes tens of ms on modern hardware.
        // Use a permissive floor (10 ms) so this isn't flaky in CI;
        // we just need to confirm the work happened.
        $this->assertGreaterThan(
            10,
            $elapsedMs,
            "Expected verify(_, null) to run Argon2id work (>10ms), got {$elapsedMs}ms"
        );
    }

    /**
     * Submitting the hash itself as the candidate must not authenticate.
     * Worth pinning explicitly because the naive "attacker reads the
     * userconfig.json and submits the hash back" attack would succeed
     * against a broken verifier.
     */
    public function testHashIsNotItsOwnCredential(): void
    {
        $plain = 'CorrectAltCode12!';
        $hash = password_hash($plain, PASSWORD_ARGON2ID);

        $this->assertFalse(AltCodeVerifier::verify($hash, $hash));
    }
}
