<?php
# Copyright 2025

/**
 * Create a PDO database connection
 *
 * This function establishes a secure PDO connection to the MySQL database
 * with proper error handling and security settings.
 *
 * @return PDO Database connection instance
 * @throws PDOException If connection fails
 */

require_once dirname(__DIR__) . '/core/Constants.php';
require_once dirname(__DIR__) . '/core/DatabaseContext.php';

function createPDOConnection(): PDO {
    // Try to use UserContext if available, fallback to global $user
    $databaseContext = DatabaseContext::getInstance();
    $envVariables = Constants::getInstance();


    // Get database configuration from UserContext or global $user
    if ($databaseContext && $databaseContext->isInitialized()) {
        $dbHost = $databaseContext->getDbHost();
        $dbName = $databaseContext->getDbName();
        $dbUser = $databaseContext->getDbUser();
        $dbPass = $databaseContext->getDbPass();
    } else {
        echo "FALLBACK DATABASE...";
        // Fallback to global $user for backward compatibility
        global $database;
        $dbHost = $database['dbHost'] ?? null;
        $dbName = $database['dbName'] ?? null;
        $dbUser = $database['dbUser'] ?? null;
        $dbPass = $database['dbPass'] ?? null;
    }

    // Validate required configuration
    if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
        error_log("Missing database configuration parameters");
        throw new RuntimeException("Database configuration incomplete");
    }

    try {
        // Create DSN with charset to prevent injection attacks
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

        // PDO options for security and performance
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
            PDO::ATTR_PERSISTENT => false, // Disable persistent connections for security
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        // Create PDO connection
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

        return $pdo;
    } catch (PDOException $e) {
        // Log the error securely (don't expose connection details)
        // Use SecureLogger if available, otherwise error_log
        if (class_exists('SecureLogger')) {
            SecureLogger::critical("Database connection failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        } else {
            error_log("Database connection failed: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // Throw exception to let ErrorHandler handle it
        // This allows upper layers to decide how to handle the error
        throw new \RuntimeException(
            'Database connection failed. Please check configuration.',
            500,
            $e
        );
    }
}
?>
