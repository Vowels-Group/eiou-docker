<?php
# Copyright 2025

/**
 * Debug Service
 *
 * Handles all business logic for debug management.
 *
 * @package Services
 */
class DebugService {
    /**
     * @var DebugRepository Debug repository instance
     */
    private DebugRepository $debugRepository;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Constructor
     *
     * @param DebugRepository $debugRepository Debug repository
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        DebugRepository $debugRepository,
        UserContext $currentUser
    ) {
        $this->debugRepository = $debugRepository;
        $this->currentUser = $currentUser;
    }

    public function getContext(){
        $context = [];

        // Add command line arguments
        if (isset($argv)) {
            $context['argv'] = $argv;
        }

        // Add server request information
        if (isset($_SERVER['REQUEST_URI'])) {
            $context['request_uri'] = $_SERVER['REQUEST_URI'];
        }

        // Add user information if available
        if ($this->currentUser->isInitialized()) {
            $context['user'] = [
                'public_key' => $this->currentUser->getPublicKey(),
                'tor_address' => $this->currentUser->getTorAddress(),
                'hostname' => $this->currentUser->getHttpAddress()
            ];
        }

        // Add database connection information
        if (isset($pdo)) {
            $context['database'] = [
                'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
            ];
        }

        // Add PHP environment details
        $context['php'] = [
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'os' => PHP_OS
        ];

        // Add current script details
        $context['script'] = [
            'file' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            'dir' => __DIR__
        ];

        return json_encode($context, JSON_PRETTY_PRINT);
    }

    public function output($message, $level = 'ECHO') {
        // Check if debug mode is enabled
        if (Constants::get('APP_DEBUG')) {
            $data = [
                'level' => $level,
                'message' => trim($message),
                'context' => $this->getContext(),
                'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'],
                'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'],
                'trace' => json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
            ];
            $this->debugRepository->insertDebug($data);
        }
        if ($level !== 'SILENT') {
            echo $message;
        }
    }

    public function setupErrorLogging() {
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        error_reporting(E_ALL);

        // Ensure the log directory exists and is writable
        $log_dir = '/var/log/eiou';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $log_file = $log_dir . '/eiou-php-error.log';
        ini_set('error_log', $log_file);

        // Optional: Verify log file is writable
        if (!is_writable($log_file)) {
            // Fallback to system temp directory if needed
            $log_file = sys_get_temp_dir() . '/eiou-php-error.log';
            ini_set('error_log', $log_file);
        }
    }
}