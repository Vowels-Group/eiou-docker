<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\DatabaseContext;
use Eiou\Utils\Logger;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Create a PDO database connection
 *
 * This function establishes a secure PDO connection to the MySQL database
 * with proper error handling and security settings.
 *
 * @return PDO Database connection instance
 * @throws PDOException If connection fails
 */
function createPDOConnection(): PDO {
    // Try to use UserContext if available, fallback to global $user
    $databaseContext = DatabaseContext::getInstance();

    // Initialize variables to null
    $dbHost = null;
    $dbName = null;
    $dbUser = null;
    $dbPass = null;

    // Get database configuration from UserContext or global $user
    if ($databaseContext && $databaseContext->isInitialized()) {
        $dbHost = $databaseContext->getDbHost();
        $dbName = $databaseContext->getDbName();
        $dbUser = $databaseContext->getDbUser();
        $dbPass = $databaseContext->getDbPass();
    }

    // Validate required configuration
    if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
        Logger::getInstance()->error("Missing database configuration parameters");
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
        Logger::getInstance()->logException($e, 'CRITICAL');

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
