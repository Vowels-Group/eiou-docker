<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Anonymous Analytics Cron Script
#
# Sends aggregated, anonymous usage statistics to analytics.eiou.org.
# Runs daily via cron. Respects the analyticsEnabled user setting.
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
    // and avoid thundering herd through Tor exit nodes.
    // Skip jitter for node_setup (triggered on first enable, user expects prompt send).
    if ($event !== 'node_setup') {
        $jitter = random_int(0, 3600);
        echo "Waiting {$jitter}s jitter before submission\n";
        sleep($jitter);
    }

    // Build payload
    if ($event === 'node_setup') {
        $payload = AnalyticsService::buildSetupPayload();
    } else {
        // Need PDO for heartbeat (transaction stats)
        // Use DatabaseContext to handle encrypted credentials
        $dbCtx = \Eiou\Core\DatabaseContext::getInstance();
        if (!$dbCtx->hasValidDbConfig()) {
            echo "Database config not available — skipping analytics\n";
            exit(0);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbCtx->getDbHost() ?? '127.0.0.1',
            $dbCtx->get('dbPort') ?? '3306',
            $dbCtx->getDbName() ?? 'eiou'
        );

        $pdo = new PDO($dsn, $dbCtx->getDbUser(), $dbCtx->getDbPass() ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $payload = AnalyticsService::buildHeartbeatPayload($pdo, 1);
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
