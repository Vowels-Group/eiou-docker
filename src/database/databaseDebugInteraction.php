<?php
# Copyright 2025

function insertDebug($data) {
    global $pdo;
    // Add debug messages to database

    // If PDO connection is not established, use error_log as fallback
    if (!$pdo) {
        $errorMessage = "Debug: " . ($data['message'] ?? 'No message');
        $errorContext = $data['context'] ? " Context: " . json_encode($data['context']) : '';
        $errorLocation = $data['file'] ? " File: {$data['file']} Line: {$data['line']}" : '';
        
        error_log($errorMessage . $errorContext . $errorLocation);
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO debug (level, message, context, file, line, trace) VALUES (:level, :message, :context, :file, :line, :trace)");
        $stmt->bindValue(':level', $data['level'] ?? 'INFO');
        $stmt->bindValue(':message', $data['message'] ?? '');
        $stmt->bindValue(':context', json_encode($data['context'] ?? null), PDO::PARAM_STR);
        $stmt->bindValue(':file', $data['file'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':line', $data['line'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':trace', $data['trace'] ?? null, PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        // Fallback error logging if debug table insertion fails
        error_log("Debug logging failed: " . $e->getMessage());
        
        // Also log the original debug message
        $originalMessage = "Original Debug - Level: " . ($data['level'] ?? 'INFO') 
            . ", Message: " . ($data['message'] ?? '')
            . ", File: " . ($data['file'] ?? 'Unknown')
            . ", Line: " . ($data['line'] ?? 'Unknown');
        error_log($originalMessage);
    }
}


