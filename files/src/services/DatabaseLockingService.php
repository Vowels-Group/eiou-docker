<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Utils\Logger;
use Eiou\Contracts\LockingServiceInterface;
use PDO;
use PDOException;

/**
 * Database Locking Service
 *
 * Implements distributed locking using MySQL advisory locks (GET_LOCK/RELEASE_LOCK).
 * Advisory locks are connection-scoped and work across multiple PHP processes,
 * containers, and servers pointing to the same database.
 *
 * Key characteristics of MySQL advisory locks:
 * - Connection-scoped: Released when connection closes
 * - Non-blocking option: Can check lock without waiting
 * - Named locks: String-based identifiers (max 64 chars)
 * - Cross-process: Work across different PHP processes
 *
 * @package Services
 */
class DatabaseLockingService implements LockingServiceInterface
{
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var array<string, bool> Tracks locks held by this instance
     */
    private array $heldLocks = [];

    /**
     * Lock name prefix to avoid collisions with other applications
     */
    private const LOCK_PREFIX = 'eiou_';

    /**
     * Maximum lock name length in MySQL
     */
    private const MAX_LOCK_NAME_LENGTH = 64;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection for advisory locks
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Destructor - release all held locks on shutdown
     */
    public function __destruct()
    {
        $this->releaseAll();
    }

