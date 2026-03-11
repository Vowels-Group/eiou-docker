<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Maintenance Mode Check
 *
 * Include this at the top of HTTP entry points (API, GUI, P2P) to return
 * 503 Service Unavailable while the node is in maintenance mode (during
 * startup source file sync, database migrations, and autoloader rebuild).
 *
 * The lockfile is created by startup.sh before source sync and removed
 * after all initialization is complete.
 *
 * CLI entry points and background processors should NOT include this file.
 */

if (file_exists('/tmp/eiou_maintenance.lock')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 30');
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => [
            'message' => 'Node is starting up or upgrading. Please try again shortly.',
            'code' => 'maintenance_mode'
        ]
    ]);
    exit;
}
