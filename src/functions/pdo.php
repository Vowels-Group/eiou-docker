<?php
function createPDOConnection() {
    global $user;
    try {
        // Recreate PDO connection if it's null
        $pdo = new PDO("mysql:host={$user['dbHost']};dbname={$user['dbName']}", $user['dbUser'], $user['dbPass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database connection failed: " . $e->getMessage()]);
        exit();
    }
}
?>
