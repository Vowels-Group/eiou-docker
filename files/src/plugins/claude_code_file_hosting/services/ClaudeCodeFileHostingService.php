<?php
# Copyright 2025

/**
 * File Hosting Service
 *
 * Business logic for file hosting operations including uploads,
 * downloads, storage management, and eIOU payment processing.
 *
 * @package Plugins\FileHosting\Services
 */

class ClaudeCodeFileHostingService {
    /**
     * @var ClaudeCodeFileHostingRepository Data repository
     */
    private ClaudeCodeFileHostingRepository $repository;

    /**
     * @var UtilityServiceContainer Utility services
     */
    private UtilityServiceContainer $utilities;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var array Plugin configuration
     */
    private array $config;

    /**
     * @var UserContext|null Current user context
     */
    private ?UserContext $userContext;

    /**
     * Storage directories
     */
    const STORAGE_DIR = '/etc/eiou/file-hosting/';
    const TEMP_DIR = '/etc/eiou/file-hosting/temp/';

    /**
     * Constructor
     *
     * @param ClaudeCodeFileHostingRepository $repository Data repository
     * @param UtilityServiceContainer $utilities Utility services
     * @param SecureLogger|null $logger Logger
     * @param array $config Plugin configuration
     */
    public function __construct(
        ClaudeCodeFileHostingRepository $repository,
        UtilityServiceContainer $utilities,
        ?SecureLogger $logger = null,
        array $config = []
    ) {
        $this->repository = $repository;
        $this->utilities = $utilities;
        $this->logger = $logger;
        $this->config = $config;
        $this->userContext = null;
    }

    /**
     * Set user context for operations requiring authentication
     *
     * @param UserContext $userContext User context
     */
    public function setUserContext(UserContext $userContext): void {
        $this->userContext = $userContext;
    }

    // ==================== File Operations ====================

    /**
     * Upload a file
     *
     * @param array $fileData Uploaded file data from $_FILES
     * @param int $storageDays Number of days to store
     * @param bool $isPublic Whether file is publicly accessible
     * @param string|null $password Optional access password
     * @param string|null $description Optional description
     * @return array Result with file info and payment details
     * @throws Exception If upload fails
     */
    public function uploadFile(
        array $fileData,
        int $storageDays,
        bool $isPublic = false,
        ?string $password = null,
        ?string $description = null
    ): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        // Validate file
        $this->validateUpload($fileData, $storageDays);

        $ownerPublicKey = $this->userContext->getPublicKey();
        $sizeBytes = $fileData['size'];

        // Check storage quota
        $plan = $this->getOrCreateStoragePlan($ownerPublicKey);
        if (!$plan->canStore($sizeBytes)) {
            throw new Exception('Storage quota exceeded. Available: ' . $plan->getHumanAvailable());
        }

        // Calculate cost
        $pricePerMbPerDay = $this->getStoragePrice();
        $cost = FilePayment::calculateCost($sizeBytes, $storageDays, $pricePerMbPerDay);

        // Check if within free tier
        $isFree = $this->isWithinFreeTier($ownerPublicKey, $sizeBytes);

        // Create file record
        $file = new HostedFile();
        $file->fileId = HostedFile::generateFileId();
        $file->ownerPublicKey = $ownerPublicKey;
        $file->filename = basename($fileData['name']);
        $file->storedFilename = $this->generateStoredFilename($file->fileId, $fileData['name']);
        $file->mimeType = $fileData['type'] ?? 'application/octet-stream';
        $file->sizeBytes = $sizeBytes;
        $file->isPublic = $isPublic;
        $file->description = $description;
        $file->expiresAt = date('Y-m-d H:i:s', strtotime("+{$storageDays} days"));

        if ($password) {
            $file->accessPasswordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        // Process payment if not free
        $payment = null;
        if (!$isFree && $cost > 0) {
            $payment = $this->processUploadPayment($file, $storageDays, $pricePerMbPerDay);
            if (!$payment->isCompleted()) {
                throw new Exception('Payment failed: ' . ($payment->notes ?? 'Unknown error'));
            }
        }

        // Store the file
        $storagePath = $this->storeFile($fileData['tmp_name'], $file->storedFilename);
        $file->checksum = hash_file('sha256', $storagePath);

        // Encrypt if enabled
        if ($this->getSetting('encryption_enabled', true)) {
            $this->encryptFile($storagePath);
            $file->isEncrypted = true;
        }

        // Save file record
        $this->repository->saveFile($file);

        // Update storage usage
        $this->repository->updateStorageUsage($ownerPublicKey, $sizeBytes, 1);

        $this->log('info', 'File uploaded', [
            'file_id' => $file->fileId,
            'size' => $file->sizeBytes,
            'days' => $storageDays,
            'cost' => $cost
        ]);

        return [
            'file' => $file->toOwnerArray(),
            'payment' => $payment ? $payment->toApiArray() : null,
            'cost' => $cost,
            'is_free' => $isFree
        ];
    }

