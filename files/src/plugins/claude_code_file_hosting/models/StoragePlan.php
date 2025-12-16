<?php
# Copyright 2025

/**
 * Storage Plan Model
 *
 * Represents a storage allocation for a user
 *
 * @package Plugins\FileHosting\Models
 */

class StoragePlan {
    /**
     * @var int|null Database ID
     */
    public ?int $id;

    /**
     * @var string User's public key
     */
    public string $userPublicKey;

    /**
     * @var string Plan type (free, basic, premium, custom)
     */
    public string $planType;

    /**
     * @var int Storage quota in bytes
     */
    public int $quotaBytes;

    /**
     * @var int Used storage in bytes
     */
    public int $usedBytes;

    /**
     * @var int Number of files stored
     */
    public int $fileCount;

    /**
     * @var float Total eIOUs spent on storage
     */
    public float $totalSpent;

    /**
     * @var string|null Plan expiration date
     */
    public ?string $expiresAt;

    /**
     * @var bool Whether auto-renewal is enabled
     */
    public bool $autoRenew;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * Plan type constants
     */
    const PLAN_FREE = 'free';
    const PLAN_BASIC = 'basic';
    const PLAN_PREMIUM = 'premium';
    const PLAN_CUSTOM = 'custom';

    /**
     * Default quotas per plan type (in bytes)
     */
    const DEFAULT_QUOTAS = [
        self::PLAN_FREE => 10 * 1024 * 1024,        // 10 MB
        self::PLAN_BASIC => 100 * 1024 * 1024,      // 100 MB
        self::PLAN_PREMIUM => 1024 * 1024 * 1024,   // 1 GB
        self::PLAN_CUSTOM => 0                       // No limit
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = null;
        $this->userPublicKey = '';
        $this->planType = self::PLAN_FREE;
        $this->quotaBytes = self::DEFAULT_QUOTAS[self::PLAN_FREE];
        $this->usedBytes = 0;
        $this->fileCount = 0;
        $this->totalSpent = 0.0;
        $this->expiresAt = null;
        $this->autoRenew = false;
        $this->createdAt = '';
        $this->updatedAt = '';
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return StoragePlan
     */
    public static function fromRow(array $row): StoragePlan {
        $plan = new self();
        $plan->id = isset($row['id']) ? (int) $row['id'] : null;
        $plan->userPublicKey = $row['user_public_key'] ?? '';
        $plan->planType = $row['plan_type'] ?? self::PLAN_FREE;
        $plan->quotaBytes = (int) ($row['quota_bytes'] ?? self::DEFAULT_QUOTAS[self::PLAN_FREE]);
        $plan->usedBytes = (int) ($row['used_bytes'] ?? 0);
        $plan->fileCount = (int) ($row['file_count'] ?? 0);
        $plan->totalSpent = (float) ($row['total_spent'] ?? 0);
        $plan->expiresAt = $row['expires_at'] ?? null;
        $plan->autoRenew = (bool) ($row['auto_renew'] ?? false);
        $plan->createdAt = $row['created_at'] ?? '';
        $plan->updatedAt = $row['updated_at'] ?? '';

        return $plan;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'user_public_key' => $this->userPublicKey,
            'plan_type' => $this->planType,
            'quota_bytes' => $this->quotaBytes,
            'used_bytes' => $this->usedBytes,
            'file_count' => $this->fileCount,
            'total_spent' => $this->totalSpent,
            'expires_at' => $this->expiresAt,
            'auto_renew' => $this->autoRenew ? 1 : 0
        ];
    }

    /**
     * Convert to API response
     *
     * @return array
     */
    public function toApiArray(): array {
        return [
            'plan_type' => $this->planType,
            'quota_bytes' => $this->quotaBytes,
            'quota_human' => $this->getHumanQuota(),
            'used_bytes' => $this->usedBytes,
            'used_human' => $this->getHumanUsed(),
            'available_bytes' => $this->getAvailableBytes(),
            'available_human' => $this->getHumanAvailable(),
            'usage_percentage' => $this->getUsagePercentage(),
            'file_count' => $this->fileCount,
            'total_spent' => $this->totalSpent,
            'expires_at' => $this->expiresAt,
            'auto_renew' => $this->autoRenew,
            'is_expired' => $this->isExpired()
        ];
    }

    /**
     * Get available storage in bytes
     *
     * @return int
     */
    public function getAvailableBytes(): int {
        return max(0, $this->quotaBytes - $this->usedBytes);
    }

    /**
     * Get usage percentage
     *
     * @return float
     */
    public function getUsagePercentage(): float {
        if ($this->quotaBytes <= 0) {
            return 0.0;
        }
        return min(100, ($this->usedBytes / $this->quotaBytes) * 100);
    }

    /**
     * Check if storage is full
     *
     * @return bool
     */
    public function isFull(): bool {
        return $this->usedBytes >= $this->quotaBytes;
    }

    /**
     * Check if can store a file of given size
     *
     * @param int $sizeBytes File size in bytes
     * @return bool
     */
    public function canStore(int $sizeBytes): bool {
        return ($this->usedBytes + $sizeBytes) <= $this->quotaBytes;
    }

    /**
     * Check if plan is expired
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
     * Get human-readable quota
     *
     * @return string
     */
    public function getHumanQuota(): string {
        return $this->bytesToHuman($this->quotaBytes);
    }

    /**
     * Get human-readable used storage
     *
     * @return string
     */
    public function getHumanUsed(): string {
        return $this->bytesToHuman($this->usedBytes);
    }

    /**
     * Get human-readable available storage
     *
     * @return string
     */
    public function getHumanAvailable(): string {
        return $this->bytesToHuman($this->getAvailableBytes());
    }

    /**
     * Convert bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    private function bytesToHuman(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Create a new free plan for a user
     *
     * @param string $userPublicKey User's public key
     * @return StoragePlan
     */
    public static function createFreePlan(string $userPublicKey): StoragePlan {
        $plan = new self();
        $plan->userPublicKey = $userPublicKey;
        $plan->planType = self::PLAN_FREE;
        $plan->quotaBytes = self::DEFAULT_QUOTAS[self::PLAN_FREE];
        return $plan;
    }

    /**
     * Validate the plan model
     *
     * @return array List of validation errors
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->userPublicKey)) {
            $errors[] = 'User public key is required';
        }

        if (!in_array($this->planType, [self::PLAN_FREE, self::PLAN_BASIC, self::PLAN_PREMIUM, self::PLAN_CUSTOM])) {
            $errors[] = 'Invalid plan type';
        }

        if ($this->quotaBytes < 0) {
            $errors[] = 'Quota cannot be negative';
        }

        if ($this->usedBytes < 0) {
            $errors[] = 'Used bytes cannot be negative';
        }

        return $errors;
    }
}
