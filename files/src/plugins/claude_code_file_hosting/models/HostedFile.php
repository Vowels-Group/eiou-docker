<?php
# Copyright 2025

/**
 * Hosted File Model
 *
 * Represents a file stored on the node with associated metadata
 *
 * @package Plugins\FileHosting\Models
 */

class HostedFile {
    /**
     * @var int|null Database ID
     */
    public ?int $id;

    /**
     * @var string Unique file identifier (UUID)
     */
    public string $fileId;

    /**
     * @var string Owner's public key
     */
    public string $ownerPublicKey;

    /**
     * @var string Original filename
     */
    public string $filename;

    /**
     * @var string Stored filename (hashed)
     */
    public string $storedFilename;

    /**
     * @var string MIME type
     */
    public string $mimeType;

    /**
     * @var int File size in bytes
     */
    public int $sizeBytes;

    /**
     * @var string SHA-256 checksum
     */
    public string $checksum;

    /**
     * @var bool Whether file is encrypted at rest
     */
    public bool $isEncrypted;

    /**
     * @var bool Whether file is publicly downloadable
     */
    public bool $isPublic;

    /**
     * @var string|null Optional access password hash
     */
    public ?string $accessPasswordHash;

    /**
     * @var int Download count
     */
    public int $downloadCount;

    /**
     * @var string Storage expiration date
     */
    public string $expiresAt;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * @var string|null Description/notes
     */
    public ?string $description;

    /**
     * @var array|null Custom metadata
     */
    public ?array $metadata;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = null;
        $this->fileId = '';
        $this->ownerPublicKey = '';
        $this->filename = '';
        $this->storedFilename = '';
        $this->mimeType = 'application/octet-stream';
        $this->sizeBytes = 0;
        $this->checksum = '';
        $this->isEncrypted = false;
        $this->isPublic = false;
        $this->accessPasswordHash = null;
        $this->downloadCount = 0;
        $this->expiresAt = '';
        $this->createdAt = '';
        $this->updatedAt = '';
        $this->description = null;
        $this->metadata = null;
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return HostedFile
     */
    public static function fromRow(array $row): HostedFile {
        $file = new self();
        $file->id = isset($row['id']) ? (int) $row['id'] : null;
        $file->fileId = $row['file_id'] ?? '';
        $file->ownerPublicKey = $row['owner_public_key'] ?? '';
        $file->filename = $row['filename'] ?? '';
        $file->storedFilename = $row['stored_filename'] ?? '';
        $file->mimeType = $row['mime_type'] ?? 'application/octet-stream';
        $file->sizeBytes = (int) ($row['size_bytes'] ?? 0);
        $file->checksum = $row['checksum'] ?? '';
        $file->isEncrypted = (bool) ($row['is_encrypted'] ?? false);
        $file->isPublic = (bool) ($row['is_public'] ?? false);
        $file->accessPasswordHash = $row['access_password_hash'] ?? null;
        $file->downloadCount = (int) ($row['download_count'] ?? 0);
        $file->expiresAt = $row['expires_at'] ?? '';
        $file->createdAt = $row['created_at'] ?? '';
        $file->updatedAt = $row['updated_at'] ?? '';
        $file->description = $row['description'] ?? null;
        $file->metadata = isset($row['metadata']) ? json_decode($row['metadata'], true) : null;

        return $file;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'file_id' => $this->fileId,
            'owner_public_key' => $this->ownerPublicKey,
            'filename' => $this->filename,
            'stored_filename' => $this->storedFilename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'checksum' => $this->checksum,
            'is_encrypted' => $this->isEncrypted ? 1 : 0,
            'is_public' => $this->isPublic ? 1 : 0,
            'access_password_hash' => $this->accessPasswordHash,
            'download_count' => $this->downloadCount,
            'expires_at' => $this->expiresAt,
            'description' => $this->description,
            'metadata' => $this->metadata ? json_encode($this->metadata) : null
        ];
    }

    /**
     * Convert to public API response
     *
     * @return array
     */
    public function toPublicArray(): array {
        return [
            'file_id' => $this->fileId,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'size_human' => $this->getHumanFileSize(),
            'is_public' => $this->isPublic,
            'has_password' => $this->accessPasswordHash !== null,
            'download_count' => $this->downloadCount,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'description' => $this->description,
            'is_expired' => $this->isExpired()
        ];
    }

    /**
     * Convert to owner's API response (includes more details)
     *
     * @return array
     */
    public function toOwnerArray(): array {
        $data = $this->toPublicArray();
        $data['checksum'] = $this->checksum;
        $data['is_encrypted'] = $this->isEncrypted;
        $data['metadata'] = $this->metadata;
        $data['days_remaining'] = $this->getDaysRemaining();
        return $data;
    }

    /**
     * Check if file storage has expired
     *
     * @return bool
     */
    public function isExpired(): bool {
        if (empty($this->expiresAt)) {
            return false;
        }
        return strtotime($this->expiresAt) < time();
    }

    /**
     * Get days remaining until expiration
     *
     * @return int
     */
    public function getDaysRemaining(): int {
        if (empty($this->expiresAt)) {
            return 0;
        }
        $diff = strtotime($this->expiresAt) - time();
        return max(0, (int) ceil($diff / 86400));
    }

    /**
     * Get human-readable file size
     *
     * @return string
     */
    public function getHumanFileSize(): string {
        $bytes = $this->sizeBytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get size in megabytes
     *
     * @return float
     */
    public function getSizeMB(): float {
        return $this->sizeBytes / (1024 * 1024);
    }

    /**
     * Generate a new file ID
     *
     * @return string UUID v4
     */
    public static function generateFileId(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Validate the file model
     *
     * @return array List of validation errors
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->fileId)) {
            $errors[] = 'File ID is required';
        }

        if (empty($this->ownerPublicKey)) {
            $errors[] = 'Owner public key is required';
        }

        if (empty($this->filename)) {
            $errors[] = 'Filename is required';
        }

        if (strlen($this->filename) > 255) {
            $errors[] = 'Filename must be 255 characters or less';
        }

        if ($this->sizeBytes <= 0) {
            $errors[] = 'File size must be greater than 0';
        }

        if (empty($this->expiresAt)) {
            $errors[] = 'Expiration date is required';
        }

        return $errors;
    }
}