    /**
     * Download a file
     *
     * @param string $fileId File identifier
     * @param string|null $password Optional access password
     * @return array File info and path for streaming
     * @throws Exception If download fails
     */
    public function downloadFile(string $fileId, ?string $password = null): array {
        $file = $this->repository->getFileById($fileId);
        if (!$file) {
            throw new Exception('File not found');
        }

        if ($file->isExpired()) {
            throw new Exception('File storage has expired');
        }

        // Check access
        if (!$file->isPublic) {
            if (!$this->userContext || $this->userContext->getPublicKey() !== $file->ownerPublicKey) {
                throw new Exception('Access denied: private file');
            }
        }

        // Check password if required
        if ($file->accessPasswordHash) {
            if (!$password || !password_verify($password, $file->accessPasswordHash)) {
                throw new Exception('Invalid password');
            }
        }

        // Get file path
        $storagePath = self::STORAGE_DIR . $file->storedFilename;
        if (!file_exists($storagePath)) {
            throw new Exception('File not found on storage');
        }

        // Decrypt if needed (to temp file)
        $downloadPath = $storagePath;
        if ($file->isEncrypted) {
            $downloadPath = $this->decryptFileToTemp($storagePath, $file->fileId);
        }

        // Increment download count
        $this->repository->incrementDownloads($fileId);

        return [
            'file' => $file,
            'path' => $downloadPath,
            'filename' => $file->filename,
            'mime_type' => $file->mimeType,
            'size' => $file->sizeBytes,
            'is_temp' => $file->isEncrypted
        ];
    }

    /**
     * Delete a file
     *
     * @param string $fileId File identifier
     * @return array Result
     * @throws Exception If deletion fails
     */
    public function deleteFile(string $fileId): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $file = $this->repository->getFileById($fileId);
        if (!$file) {
            throw new Exception('File not found');
        }

        // Check ownership
        if ($file->ownerPublicKey !== $this->userContext->getPublicKey()) {
            throw new Exception('Access denied: not file owner');
        }

        // Delete physical file
        $storagePath = self::STORAGE_DIR . $file->storedFilename;
        if (file_exists($storagePath)) {
            unlink($storagePath);
        }

        // Delete database record
        $this->repository->deleteFile($fileId);

        // Update storage usage
        $this->repository->updateStorageUsage($file->ownerPublicKey, -$file->sizeBytes, -1);

        $this->log('info', 'File deleted', ['file_id' => $fileId]);

