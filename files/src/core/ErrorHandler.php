<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Exceptions\ServiceException;
use Eiou\Utils\Logger;
use Exception;
use Throwable;

/**
 * Standardized error handling system
 * Provides consistent error handling patterns across the application
 */

class ErrorHandler {

    private static $errorHandlers = [];
    private static $initialized = false;
    private static ?string $requestId = null;

    /**
     * Initialize error handling
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$initialized = true;
    }

    /**
     * Set the request ID for error tracking
     *
     * @param string $id
     */
    public static function setRequestId(string $id): void {
        self::$requestId = $id;
    }

    /**
     * Get the current request ID
     *
     * @return string|null
     */
    public static function getRequestId(): ?string {
        return self::$requestId;
    }

    /**
     * Generate a new request ID
     *
     * @return string
     */
    public static function generateRequestId(): string {
        self::$requestId = bin2hex(random_bytes(8));
        return self::$requestId;
    }

    /**
     * Handle PHP errors
     *
     * @param int $severity
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     */
    public static function handleError($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $errorType = self::getErrorTypeName($severity);

        // Log the error
        self::logError($errorType, $message, $file, $line);

        // In production, don't expose errors
        if (self::isProduction()) {
            self::displaySafeError();
            return true;
        }

        // In development, show detailed error
        if (php_sapi_name() === 'cli') {
            echo "[$errorType] $message in $file:$line\n";
        } else {
            echo "<div style='border:1px solid red;padding:10px;margin:10px;'>";
            echo "<strong>$errorType:</strong> $message<br>";
            echo "<strong>File:</strong> $file:$line<br>";
            echo "</div>";
        }

        return true;
    }

    /**
     * Handle uncaught exceptions
     *
     * @param Throwable $exception
     */
    public static function handleException($exception) {
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();
        $trace = $exception->getTraceAsString();

        // Log the exception
        self::logException($exception);

        // Handle ServiceException with consistent formatting
        if ($exception instanceof ServiceException) {
            $response = self::createErrorResponseWithContext($exception);

            if (php_sapi_name() === 'cli') {
                echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
            } else {
                http_response_code($exception->getHttpStatus());
                header('Content-Type: application/json');
                echo json_encode($response);
            }
            return;
        }

        // In production, don't expose exception details
        if (self::isProduction()) {
            self::displaySafeError();
            return;
        }

        // In development, show detailed exception
        if (php_sapi_name() === 'cli') {
            echo "\nUncaught Exception: $message\n";
            echo "File: $file:$line\n";
            echo "Stack trace:\n$trace\n";
        } else {
            echo "<div style='border:2px solid red;padding:15px;margin:10px;background:#ffe;'>";
            echo "<h3>Uncaught Exception</h3>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($message) . "</p>";
            echo "<p><strong>File:</strong> $file:$line</p>";
            echo "<pre>" . htmlspecialchars($trace) . "</pre>";
            echo "</div>";
        }
    }

    /**
     * Handle fatal errors on shutdown
     */
    public static function handleShutdown() {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }

    /**
     * Log an error
     *
     * @param string $type
     * @param string $message
     * @param string $file
     * @param int $line
     */
    private static function logError($type, $message, $file, $line) {
        $logMessage = "[$type] $message in $file:$line";

        // Use Logger if available
        if (class_exists('Eiou\\Utils\\Logger')) {
            Logger::getInstance()->error($logMessage, [
                'type' => $type,
                'file' => $file,
                'line' => $line
            ]);
        } else {
            error_log($logMessage);
        }

        // Also log to application logger if available
        if (class_exists('Application')) {
            $app = Application::getInstance();
            if ($app->getLogger()) {
                $app->getLogger()->error($logMessage);
            }
        }
    }

    /**
     * Log an exception
     *
     * @param Throwable $exception
     */
    private static function logException($exception) {
        // Use Logger first if available
        if (class_exists('Eiou\\Utils\\Logger')) {
            Logger::getInstance()->logException($exception, [], 'CRITICAL');
        } else {
            $logMessage = sprintf(
                "Uncaught %s: %s in %s:%d\nStack trace:\n%s",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
            error_log($logMessage);
        }

        // Also log to application logger if available
        if (class_exists('Application') && class_exists('Eiou\\Utils\\Logger')) {
            $app = Application::getInstance();
            if ($app->getLogger()) {
                Logger::getInstance()->logException($exception);
            }
        }
    }

    /**
     * Display a safe error message to users
     */
    private static function displaySafeError() {
        if (php_sapi_name() === 'cli') {
            echo ErrorCodes::MESSAGE_GENERIC . "\n";
        } else {
            http_response_code(ErrorCodes::HTTP_INTERNAL_SERVER_ERROR);
            echo ErrorCodes::MESSAGE_GENERIC;
        }
    }

    /**
     * Check if running in production
     *
     * @return bool
     */
    private static function isProduction() {
        return Constants::getAppEnv() === 'production' || !Constants::getAppEnv();
    }

    /**
     * Get human-readable error type name
     *
     * @param int $type
     * @return string
     */
    private static function getErrorTypeName($type) {
        $types = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        return $types[$type] ?? 'Unknown Error';
    }

    /**
     * Register a custom error handler for specific error types
     *
     * @param string $type Error type or exception class
     * @param callable $handler
     */
    public static function registerHandler($type, $handler) {
        self::$errorHandlers[$type] = $handler;
    }

    /**
     * Handle an error with try-catch pattern
     *
     * @param callable $operation
     * @param callable $errorHandler
     * @param mixed $default
     * @return mixed
     */
    public static function tryOperation($operation, $errorHandler = null, $default = null) {
        try {
            return $operation();
        } catch (Exception $e) {
            if ($errorHandler) {
                return $errorHandler($e);
            }
            self::logException($e);
            return $default;
        }
    }

    /**
     * Create a standardized error response
     *
     * @param string $message
     * @param int $code
     * @param array $details
     * @return array
     */
    public static function createErrorResponse($message, $code = 500, $details = []) {
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code,
            ]
        ];

        // Only add details in development
        if (!self::isProduction() && !empty($details)) {
            $response['error']['details'] = $details;
        }

        return $response;
    }

    /**
     * Create an error response from a ServiceException with context
     *
     * @param ServiceException $exception
     * @param string|null $requestId
     * @return array
     */
    public static function createErrorResponseWithContext(
        ServiceException $exception,
        ?string $requestId = null
    ): array {
        $response = $exception->toArray();
        $response['request_id'] = $requestId ?? self::$requestId;
        return $response;
    }

    /**
     * Create a standardized success response
     *
     * @param mixed $data
     * @param string $message
     * @return array
     */
    public static function createSuccessResponse($data = null, $message = 'Success') {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
    }
}