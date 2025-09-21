<?php
# Copyright 2025

function createPDOConnection() {
    global $user;
    // Create PDO connection to database
    try {
        // Recreate PDO connection if it's null
        $pdo = new PDO("mysql:host={$user['dbHost']};dbname={$user['dbName']}", $user['dbUser'], $user['dbPass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        // Handle PDO connection failure
        echo json_encode(["status" => "error", "message" => "Database connection failed: " . $e->getMessage()]);
        exit();
    }
}
?>
