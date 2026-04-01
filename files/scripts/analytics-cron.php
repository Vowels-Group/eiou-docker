<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Anonymous Analytics Cron Script
#
# Sends aggregated, anonymous usage statistics to analytics.eiou.org.
# Runs weekly via cron. Respects the analyticsEnabled user setting.
# Opt-in only (default: off). No personal data is ever sent.
#
# Exit codes:
#   0 = success (submitted or skipped)
#   1 = error

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Core\UserContext;
use Eiou\Services\AnalyticsService;

try {
    // Check if analytics are enabled
    $user = UserContext::getInstance();
    if (!$user->getAnalyticsEnabled()) {
        echo "Analytics disabled by user setting\n";
        exit(0);
    }

    // Determine event type from CLI argument
    $event = 'heartbeat';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--event=')) {
            $event = substr($arg, 8);
        }
    }

    // Random jitter (0–3600s) to spread submissions across a 1-hour window
    // and avoid thundering herd through Tor exit nodes
    $jitter = random_int(0, 3600);
    echo "Waiting {$jitter}s jitter before submission\n";
    sleep($jitter);

    // Build payload
    if ($event === 'node_setup') {
        $payload = AnalyticsService::buildSetupPayload();
    } else {
        // Need PDO for heartbeat (transaction stats)
        $dbConfigPath = '/etc/eiou/config/dbconfig.json';
        if (!file_exists($dbConfigPath)) {
            echo "Database config not found — skipping analytics\n";
            exit(0);
        }

        $dbConfig = json_decode(file_get_contents($dbConfigPath), true);
        if (!is_array($dbConfig)) {
            echo "Invalid database config — skipping analytics\n";
            exit(0);
        }

        // Connect to database (read-only queries only)
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['dbHost'] ?? '127.0.0.1',
            $dbConfig['dbPort'] ?? '3306',
            $dbConfig['dbName'] ?? 'eiou'
        );

        $pdo = new PDO($dsn, $dbConfig['dbUser'] ?? 'root', $dbConfig['dbPass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $payload = AnalyticsService::buildHeartbeatPayload($pdo, 7);
    }

    // Send
    $success = AnalyticsService::send($payload);

    if ($success) {
        echo "Analytics {$event} submitted successfully\n";
    } else {
        echo "Analytics {$event} submission failed\n";
    }

    exit(0); // Non-fatal either way

} catch (Exception $e) {
    fwrite(STDERR, "Analytics error: " . $e->getMessage() . "\n");
    exit(1);
}
