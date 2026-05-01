<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

use Eiou\Contracts\ClockInterface;

/**
 * Real-clock implementation backed by `new \DateTimeImmutable()`.
 *
 * Production wallets use this everywhere a `ClockInterface` is
 * declared as a dependency. Tests inject a fake implementation that
 * returns a frozen point in time (or an advancing one, depending on
 * what the test needs to exercise).
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
