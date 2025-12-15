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

    /**
     * Get recent debug entries
     *
     * @param int $limit Number of entries to retrieve
     * @return array
     */
    public function getRecentDebugEntries(int $limit = 100): array {
        if (!$this->getPdo()) {
            return [];
        }

        $query = "SELECT * FROM {$this->tableName} ORDER BY timestamp DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch debug entries: ", $e);
            return [];
        }
    }

    /**
     * Clear all debug entries
     *
     * @return bool
     */
    public function clearDebugEntries(): bool {
        if (!$this->getPdo()) {
            return false;
        }

        $query = "DELETE FROM {$this->tableName}";
        try {
            $this->pdo->exec($query);
            return true;
        } catch (PDOException $e) {
            $this->logError("Failed to clear debug entries: ", $e);
            return false;
        }
    }

    /**
     * Get debug entry count
     *
     * @return int
     */
    public function getDebugEntryCount(): int {
        if (!$this->getPdo()) {
            return 0;
        }

        $query = "SELECT COUNT(*) FROM {$this->tableName}";
        try {
            return (int) $this->pdo->query($query)->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Failed to count debug entries: ", $e);
            return 0;
        }
    }
}