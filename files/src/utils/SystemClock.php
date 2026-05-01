<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

use Psr\Clock\ClockInterface;

/**
 * Real-clock implementation backed by `new \DateTimeImmutable()`.
 *
 * Implements PSR-20 `Psr\Clock\ClockInterface` so call sites take a
 * dependency on the standard interface and tests can swap in any
 * PSR-20 fake (including `Symfony\Component\Clock\MockClock` if/when
 * that lib is ever pulled in).
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
