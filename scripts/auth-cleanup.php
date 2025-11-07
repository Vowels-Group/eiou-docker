#!/usr/bin/env php
<?php
/**
 * Authentication Cleanup Script
 *
 * Copyright 2025
 *
 * Periodically cleans up old login attempts and expired lockouts
 * Recommended: Run every 6 hours via cron
 *
 * Usage:
 *   php scripts/auth-cleanup.php [--verbose] [--age=SECONDS]
 */

require_once __DIR__ . '/../src/core/Constants.php';
require_once __DIR__ . '/../src/core/DatabaseContext.php';
require_once __DIR__ . '/../src/security/SessionManager.php';
require_once __DIR__ . '/../src/security/AuthenticationService.php';
require_once __DIR__ . '/../src/utils/SecureLogger.php';

class AuthenticationCleanup
{
    private PDO $pdo;
    private AuthenticationService $authService;
    private bool $verbose = false;
    private int $age = 86400; // 24 hours default

    public function __construct(array $options = [])
    {
        $this->verbose = $options['verbose'] ?? false;
        $this->age = $options['age'] ?? 86400;

        // Initialize components
        $this->pdo = DatabaseContext::getInstance()->getPDO();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionManager = new SessionManager();
        $this->authService = new AuthenticationService($this->pdo, $sessionManager);
    }