        return [
            'success' => true,
            'message' => 'File deleted successfully',
            'freed_bytes' => $file->sizeBytes
        ];
    }

    /**
     * Extend file storage duration
     *
     * @param string $fileId File identifier
     * @param int $additionalDays Days to extend
     * @return array Result with payment details
     * @throws Exception If extension fails
     */
    public function extendStorage(string $fileId, int $additionalDays): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $file = $this->repository->getFileById($fileId);
        if (!$file) {
            throw new Exception('File not found');
        }

        // Check ownership
        if ($file->ownerPublicKey !== $this->userContext->getPublicKey()) {
            throw new Exception('Access denied: not file owner');
        }

        // Validate days
        $maxDays = $this->getSetting('max_storage_days', 365);
        if ($additionalDays < 1 || $additionalDays > $maxDays) {
            throw new Exception("Additional days must be between 1 and {$maxDays}");
        }

        // Calculate cost
        $pricePerMbPerDay = $this->getStoragePrice();
        $cost = FilePayment::calculateCost($file->sizeBytes, $additionalDays, $pricePerMbPerDay);

        // Process payment
        $payment = $this->processExtensionPayment($file, $additionalDays, $pricePerMbPerDay);
        if (!$payment->isCompleted()) {
            throw new Exception('Payment failed: ' . ($payment->notes ?? 'Unknown error'));
        }

        // Extend expiration
        $this->repository->extendExpiration($fileId, $additionalDays);

        // Refresh file data
        $file = $this->repository->getFileById($fileId);

        $this->log('info', 'Storage extended', [
            'file_id' => $fileId,
            'days' => $additionalDays,
            'cost' => $cost
        ]);

        return [
            'file' => $file->toOwnerArray(),
            'payment' => $payment->toApiArray(),
            'additional_days' => $additionalDays,
            'cost' => $cost
        ];
    }

    /**
     * Get file information
     *
     * @param string $fileId File identifier
     * @return array|null File info or null if not found
     */
    public function getFileInfo(string $fileId): ?array {
        $file = $this->repository->getFileById($fileId);
        if (!$file) {
            return null;
        }

        // Return full info if owner
        if ($this->userContext && $this->userContext->getPublicKey() === $file->ownerPublicKey) {
            return $file->toOwnerArray();
        }

        // Return public info only
        return $file->toPublicArray();
    }

    /**
     * List user's files
     *
     * @param bool $includeExpired Include expired files
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array
     */
    public function listUserFiles(bool $includeExpired = false, int $page = 1, int $perPage = 20): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $ownerPublicKey = $this->userContext->getPublicKey();
        $offset = ($page - 1) * $perPage;

        $files = $this->repository->getFilesByOwner($ownerPublicKey, $includeExpired, $perPage, $offset);
        $totalCount = $this->repository->getFileCountByOwner($ownerPublicKey, $includeExpired);

        return [
            'files' => array_map(fn($f) => $f->toOwnerArray(), $files),
            'total' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($totalCount / $perPage)
        ];
    }

    // ==================== Storage Management ====================

    /**
     * Get or create storage plan for user
     *
     * @param string $userPublicKey User's public key
     * @return StoragePlan
     */
    public function getOrCreateStoragePlan(string $userPublicKey): StoragePlan {
        $plan = $this->repository->getStoragePlan($userPublicKey);

        if (!$plan) {
            $plan = StoragePlan::createFreePlan($userPublicKey);
            $plan->quotaBytes = $this->getSetting('free_storage_mb', 10) * 1024 * 1024;
            $this->repository->saveStoragePlan($plan);
        }

        // Update usage stats
        $plan->usedBytes = $this->repository->getTotalStorageByOwner($userPublicKey);
        $plan->fileCount = $this->repository->getFileCountByOwner($userPublicKey);
        $plan->totalSpent = $this->repository->getTotalSpentByUser($userPublicKey);

        return $plan;
    }

    /**
     * Get storage info for current user
     *
     * @return array
     */
    public function getStorageInfo(): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $plan = $this->getOrCreateStoragePlan($this->userContext->getPublicKey());
        return $plan->toApiArray();
    }

    // ==================== Pricing ====================

    /**
     * Get current storage price
     *
     * @return float Price per MB per day in eIOUs
     */
    public function getStoragePrice(): float {
        return (float) $this->getSetting('storage_price_per_mb_per_day', 0.001);
    }

    /**
     * Get pricing information
     *
     * @return array
     */
    public function getPricingInfo(): array {
        return [
            'price_per_mb_per_day' => $this->getStoragePrice(),
            'price_per_gb_per_day' => $this->getStoragePrice() * 1024,
            'price_per_gb_per_month' => $this->getStoragePrice() * 1024 * 30,
            'free_storage_mb' => $this->getSetting('free_storage_mb', 10),
            'max_file_size_mb' => $this->getSetting('max_file_size_mb', 100),
            'min_storage_days' => $this->getSetting('min_storage_days', 1),
            'max_storage_days' => $this->getSetting('max_storage_days', 365),
            'currency' => 'eIOU'
        ];
    }

    /**
     * Calculate storage cost
     *
     * @param int $sizeBytes File size in bytes
     * @param int $days Storage days
     * @return array Cost breakdown
     */
    public function calculateCost(int $sizeBytes, int $days): array {
        $pricePerMbPerDay = $this->getStoragePrice();
        $totalCost = FilePayment::calculateCost($sizeBytes, $days, $pricePerMbPerDay);
        $sizeMb = $sizeBytes / (1024 * 1024);

        return [
            'size_bytes' => $sizeBytes,
            'size_mb' => round($sizeMb, 2),
            'days' => $days,
            'price_per_mb_per_day' => $pricePerMbPerDay,
            'total_cost' => $totalCost,
            'currency' => 'eIOU'
        ];
    }

    // ==================== Payment Operations ====================

    /**
     * Get payment history
     *
     * @param int $limit Result limit
     * @return array
     */
    public function getPaymentHistory(int $limit = 50): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $payments = $this->repository->getPaymentsByPayer($this->userContext->getPublicKey(), $limit);
        return [
            'payments' => array_map(fn($p) => $p->toApiArray(), $payments),
            'total_spent' => $this->repository->getTotalSpentByUser($this->userContext->getPublicKey())
        ];
    }

    /**
     * Process payment for file upload
     *
     * @param HostedFile $file File being uploaded
     * @param int $days Storage days
     * @param float $pricePerMbPerDay Current price
     * @return FilePayment
     */
    private function processUploadPayment(HostedFile $file, int $days, float $pricePerMbPerDay): FilePayment {
        $nodePublicKey = $this->getNodePublicKey();

        $payment = FilePayment::createUploadPayment(
            $file->fileId,
            $file->ownerPublicKey,
            $nodePublicKey,
            $file->sizeBytes,
            $days,
            $pricePerMbPerDay
        );

        // Save pending payment
        $this->repository->savePayment($payment);

        // Attempt to process eIOU transaction
        try {
            $transactionId = $this->processEiouPayment(
                $file->ownerPublicKey,
                $nodePublicKey,
                $payment->amount,
                "File hosting: {$file->filename} ({$days} days)"
            );

            $payment->markCompleted($transactionId);
        } catch (Exception $e) {
            $payment->markFailed($e->getMessage());
            $this->log('error', 'Payment failed', [
                'payment_id' => $payment->paymentId,
                'error' => $e->getMessage()
            ]);
        }

        // Update payment record
        $this->repository->savePayment($payment);

        return $payment;
    }

    /**
     * Process payment for storage extension
     *
     * @param HostedFile $file File being extended
     * @param int $additionalDays Additional days
     * @param float $pricePerMbPerDay Current price
     * @return FilePayment
     */
    private function processExtensionPayment(HostedFile $file, int $additionalDays, float $pricePerMbPerDay): FilePayment {
        $nodePublicKey = $this->getNodePublicKey();

        $payment = FilePayment::createExtensionPayment(
            $file->fileId,
            $file->ownerPublicKey,
            $nodePublicKey,
            $file->sizeBytes,
            $additionalDays,
            $pricePerMbPerDay
        );

        // Save pending payment
        $this->repository->savePayment($payment);

        // Attempt to process eIOU transaction
        try {
            $transactionId = $this->processEiouPayment(
                $file->ownerPublicKey,
                $nodePublicKey,
                $payment->amount,
                "Storage extension: {$file->filename} (+{$additionalDays} days)"
            );

            $payment->markCompleted($transactionId);
        } catch (Exception $e) {
            $payment->markFailed($e->getMessage());
            $this->log('error', 'Extension payment failed', [
                'payment_id' => $payment->paymentId,
                'error' => $e->getMessage()
            ]);
        }

        // Update payment record
        $this->repository->savePayment($payment);

        return $payment;
    }

    /**
     * Process an eIOU payment transaction
     *
     * @param string $fromPublicKey Sender's public key
     * @param string $toPublicKey Recipient's public key
     * @param float $amount Amount in eIOUs
     * @param string $memo Transaction memo
     * @return string Transaction ID
     * @throws Exception If transaction fails
     */
    private function processEiouPayment(string $fromPublicKey, string $toPublicKey, float $amount, string $memo): string {
        // This integrates with the existing eIOU transaction system
        // The actual implementation would use the TransactionService

        // For now, generate a transaction ID and log the payment
        // In production, this would create an actual eIOU transaction
        $transactionId = 'txn_' . bin2hex(random_bytes(16));

        $this->log('info', 'eIOU payment processed', [
            'transaction_id' => $transactionId,
            'from' => substr($fromPublicKey, 0, 16) . '...',
            'to' => substr($toPublicKey, 0, 16) . '...',
            'amount' => $amount,
            'memo' => $memo
        ]);

        return $transactionId;
    }

    // ==================== Helper Methods ====================

    /**
     * Validate file upload
     *
     * @param array $fileData File data from $_FILES
     * @param int $storageDays Storage days
     * @throws Exception If validation fails
     */
    private function validateUpload(array $fileData, int $storageDays): void {
        if (empty($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
            throw new Exception('Invalid upload');
        }

        // Check file size
        $maxSize = $this->getSetting('max_file_size_mb', 100) * 1024 * 1024;
        if ($fileData['size'] > $maxSize) {
            throw new Exception('File exceeds maximum size of ' . ($maxSize / 1024 / 1024) . ' MB');
        }

        // Check storage days
        $minDays = $this->getSetting('min_storage_days', 1);
        $maxDays = $this->getSetting('max_storage_days', 365);
        if ($storageDays < $minDays || $storageDays > $maxDays) {
            throw new Exception("Storage days must be between {$minDays} and {$maxDays}");
        }

        // Check allowed extensions
        $allowedExtensions = $this->getSetting('allowed_extensions', ['*']);
        if ($allowedExtensions !== ['*']) {
            $ext = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                throw new Exception('File type not allowed');
            }
        }
    }

    /**
     * Check if storage is within free tier
     *
     * @param string $ownerPublicKey Owner's public key
     * @param int $additionalBytes Additional bytes to store
     * @return bool
     */
    private function isWithinFreeTier(string $ownerPublicKey, int $additionalBytes): bool {
        $freeStorageMb = $this->getSetting('free_storage_mb', 10);
        $freeStorageBytes = $freeStorageMb * 1024 * 1024;

        $currentUsage = $this->repository->getTotalStorageByOwner($ownerPublicKey);
        return ($currentUsage + $additionalBytes) <= $freeStorageBytes;
    }

    /**
     * Generate stored filename
     *
     * @param string $fileId File ID
     * @param string $originalName Original filename
     * @return string
     */
    private function generateStoredFilename(string $fileId, string $originalName): string {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        return $fileId . ($ext ? '.' . $ext : '');
    }

    /**
     * Store uploaded file
     *
     * @param string $tmpPath Temporary file path
     * @param string $storedFilename Target filename
     * @return string Full storage path
     * @throws Exception If storage fails
     */
    private function storeFile(string $tmpPath, string $storedFilename): string {
        $storagePath = self::STORAGE_DIR . $storedFilename;

        // Ensure storage directory exists
        if (!is_dir(self::STORAGE_DIR)) {
            mkdir(self::STORAGE_DIR, 0755, true);
        }

        if (!move_uploaded_file($tmpPath, $storagePath)) {
            throw new Exception('Failed to store file');
        }

        return $storagePath;
    }

    /**
     * Encrypt a file at rest
     *
     * @param string $filePath File path
     */
    private function encryptFile(string $filePath): void {
        // Simple encryption - in production use a proper encryption library
        // This is a placeholder that demonstrates the encryption point
        $key = $this->getEncryptionKey();
        $content = file_get_contents($filePath);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($content, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        file_put_contents($filePath, $iv . $encrypted);
    }

    /**
     * Decrypt file to temporary location
     *
     * @param string $filePath Encrypted file path
     * @param string $fileId File ID for temp naming
     * @return string Path to decrypted file
     */
    private function decryptFileToTemp(string $filePath, string $fileId): string {
        $key = $this->getEncryptionKey();
        $content = file_get_contents($filePath);
        $iv = substr($content, 0, 16);
        $encrypted = substr($content, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        // Ensure temp directory exists
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }

        $tempPath = self::TEMP_DIR . $fileId . '_' . time();
        file_put_contents($tempPath, $decrypted);

        return $tempPath;
    }

    /**
     * Get encryption key
     *
     * @return string
     */
    private function getEncryptionKey(): string {
        // In production, this should be securely stored/retrieved
        return hash('sha256', $this->getSetting('encryption_secret', 'default-key'), true);
    }

    /**
     * Get node's public key
     *
     * @return string
     */
    private function getNodePublicKey(): string {
        // Get the node's public key for receiving payments
        // This would be configured in the node settings
        return $this->config['node_public_key'] ?? 'node_default_public_key';
    }

    /**
     * Clean up expired files
     *
     * @return array Cleanup results
     */
    public function cleanupExpiredFiles(): array {
        $deleted = 0;
        $freedBytes = 0;

        $expiredFiles = $this->repository->getExpiredFiles(100);

        foreach ($expiredFiles as $file) {
            // Delete physical file
            $storagePath = self::STORAGE_DIR . $file->storedFilename;
            if (file_exists($storagePath)) {
                unlink($storagePath);
            }

            // Delete database record
            $this->repository->deleteFile($file->fileId);

            // Update storage usage
            $this->repository->updateStorageUsage($file->ownerPublicKey, -$file->sizeBytes, -1);

            $deleted++;
            $freedBytes += $file->sizeBytes;
        }

        $this->log('info', 'Expired files cleaned up', [
            'deleted' => $deleted,
            'freed_bytes' => $freedBytes
        ]);

        return [
            'deleted_count' => $deleted,
            'freed_bytes' => $freedBytes
        ];
    }

    /**
     * Get node statistics
     *
     * @return array
     */
    public function getNodeStatistics(): array {
        return $this->repository->getNodeStatistics();
    }

    /**
     * Get a setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getSetting(string $key, $default = null) {
        return $this->config['settings'][$key] ?? $default;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[FileHosting] $message", $context);
        }
    }
}
