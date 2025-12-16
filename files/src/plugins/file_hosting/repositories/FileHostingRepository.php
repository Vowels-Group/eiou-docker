<?php
# Copyright 2025

/**
 * File Hosting Repository
 *
 * Data access layer for file hosting database operations
 *
 * @package Plugins\FileHosting\Repositories
 */

class FileHostingRepository {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ==================== Hosted File Operations ====================

    /**
     * Get all files for a user
     *
     * @param string $ownerPublicKey Owner's public key
     * @param bool $includeExpired Include expired files
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of HostedFile objects
     */
    public function getFilesByOwner(string $ownerPublicKey, bool $includeExpired = false, int $limit = 50, int $offset = 0): array {
        $sql = "SELECT * FROM file_hosting_files WHERE owner_public_key = :owner";
        if (!$includeExpired) {
            $sql .= " AND expires_at > NOW()";
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':owner', $ownerPublicKey);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $files = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = HostedFile::fromRow($row);
        }

        return $files;
    }

    /**
     * Get a file by ID
     *
     * @param string $fileId File identifier
     * @return HostedFile|null
     */
    public function getFileById(string $fileId): ?HostedFile {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_files WHERE file_id = :file_id");
        $stmt->execute([':file_id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? HostedFile::fromRow($row) : null;
    }

    /**
     * Get files by checksum (find duplicates)
     *
     * @param string $checksum File checksum
     * @return array
     */
    public function getFilesByChecksum(string $checksum): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_files WHERE checksum = :checksum");
        $stmt->execute([':checksum' => $checksum]);

        $files = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = HostedFile::fromRow($row);
        }

        return $files;
    }

    /**
     * Save a file record
     *
     * @param HostedFile $file File to save
     * @return bool Success
     */
    public function saveFile(HostedFile $file): bool {
        $data = $file->toArray();

        if ($this->getFileById($file->fileId)) {
            // Update existing file
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $file->fileId;

            $sql = "UPDATE file_hosting_files SET " . implode(', ', $sets) . " WHERE file_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new file
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO file_hosting_files ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Delete a file record
     *
     * @param string $fileId File identifier
     * @return bool Success
     */
    public function deleteFile(string $fileId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM file_hosting_files WHERE file_id = :file_id");
        return $stmt->execute([':file_id' => $fileId]);
    }

    /**
     * Increment download count
     *
     * @param string $fileId File identifier
     * @return bool Success
     */
    public function incrementDownloads(string $fileId): bool {
        $stmt = $this->pdo->prepare("UPDATE file_hosting_files SET download_count = download_count + 1 WHERE file_id = :file_id");
        return $stmt->execute([':file_id' => $fileId]);
    }

    /**
     * Extend file expiration
     *
     * @param string $fileId File identifier
     * @param int $additionalDays Days to add
     * @return bool Success
     */
    public function extendExpiration(string $fileId, int $additionalDays): bool {
        $stmt = $this->pdo->prepare("UPDATE file_hosting_files SET expires_at = DATE_ADD(expires_at, INTERVAL :days DAY) WHERE file_id = :file_id");
        return $stmt->execute([':file_id' => $fileId, ':days' => $additionalDays]);
    }

    /**
     * Get expired files
     *
     * @param int $limit Result limit
     * @return array
     */
    public function getExpiredFiles(int $limit = 100): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_files WHERE expires_at < NOW() LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $files = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = HostedFile::fromRow($row);
        }

        return $files;
    }

    /**
     * Get files expiring soon
     *
     * @param int $withinDays Days until expiration
     * @return array
     */
    public function getFilesExpiringSoon(int $withinDays = 7): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_files WHERE expires_at > NOW() AND expires_at < DATE_ADD(NOW(), INTERVAL :days DAY)");
        $stmt->execute([':days' => $withinDays]);

        $files = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = HostedFile::fromRow($row);
        }

        return $files;
    }

    /**
     * Get file count for owner
     *
     * @param string $ownerPublicKey Owner's public key
     * @param bool $includeExpired Include expired files
     * @return int
     */
    public function getFileCountByOwner(string $ownerPublicKey, bool $includeExpired = false): int {
        $sql = "SELECT COUNT(*) FROM file_hosting_files WHERE owner_public_key = :owner";
        if (!$includeExpired) {
            $sql .= " AND expires_at > NOW()";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':owner' => $ownerPublicKey]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get total storage used by owner
     *
     * @param string $ownerPublicKey Owner's public key
     * @param bool $includeExpired Include expired files
     * @return int Bytes
     */
    public function getTotalStorageByOwner(string $ownerPublicKey, bool $includeExpired = false): int {
        $sql = "SELECT COALESCE(SUM(size_bytes), 0) FROM file_hosting_files WHERE owner_public_key = :owner";
        if (!$includeExpired) {
            $sql .= " AND expires_at > NOW()";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':owner' => $ownerPublicKey]);
        return (int) $stmt->fetchColumn();
    }

    // ==================== Storage Plan Operations ====================

    /**
     * Get storage plan for user
     *
     * @param string $userPublicKey User's public key
     * @return StoragePlan|null
     */
    public function getStoragePlan(string $userPublicKey): ?StoragePlan {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_plans WHERE user_public_key = :user");
        $stmt->execute([':user' => $userPublicKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? StoragePlan::fromRow($row) : null;
    }

    /**
     * Save a storage plan
     *
     * @param StoragePlan $plan Plan to save
     * @return bool Success
     */
    public function saveStoragePlan(StoragePlan $plan): bool {
        $data = $plan->toArray();

        if ($this->getStoragePlan($plan->userPublicKey)) {
            // Update existing plan
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':user'] = $plan->userPublicKey;

            $sql = "UPDATE file_hosting_plans SET " . implode(', ', $sets) . " WHERE user_public_key = :user";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new plan
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO file_hosting_plans ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Update storage usage for user
     *
     * @param string $userPublicKey User's public key
     * @param int $bytesChange Change in bytes (positive or negative)
     * @param int $fileCountChange Change in file count (positive or negative)
     * @return bool Success
     */
    public function updateStorageUsage(string $userPublicKey, int $bytesChange, int $fileCountChange): bool {
        $stmt = $this->pdo->prepare("
            UPDATE file_hosting_plans
            SET used_bytes = GREATEST(0, used_bytes + :bytes),
                file_count = GREATEST(0, file_count + :count)
            WHERE user_public_key = :user
        ");
        return $stmt->execute([
            ':user' => $userPublicKey,
            ':bytes' => $bytesChange,
            ':count' => $fileCountChange
        ]);
    }

    // ==================== Payment Operations ====================

    /**
     * Get payments for a user
     *
     * @param string $payerPublicKey Payer's public key
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of FilePayment objects
     */
    public function getPaymentsByPayer(string $payerPublicKey, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_payments WHERE payer_public_key = :payer ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':payer', $payerPublicKey);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $payments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payments[] = FilePayment::fromRow($row);
        }

        return $payments;
    }

    /**
     * Get payment by ID
     *
     * @param string $paymentId Payment identifier
     * @return FilePayment|null
     */
    public function getPaymentById(string $paymentId): ?FilePayment {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_payments WHERE payment_id = :payment_id");
        $stmt->execute([':payment_id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? FilePayment::fromRow($row) : null;
    }

    /**
     * Get payments for a file
     *
     * @param string $fileId File identifier
     * @return array
     */
    public function getPaymentsByFile(string $fileId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_payments WHERE file_id = :file_id ORDER BY created_at DESC");
        $stmt->execute([':file_id' => $fileId]);

        $payments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payments[] = FilePayment::fromRow($row);
        }

        return $payments;
    }

    /**
     * Save a payment record
     *
     * @param FilePayment $payment Payment to save
     * @return bool Success
     */
    public function savePayment(FilePayment $payment): bool {
        $data = $payment->toArray();

        if ($this->getPaymentById($payment->paymentId)) {
            // Update existing payment
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $payment->paymentId;

            $sql = "UPDATE file_hosting_payments SET " . implode(', ', $sets) . " WHERE payment_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new payment
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO file_hosting_payments ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Get total spent by user
     *
     * @param string $payerPublicKey Payer's public key
     * @return float
     */
    public function getTotalSpentByUser(string $payerPublicKey): float {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM file_hosting_payments WHERE payer_public_key = :payer AND status = 'completed'");
        $stmt->execute([':payer' => $payerPublicKey]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get pending payments
     *
     * @param int $olderThanMinutes Only get payments older than this
     * @return array
     */
    public function getPendingPayments(int $olderThanMinutes = 60): array {
        $stmt = $this->pdo->prepare("SELECT * FROM file_hosting_payments WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)");
        $stmt->execute([':minutes' => $olderThanMinutes]);

        $payments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payments[] = FilePayment::fromRow($row);
        }

        return $payments;
    }

    // ==================== Statistics ====================

    /**
     * Get node storage statistics
     *
     * @return array
     */
    public function getNodeStatistics(): array {
        $totalFiles = $this->pdo->query("SELECT COUNT(*) FROM file_hosting_files WHERE expires_at > NOW()")->fetchColumn();
        $totalStorage = $this->pdo->query("SELECT COALESCE(SUM(size_bytes), 0) FROM file_hosting_files WHERE expires_at > NOW()")->fetchColumn();
        $totalDownloads = $this->pdo->query("SELECT COALESCE(SUM(download_count), 0) FROM file_hosting_files")->fetchColumn();
        $totalRevenue = $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM file_hosting_payments WHERE status = 'completed'")->fetchColumn();
        $uniqueUsers = $this->pdo->query("SELECT COUNT(DISTINCT owner_public_key) FROM file_hosting_files")->fetchColumn();

        return [
            'total_files' => (int) $totalFiles,
            'total_storage_bytes' => (int) $totalStorage,
            'total_downloads' => (int) $totalDownloads,
            'total_revenue' => (float) $totalRevenue,
            'unique_users' => (int) $uniqueUsers
        ];
    }
}