    /**
     * Acquire a named lock using MySQL GET_LOCK
     *
     * GET_LOCK returns:
     * - 1: Lock acquired successfully
     * - 0: Lock not acquired (timeout)
     * - NULL: Error occurred
     *
     * @param string $lockName Unique lock identifier
     * @param int $timeout Maximum seconds to wait for lock (0 = no wait)
     * @return bool True if lock acquired, false if timeout or error
     */
    public function acquireLock(string $lockName, int $timeout = Constants::DB_LOCK_TIMEOUT_SECONDS): bool
    {
        $sanitizedName = $this->sanitizeLockName($lockName);

        // Check if we already hold this lock
        if ($this->holdsLock($lockName)) {
            Logger::getInstance()->debug("Lock already held", ['lock_name' => $sanitizedName]);
            return true;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT GET_LOCK(:name, :timeout) AS acquired");
            $stmt->execute([
                'name' => $sanitizedName,
                'timeout' => max(0, $timeout)
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $acquired = $result['acquired'] ?? null;

            if ($acquired === 1 || $acquired === '1') {
                $this->heldLocks[$sanitizedName] = true;
                Logger::getInstance()->info("Lock acquired", [
                    'lock_name' => $sanitizedName,
                    'timeout' => $timeout
                ]);
                return true;
            }

            if ($acquired === null) {
                Logger::getInstance()->error("Lock acquisition error", [
                    'lock_name' => $sanitizedName,
                    'error' => 'GET_LOCK returned NULL (internal error)'
                ]);
            } else {
                Logger::getInstance()->warning("Lock acquisition timeout", [
                    'lock_name' => $sanitizedName,
                    'timeout' => $timeout
                ]);
            }

            return false;

        } catch (PDOException $e) {
            Logger::getInstance()->error("Lock acquisition failed", [
                'lock_name' => $sanitizedName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Release a named lock using MySQL RELEASE_LOCK
     *
     * RELEASE_LOCK returns:
     * - 1: Lock released successfully
     * - 0: Lock not released (not held by this connection)
     * - NULL: Lock doesn't exist
     *
     * @param string $lockName Lock identifier to release
     * @return bool True if released successfully, false if not held or error
     */
    public function releaseLock(string $lockName): bool
    {
        $sanitizedName = $this->sanitizeLockName($lockName);

        // Check if we think we hold this lock
        if (!isset($this->heldLocks[$sanitizedName])) {
            Logger::getInstance()->debug("Lock not in held list", ['lock_name' => $sanitizedName]);
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT RELEASE_LOCK(:name) AS released");
            $stmt->execute(['name' => $sanitizedName]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $released = $result['released'] ?? null;

            // Remove from our tracking regardless of result
            unset($this->heldLocks[$sanitizedName]);

            if ($released === 1 || $released === '1') {
                Logger::getInstance()->info("Lock released", ['lock_name' => $sanitizedName]);
                return true;
            }

            if ($released === null) {
                Logger::getInstance()->warning("Lock release: lock did not exist", [
                    'lock_name' => $sanitizedName
                ]);
            } else {
                Logger::getInstance()->warning("Lock release: not held by this connection", [
                    'lock_name' => $sanitizedName
                ]);
            }

            return false;

        } catch (PDOException $e) {
            // Still remove from tracking on error
            unset($this->heldLocks[$sanitizedName]);
            Logger::getInstance()->error("Lock release failed", [
                'lock_name' => $sanitizedName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if a lock is currently held by any process using MySQL IS_FREE_LOCK
     *
     * IS_FREE_LOCK returns:
     * - 1: Lock is free (not held)
     * - 0: Lock is in use
     * - NULL: Error occurred
     *
     * @param string $lockName Lock identifier to check
     * @return bool True if lock is currently held, false if free
     */
    public function isLocked(string $lockName): bool
    {
        $sanitizedName = $this->sanitizeLockName($lockName);

        try {
            $stmt = $this->pdo->prepare("SELECT IS_FREE_LOCK(:name) AS is_free");
            $stmt->execute(['name' => $sanitizedName]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $isFree = $result['is_free'] ?? null;

            if ($isFree === null) {
                Logger::getInstance()->warning("Lock status check error", [
                    'lock_name' => $sanitizedName,
                    'error' => 'IS_FREE_LOCK returned NULL'
                ]);
                // On error, assume locked to be safe
                return true;
            }

            // IS_FREE_LOCK returns 1 if free, 0 if in use
            // We return true if LOCKED (in use), so invert
            return ($isFree === 0 || $isFree === '0');

        } catch (PDOException $e) {
            Logger::getInstance()->error("Lock status check failed", [
                'lock_name' => $sanitizedName,
                'error' => $e->getMessage()
            ]);
            // On error, assume locked to be safe
            return true;
        }
    }

    /**
     * Check if current process/instance holds a specific lock
     *
     * @param string $lockName Lock identifier to check
     * @return bool True if this process holds the lock
     */
    public function holdsLock(string $lockName): bool
    {
        $sanitizedName = $this->sanitizeLockName($lockName);
        return isset($this->heldLocks[$sanitizedName]) && $this->heldLocks[$sanitizedName] === true;
    }

    /**
     * Release all locks held by this instance
     *
     * @return int Number of locks released
     */
    public function releaseAll(): int
    {
        $released = 0;
        $locksToRelease = array_keys($this->heldLocks);

        foreach ($locksToRelease as $lockName) {
            try {
                $stmt = $this->pdo->prepare("SELECT RELEASE_LOCK(:name) AS released");
                $stmt->execute(['name' => $lockName]);

                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result['released'] === 1 || $result['released'] === '1') {
                    $released++;
                }
            } catch (PDOException $e) {
                Logger::getInstance()->error("Failed to release lock during cleanup", [
                    'lock_name' => $lockName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($released > 0) {
            Logger::getInstance()->info("Released all locks", ['count' => $released]);
        }

        $this->heldLocks = [];
        return $released;
    }

    /**
     * Get list of locks currently held by this instance
     *
     * @return array List of held lock names (sanitized)
     */
    public function getHeldLocks(): array
    {
        return array_keys($this->heldLocks);
    }

    /**
     * Sanitize lock name for MySQL advisory locks
     *
     * - Adds prefix to avoid collisions
     * - Removes non-alphanumeric characters (except underscore)
     * - Truncates to maximum length
     *
     * @param string $name Raw lock name
     * @return string Sanitized lock name
     */
    private function sanitizeLockName(string $name): string
    {
        // Remove any existing prefix to avoid double-prefixing
        $cleanName = $name;
        if (strpos($name, self::LOCK_PREFIX) === 0) {
            $cleanName = substr($name, strlen(self::LOCK_PREFIX));
        }

        // Keep only alphanumeric and underscore
        $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', $cleanName);

        // Add prefix
        $prefixedName = self::LOCK_PREFIX . $cleanName;

        // Truncate if necessary (max 64 chars for MySQL)
        if (strlen($prefixedName) > self::MAX_LOCK_NAME_LENGTH) {
            $hash = substr(hash('sha256', $cleanName), 0, self::MAX_LOCK_NAME_LENGTH - strlen(self::LOCK_PREFIX));
            $prefixedName = self::LOCK_PREFIX . $hash;
        }

        return $prefixedName;
    }
}