    /**
     * Run cleanup
     */
    public function cleanup(): array
    {
        $results = [
            'login_attempts_deleted' => 0,
            'lockouts_expired' => 0,
            'errors' => []
        ];

        if ($this->verbose) {
            echo "Authentication Cleanup\n";
            echo str_repeat('=', 50) . "\n";
            echo "Age threshold: " . $this->formatDuration($this->age) . "\n";
            echo "Started: " . date('Y-m-d H:i:s') . "\n\n";
        }

        // Clean old login attempts
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM login_attempts
                WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$this->age]);
            $beforeCount = $stmt->fetch()['count'];

            if ($this->verbose) {
                echo "Cleaning login attempts older than " . $this->formatDuration($this->age) . "...\n";
                echo "  Records to delete: $beforeCount\n";
            }

            $stmt = $this->pdo->prepare("
                DELETE FROM login_attempts
                WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$this->age]);

            $results['login_attempts_deleted'] = $beforeCount;

            if ($this->verbose) {
                echo "  ✓ Deleted $beforeCount old login attempts\n\n";
            }
        } catch (PDOException $e) {
            $error = "Failed to clean login attempts: " . $e->getMessage();
            $results['errors'][] = $error;

            if ($this->verbose) {
                echo "  ✗ ERROR: $error\n\n";
            }

            SecureLogger::error('Cleanup failed', ['error' => $error]);
        }

        // Clean expired lockouts
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count FROM account_lockouts
                WHERE locked_until < NOW()
            ");
            $stmt->execute();
            $beforeCount = $stmt->fetch()['count'];

            if ($this->verbose) {
                echo "Cleaning expired account lockouts...\n";
                echo "  Lockouts to remove: $beforeCount\n";
            }

            $stmt = $this->pdo->prepare("
                DELETE FROM account_lockouts
                WHERE locked_until < NOW()
            ");
            $stmt->execute();

            $results['lockouts_expired'] = $beforeCount;

            if ($this->verbose) {
                echo "  ✓ Removed $beforeCount expired lockouts\n\n";
            }
        } catch (PDOException $e) {
            $error = "Failed to clean lockouts: " . $e->getMessage();
            $results['errors'][] = $error;

            if ($this->verbose) {
                echo "  ✗ ERROR: $error\n\n";
            }

            SecureLogger::error('Cleanup failed', ['error' => $error]);
        }

        // Get statistics
        $stats = $this->getStatistics();

        if ($this->verbose) {
            echo "Current Statistics:\n";
            echo "  Active lockouts: " . $stats['active_lockouts'] . "\n";
            echo "  Recent attempts (24h): " . $stats['recent_attempts'] . "\n";
            echo "  Successful logins (24h): " . $stats['successful_logins'] . "\n";
            echo "  Failed logins (24h): " . $stats['failed_logins'] . "\n";
            echo "  Total auth codes: " . $stats['total_auth_codes'] . "\n\n";

            echo "Cleanup complete!\n";
            echo "Completed: " . date('Y-m-d H:i:s') . "\n";
        }

        SecureLogger::info('Authentication cleanup completed', $results);

        return $results;
    }

    /**
     * Get authentication statistics
     */
    private function getStatistics(): array
    {
        $stats = [];

        try {
            // Active lockouts
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM account_lockouts
                WHERE locked_until > NOW()
            ");
            $stats['active_lockouts'] = $stmt->fetch()['count'];

            // Recent attempts (24h)
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM login_attempts
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats['recent_attempts'] = $stmt->fetch()['count'];

            // Successful logins (24h)
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM login_attempts
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND success = TRUE
            ");
            $stats['successful_logins'] = $stmt->fetch()['count'];

            // Failed logins (24h)
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM login_attempts
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND success = FALSE
            ");
            $stats['failed_logins'] = $stmt->fetch()['count'];

            // Total auth codes
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM auth_codes
                WHERE status = 'active'
            ");
            $stats['total_auth_codes'] = $stmt->fetch()['count'];
        } catch (PDOException $e) {
            SecureLogger::error('Failed to get statistics', ['error' => $e->getMessage()]);
            $stats = [
                'active_lockouts' => 0,
                'recent_attempts' => 0,
                'successful_logins' => 0,
                'failed_logins' => 0,
                'total_auth_codes' => 0
            ];
        }

        return $stats;
    }

    /**
     * Format duration in human-readable form
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . " seconds";
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . " minutes";
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . " hours";
        } else {
            return round($seconds / 86400) . " days";
        }
    }

    /**
     * Print detailed report
     */
    public function printReport(): void
    {
        echo "\nAuthentication System Report\n";
        echo str_repeat('=', 50) . "\n\n";

        // Get statistics
        $stats = $this->getStatistics();

        echo "System Status:\n";
        echo "  Active lockouts: " . $stats['active_lockouts'] . "\n";
        echo "  Total auth codes: " . $stats['total_auth_codes'] . "\n\n";

        echo "Activity (Last 24 Hours):\n";
        echo "  Total attempts: " . $stats['recent_attempts'] . "\n";
        echo "  Successful: " . $stats['successful_logins']
            . " (" . ($stats['recent_attempts'] > 0
                ? round(($stats['successful_logins'] / $stats['recent_attempts']) * 100, 1)
                : 0) . "%)\n";
        echo "  Failed: " . $stats['failed_logins']
            . " (" . ($stats['recent_attempts'] > 0
                ? round(($stats['failed_logins'] / $stats['recent_attempts']) * 100, 1)
                : 0) . "%)\n\n";

        // Get recent failed attempts by IP
        try {
            $stmt = $this->pdo->query("
                SELECT ip_address, COUNT(*) as count
                FROM login_attempts
                WHERE success = FALSE
                AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY ip_address
                ORDER BY count DESC
                LIMIT 10
            ");

            $failedByIp = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($failedByIp) > 0) {
                echo "Top Failed Attempts by IP (Last 24 Hours):\n";
                foreach ($failedByIp as $row) {
                    echo "  " . $row['ip_address'] . ": " . $row['count'] . " attempts\n";
                }
                echo "\n";
            }
        } catch (PDOException $e) {
            echo "Failed to get IP statistics\n\n";
        }

        // Get active lockouts
        try {
            $stmt = $this->pdo->query("
                SELECT identifier, locked_until, reason, attempt_count
                FROM account_lockouts
                WHERE locked_until > NOW()
                ORDER BY locked_until DESC
            ");

            $lockouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($lockouts) > 0) {
                echo "Active Lockouts:\n";
                foreach ($lockouts as $lockout) {
                    $remaining = strtotime($lockout['locked_until']) - time();
                    echo "  " . $lockout['identifier']
                        . " - Locked for " . $this->formatDuration($remaining)
                        . " (" . $lockout['reason'] . ", "
                        . $lockout['attempt_count'] . " attempts)\n";
                }
                echo "\n";
            }
        } catch (PDOException $e) {
            echo "Failed to get lockout information\n\n";
        }

        echo str_repeat('=', 50) . "\n";
    }
}

// Parse command line arguments
$options = [
    'verbose' => false,
    'age' => 86400
];

$command = 'cleanup';

foreach ($argv as $arg) {
    if ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
    } elseif (strpos($arg, '--age=') === 0) {
        $options['age'] = (int)substr($arg, 6);
    } elseif ($arg === 'report') {
        $command = 'report';
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $cleanup = new AuthenticationCleanup($options);

        switch ($command) {
            case 'report':
                $cleanup->printReport();
                break;

            case 'cleanup':
            default:
                $results = $cleanup->cleanup();

                // Exit with error if there were errors
                exit(count($results['errors']) > 0 ? 1 : 0);
        }
    } catch (Exception $e) {
        echo "\n✗ ERROR: " . $e->getMessage() . "\n";
        if ($options['verbose']) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        exit(1);
    }
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
