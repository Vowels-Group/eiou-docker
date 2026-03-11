<?php
/**
 * Tor Circuit Health Tracker
 *
 * Tracks per-.onion address delivery failures and manages cooldown periods.
 * When a specific .onion address times out repeatedly, further attempts are
 * skipped until the cooldown expires — avoiding wasted retries and Tor circuit
 * overload.
 *
 * Uses file-based storage in /tmp so state is shared across all processor
 * workers and automatically cleared on container restart (when Tor restarts).
 *
 * @see https://github.com/eiou-org/eiou-docker/issues/699
 */

namespace Eiou\Utils;

use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;

class TorCircuitHealth
{
    private const HEALTH_DIR = '/tmp/tor-circuit-health';

    /**
     * Check if a .onion address is available for delivery (not in cooldown)
     *
     * @param string $onionAddress The .onion address to check
     * @return bool True if available, false if in cooldown
     */
    public static function isAvailable(string $onionAddress): bool
    {
        $data = self::readHealthFile($onionAddress);
        if ($data === null) {
            return true;
        }

        // If cooldown_until is set and hasn't expired, address is unavailable
        if (isset($data['cooldown_until']) && time() < $data['cooldown_until']) {
            return false;
        }

        return true;
    }

    /**
     * Record a delivery failure for a .onion address
     *
     * Increments the consecutive failure count. When the threshold is reached,
     * activates a cooldown period during which no delivery attempts will be made.
     *
     * @param string $onionAddress The .onion address that failed
     * @param string $error The error message from the failed attempt
     * @return bool True if cooldown was activated by this failure
     */
    public static function recordFailure(string $onionAddress, string $error): bool
    {
        $data = self::readHealthFile($onionAddress) ?? [
            'address' => $onionAddress,
            'consecutive_failures' => 0,
            'cooldown_until' => null,
        ];

        $data['consecutive_failures']++;
        $data['last_failure_at'] = time();
        $data['last_error'] = $error;

        $maxFailures = self::getMaxFailures();
        $cooldownActivated = false;

        if ($data['consecutive_failures'] >= $maxFailures) {
            $cooldownSeconds = self::getCooldownSeconds();
            $data['cooldown_until'] = time() + $cooldownSeconds;
            $cooldownActivated = true;

            Logger::getInstance()->warning("Tor address entered cooldown", [
                'address' => $onionAddress,
                'consecutive_failures' => $data['consecutive_failures'],
                'cooldown_seconds' => $cooldownSeconds,
                'cooldown_until' => date('c', $data['cooldown_until']),
            ]);
        }

        self::writeHealthFile($onionAddress, $data);
        return $cooldownActivated;
    }

    /**
     * Record a successful delivery to a .onion address
     *
     * Resets the failure counter and removes any active cooldown.
     *
     * @param string $onionAddress The .onion address that succeeded
     */
    public static function recordSuccess(string $onionAddress): void
    {
        $filePath = self::getFilePath($onionAddress);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Get the current health status for a .onion address
     *
     * @param string $onionAddress The .onion address to check
     * @return array|null Status data or null if no failures recorded
     */
    public static function getStatus(string $onionAddress): ?array
    {
        $data = self::readHealthFile($onionAddress);
        if ($data === null) {
            return null;
        }

        $data['in_cooldown'] = isset($data['cooldown_until']) && time() < $data['cooldown_until'];
        if ($data['in_cooldown']) {
            $data['cooldown_remaining_seconds'] = $data['cooldown_until'] - time();
        }

        return $data;
    }

    /**
     * Get all .onion addresses currently in cooldown
     *
     * @return array Array of status data for addresses in cooldown
     */
    public static function getAllUnhealthy(): array
    {
        $dir = self::HEALTH_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $unhealthy = [];
        $files = glob($dir . '/*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);
            if ($data && isset($data['cooldown_until']) && time() < $data['cooldown_until']) {
                $data['in_cooldown'] = true;
                $data['cooldown_remaining_seconds'] = $data['cooldown_until'] - time();
                $unhealthy[] = $data;
            }
        }

        return $unhealthy;
    }

    /**
     * Clear cooldown for a specific address (e.g., after Tor restart)
     *
     * @param string $onionAddress The .onion address to clear
     */
    public static function clearCooldown(string $onionAddress): void
    {
        self::recordSuccess($onionAddress);
    }

    /**
     * Clear all cooldowns (e.g., after full Tor restart)
     */
    public static function clearAll(): void
    {
        $dir = self::HEALTH_DIR;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Get file path for a .onion address health record
     */
    private static function getFilePath(string $onionAddress): string
    {
        // Use SHA-256 of the address as filename to avoid filesystem issues
        // with long .onion addresses
        $hash = hash('sha256', $onionAddress);
        return self::HEALTH_DIR . '/' . $hash . '.json';
    }

    /**
     * Read health data from file
     */
    private static function readHealthFile(string $onionAddress): ?array
    {
        $filePath = self::getFilePath($onionAddress);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write health data to file
     */
    private static function writeHealthFile(string $onionAddress, array $data): void
    {
        $dir = self::HEALTH_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filePath = self::getFilePath($onionAddress);
        @file_put_contents($filePath, json_encode($data), LOCK_EX);
    }

    /**
     * Get max failures threshold from user config or constants
     */
    private static function getMaxFailures(): int
    {
        try {
            return UserContext::getInstance()->getTorCircuitMaxFailures();
        } catch (\Exception $e) {
            Logger::getInstance()->log('Failed to read TorCircuitMaxFailures config, using default: ' . $e->getMessage(), 'DEBUG');
            return Constants::TOR_CIRCUIT_MAX_FAILURES;
        }
    }

    /**
     * Get cooldown duration from user config or constants
     */
    private static function getCooldownSeconds(): int
    {
        try {
            return UserContext::getInstance()->getTorCircuitCooldownSeconds();
        } catch (\Exception $e) {
            Logger::getInstance()->log('Failed to read TorCircuitCooldownSeconds config, using default: ' . $e->getMessage(), 'DEBUG');
            return Constants::TOR_CIRCUIT_COOLDOWN_SECONDS;
        }
    }
}
