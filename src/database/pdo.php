<?php
# Copyright 2025

use EIOU\Context\UserContext;

/**
 * Create a PDO database connection
 *
 * This function establishes a secure PDO connection to the MySQL database
 * with proper error handling and security settings.
 *
 * @param UserContext|null $userContext User context (optional, falls back to global for backward compatibility)
 * @return PDO Database connection instance
 * @throws PDOException If connection fails
 */
function createPDOConnection(?UserContext $userContext = null): PDO {
    // Backward compatibility: create UserContext from global if not provided
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    // Validate required configuration
    if (!$userContext->hasValidDbConfig()) {
        error_log("Missing database configuration parameters");
        throw new RuntimeException("Database configuration incomplete");
    }

    try {
        // Create DSN with charset to prevent injection attacks
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            $userContext->getDbHost(),
            $userContext->getDbName()
        );

        // PDO options for security and performance
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
            PDO::ATTR_PERSISTENT => false, // Disable persistent connections for security
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        // Create PDO connection
        $pdo = new PDO($dsn, $userContext->getDbUser(), $userContext->getDbPass(), $options);

        return $pdo;
    } catch (PDOException $e) {
        // Log the error securely (don't expose connection details)
        error_log("Database connection failed: " . $e->getMessage());

        // Return safe error message to user
        if (getenv('APP_ENV') === 'development' || getenv('APP_DEBUG') === 'true') {
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
