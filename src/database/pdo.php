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
        error_log("Database connection failed: " . $e->getMessage());

        // Return safe error message to user
        if ($envVariables->get('APP_ENV') === 'development' || $envVariables->get('APP_DEBUG') === 'true') {
            echo json_encode([
                "status" => "error",
                "message" => "Database connection failed: " . $e->getMessage()
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Database connection failed. Please contact system administrator."
            ]);
        }
        exit(1);
    }
}
?>
