<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Debug Repository
 *
 * Manages all database interactions for the debug table.
 *
 * @package Database\Repository
 */
class DebugRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'debug';
        $this->primaryKey = 'id';
    }

    /**
     * Insert a Debug report
     *
     * @param array $data debug data
     * @return void
     */
    public function insertDebug($data): void {
        // If PDO connection is not established, use error_log as fallback
        if (!$this->getPdo()) {
            $errorMessage = "Debug: " . ($data['message'] ?? 'No message');
            $errorContext = $data['context'] ? " Context: " . json_encode($data['context']) : '';
            $errorLocation = $data['file'] ? " File: {$data['file']} Line: {$data['line']}" : '';
            
            $this->logError($errorMessage . $errorContext . $errorLocation);
            return;
        }

        $query = "INSERT INTO {$this->tableName}
                    (level, message, context, file, line, trace) 
                    VALUES (:level, :message, :context, :file, :line, :trace)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':level', $data['level'] ?? 'INFO');
        $stmt->bindValue(':message', $data['message'] ?? '');
        $stmt->bindValue(':context', json_encode($data['context'] ?? null), PDO::PARAM_STR);
        $stmt->bindValue(':file', $data['file'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':line', $data['line'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':trace', $data['trace'] ?? null, PDO::PARAM_STR);
            
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Fallback error logging if debug table insertion fails
            $this->logError("Debug logging failed: ", $e);
            
            // Also log the original debug message
            $originalMessage = "Original Debug - Level: " . ($data['level'] ?? 'INFO') 
                . ", Message: " . ($data['message'] ?? '')
                . ", File: " . ($data['file'] ?? 'Unknown')
                . ", Line: " . ($data['line'] ?? 'Unknown');
            $this->logError($originalMessage);
        }
    }
}