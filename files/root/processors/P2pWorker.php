<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * P2P Worker Entry Point
 *
 * Spawned by the P2P coordinator (P2pMessageProcessor) to process a single
 * queued P2P message. Each worker is an independent PHP process with its own
 * Application singleton, PDO connection, and curl_multi handle.
 *
 * Usage: php P2pWorker.php <p2p_hash>
 *
 * Exit codes:
 *   0 = success (P2P processed or already claimed by another worker)
 *   1 = error (missing argument, bootstrap failure, processing exception)
 */

// Validate CLI argument
if (!isset($argv[1]) || empty($argv[1])) {
    fwrite(STDERR, "Usage: php P2pWorker.php <p2p_hash>\n");
    exit(1);
}

$p2pHash = $argv[1];

try {
    require_once(dirname(__DIR__) . "/Functions.php");

    // Initialize security components
    require_once dirname(__DIR__) . '/SecurityInit.php';

    $app = \Eiou\Core\Application::getInstance();
    $p2pService = $app->services->getP2pService();

    $success = $p2pService->processSingleP2p($p2pHash, getmypid());

    // Exit 0 even if claim failed (another worker got it — not an error)
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "P2pWorker error for hash {$p2pHash}: " . $e->getMessage() . "\n");
    exit(1);
}
