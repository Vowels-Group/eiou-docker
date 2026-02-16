<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Core\Constants;

/**
 * Locking Service Interface
 *
 * Defines contract for distributed locking mechanisms that work across
 * multiple PHP processes and containers. Implementations may use:
 * - Database advisory locks (MySQL GET_LOCK)
 * - File-based locking (flock)
 * - Redis locks
 * - etc.
 *
 * @package Contracts
 */
interface LockingServiceInterface
{
    /**
     * Acquire a named lock
     *
     * @param string $lockName Unique lock identifier
     * @param int $timeout Maximum seconds to wait for lock (0 = no wait)
     * @return bool True if lock acquired, false if timeout or error
     */
    public function acquireLock(string $lockName, int $timeout = Constants::DB_LOCK_TIMEOUT_SECONDS): bool;

    /**
     * Release a named lock
     *
     * @param string $lockName Lock identifier to release
     * @return bool True if released successfully, false if not held or error
     */
    public function releaseLock(string $lockName): bool;

    /**
     * Check if a lock is currently held (by any process)
     *
     * @param string $lockName Lock identifier to check
     * @return bool True if lock is currently held, false if free
     */
    public function isLocked(string $lockName): bool;

    /**
     * Check if current process holds a specific lock
     *
     * @param string $lockName Lock identifier to check
     * @return bool True if this process holds the lock
     */
    public function holdsLock(string $lockName): bool;

    /**
     * Release all locks held by this instance
     *
     * Called automatically on destruction for cleanup
     *
     * @return int Number of locks released
     */
    public function releaseAll(): int;
}
