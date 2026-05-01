<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

/**
 * Minimal time-source interface.
 *
 * Lets services that need "now" (cron schedules, expiry checks,
 * timestamp generation) accept a small interface they can mock in
 * tests, instead of calling `new \DateTime()` directly.
 *
 * Modeled on PSR-20 (`Psr\Clock\ClockInterface`) so a future migration
 * can swap this for the standard interface without touching call sites.
 * Not a hard PSR-20 dep today because the project doesn't pull in
 * `psr/clock` and adding a one-method package isn't worth the dep
 * graph cost.
 */
interface ClockInterface
{
    /**
     * Return the current point in time as a `DateTimeImmutable`.
     *
     * Implementations MUST return UTC unless the caller explicitly
     * asks for a different zone (this interface stays zone-agnostic;
     * callers convert as needed via `->setTimezone()`).
     */
    public function now(): \DateTimeImmutable;
}
