<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Anonymous Analytics Cron Script
#
# Sends aggregated, anonymous usage statistics to analytics.eiou.org.
# Runs daily via cron. Respects the analyticsEnabled user setting.
# Opt-in only (default: off). No personal data is ever sent.
#
# Usage:
#   php analytics-cron.php                     # Daily heartbeat (default)
#   php analytics-cron.php --event=node_setup  # Initial setup event
#   php analytics-cron.php --no-jitter         # Skip random delay (for testing)
#   php analytics-cron.php --dry-run           # Build payload but don't send
#
# Exit codes:
#   0 = success (submitted or skipped)
#   1 = error

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Core\UserContext;
use Eiou\Services\AnalyticsService;

try {
    // Parse CLI flags
    $event = 'heartbeat';
    $noJitter = false;
    $dryRun = false;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--event=')) {
            $event = substr($arg, 8);
        } elseif ($arg === '--no-jitter') {
            $noJitter = true;
        } elseif ($arg === '--dry-run') {
            $dryRun = true;
        }
    }

    echo "[analytics] Starting event={$event} pid=" . getmypid() . "\n";

    // Check if analytics are enabled
    $user = UserContext::getInstance();
    if (!$user->getAnalyticsEnabled()) {
        echo "[analytics] Disabled by user setting — skipping\n";
        exit(0);
    }
    echo "[analytics] Analytics enabled\n";

    // Random jitter (0–3600s) to spread submissions across a 1-hour window
    // and avoid thundering herd through Tor exit nodes.
    // Skip jitter for node_setup (triggered on first enable, user expects prompt send).
    if ($event !== 'node_setup' && !$noJitter) {
        $jitter = random_int(0, 3600);
        echo "[analytics] Waiting {$jitter}s jitter before submission\n";
        sleep($jitter);
    }

    // Build payload
    if ($event === 'node_setup') {
        $payload = AnalyticsService::buildSetupPayload();
        echo "[analytics] Built node_setup payload\n";
    } else {
        // Need PDO for heartbeat (transaction stats)
        // Use DatabaseContext to handle encrypted credentials
        $dbCtx = \Eiou\Core\DatabaseContext::getInstance();
        if (!$dbCtx->hasValidDbConfig()) {
            echo "[analytics] ERROR: Database config not available — cannot build heartbeat\n";
            echo "[analytics] dbHost=" . ($dbCtx->getDbHost() !== null ? 'ok' : 'NULL') . "\n";
            echo "[analytics] dbName=" . ($dbCtx->getDbName() !== null ? 'ok' : 'NULL') . "\n";
            echo "[analytics] dbUser=" . ($dbCtx->getDbUser() !== null ? 'ok' : 'NULL') . "\n";
            echo "[analytics] dbPass=" . ($dbCtx->getDbPass() !== null ? 'ok' : 'NULL') . "\n";
            exit(1);
        }
        echo "[analytics] Database config valid\n";

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
        echo "[analytics] PDO connection established\n";

        // Widen the heartbeat rollup window to cover any gap since the
        // node last submitted successfully (or since it opted in, if no
        // submission ever succeeded — the runuser-on-debian 2026-04-06..
        // 04-12 scenario). See AnalyticsService::computePeriodDays().
        $status = AnalyticsService::getStatus();
        $periodDays = AnalyticsService::computePeriodDays(
            $status['last_submitted'] ?? null,
            $user->getAnalyticsOptInAt()
        );
        echo "[analytics] periodDays={$periodDays} "
            . "(last_submitted=" . ($status['last_submitted'] ?? 'never')
            . ", opt_in_at=" . ($user->getAnalyticsOptInAt() ?? 'unknown') . ")\n";

        $payload = AnalyticsService::buildHeartbeatPayload($pdo, $periodDays);
        echo "[analytics] Built heartbeat payload\n";
    }

    // Dry run — show payload without sending
    if ($dryRun) {
        echo "[analytics] DRY RUN — payload:\n";
        echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    // Send
    $success = AnalyticsService::send($payload);

    if ($success) {
        echo "[analytics] {$event} submitted successfully\n";
    } else {
        echo "[analytics] ERROR: {$event} submission failed (check app log for curl details)\n";
        exit(1);
    }

    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[analytics] FATAL: " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "[analytics] " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
