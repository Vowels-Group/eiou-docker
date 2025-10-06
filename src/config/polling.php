<?php
/**
 * Polling configuration for background message processors
 *
 * Adaptive polling reduces CPU usage and improves performance by adjusting
 * the polling interval based on system load and message queue size.
 */

return [
    /**
     * Transaction message processor polling configuration
     */
    'transaction_processor' => [
        'min_interval_ms' => env('TRANSACTION_MIN_INTERVAL_MS', 100),    // 100ms minimum
        'max_interval_ms' => env('TRANSACTION_MAX_INTERVAL_MS', 5000),   // 5 seconds maximum
        'idle_interval_ms' => env('TRANSACTION_IDLE_INTERVAL_MS', 2000), // 2 seconds when idle
        'adaptive' => env('TRANSACTION_ADAPTIVE_POLLING', true),         // Enable adaptive polling
    ],

    /**
     * P2P message processor polling configuration
     */
    'p2p_processor' => [
        'min_interval_ms' => env('P2P_MIN_INTERVAL_MS', 100),    // 100ms minimum
        'max_interval_ms' => env('P2P_MAX_INTERVAL_MS', 5000),   // 5 seconds maximum
        'idle_interval_ms' => env('P2P_IDLE_INTERVAL_MS', 2000), // 2 seconds when idle
        'adaptive' => env('P2P_ADAPTIVE_POLLING', true),         // Enable adaptive polling
    ],

    /**
     * Cleanup message processor polling configuration
     */
    'cleanup_processor' => [
        'min_interval_ms' => env('CLEANUP_MIN_INTERVAL_MS', 1000),    // 1 second minimum
        'max_interval_ms' => env('CLEANUP_MAX_INTERVAL_MS', 30000),   // 30 seconds maximum
        'idle_interval_ms' => env('CLEANUP_IDLE_INTERVAL_MS', 10000), // 10 seconds when idle
        'adaptive' => env('CLEANUP_ADAPTIVE_POLLING', true),          // Enable adaptive polling
    ],

    /**
     * Database lock wait configuration
     */
    'db_lock_wait' => [
        'min_interval_ms' => env('DB_LOCK_MIN_INTERVAL_MS', 100),   // 100ms minimum
        'max_interval_ms' => env('DB_LOCK_MAX_INTERVAL_MS', 1000),  // 1 second maximum
        'adaptive' => env('DB_LOCK_ADAPTIVE_WAIT', true),           // Enable adaptive waiting
    ],

    /**
     * Load thresholds for adaptive polling
     */
    'load_thresholds' => [
        'high_load_cpu_percent' => env('HIGH_LOAD_CPU', 80),    // Consider high load above 80% CPU
        'high_load_queue_size' => env('HIGH_LOAD_QUEUE', 100),  // Consider high load with 100+ queued items
        'low_load_queue_size' => env('LOW_LOAD_QUEUE', 10),     // Consider low load with <10 queued items
    ],
];

/**
 * Helper function to get configuration value with fallback
 */
function getPollingConfig($key, $default = null) {
    static $config = null;

    if ($config === null) {
        $configFile = __DIR__ . '/src/config/polling.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = [];
        }
    }

    $keys = explode('.', $key);
    $value = $config;

    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return $default;
        }
        $value = $value[$k];
    }

    return $value;
}

/**
 * Get environment variable with fallback
 */
function env($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}