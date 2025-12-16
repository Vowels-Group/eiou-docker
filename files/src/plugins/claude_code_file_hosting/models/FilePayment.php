<?php
# Copyright 2025

/**
 * File Payment Model
 *
 * Represents a payment for file storage
 *
 * @package Plugins\FileHosting\Models
 */

class FilePayment {
    /**
     * @var int|null Database ID
     */
    public ?int $id;

    /**
     * @var string Unique payment identifier
     */
    public string $paymentId;

    /**
     * @var string Associated file ID (if applicable)
     */
    public ?string $fileId;

    /**
     * @var string Payer's public key
     */
    public string $payerPublicKey;

    /**
     * @var string Node's public key (recipient)
     */
    public string $nodePublicKey;

    /**
     * @var float Amount in eIOUs
     */
    public float $amount;

    /**
     * @var string Payment type (upload, extension, plan_upgrade)
     */
    public string $paymentType;

    /**
     * @var string Payment status (pending, completed, failed, refunded)
     */
    public string $status;

    /**
     * @var string|null Transaction ID from eIOU network
     */
    public ?string $transactionId;

    /**
     * @var int Storage days purchased
     */
    public int $storageDays;

    /**
     * @var int Storage bytes purchased
     */
    public int $storageBytes;

    /**
     * @var float Price per MB per day at time of purchase
     */
    public float $pricePerMbPerDay;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string|null Completed timestamp
     */
    public ?string $completedAt;

    /**
     * @var string|null Additional notes
     */
    public ?string $notes;

    /**
     * Payment type constants
     */
    const TYPE_UPLOAD = 'upload';
    const TYPE_EXTENSION = 'extension';
    const TYPE_PLAN_UPGRADE = 'plan_upgrade';
    const TYPE_QUOTA_INCREASE = 'quota_increase';

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = null;
        $this->paymentId = '';
        $this->fileId = null;
        $this->payerPublicKey = '';
        $this->nodePublicKey = '';
        $this->amount = 0.0;
        $this->paymentType = self::TYPE_UPLOAD;
        $this->status = self::STATUS_PENDING;
        $this->transactionId = null;
        $this->storageDays = 0;
        $this->storageBytes = 0;
        $this->pricePerMbPerDay = 0.0;
        $this->createdAt = '';
        $this->completedAt = null;
        $this->notes = null;
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return FilePayment
     */
    public static function fromRow(array $row): FilePayment {
        $payment = new self();
        $payment->id = isset($row['id']) ? (int) $row['id'] : null;
        $payment->paymentId = $row['payment_id'] ?? '';
        $payment->fileId = $row['file_id'] ?? null;
        $payment->payerPublicKey = $row['payer_public_key'] ?? '';
        $payment->nodePublicKey = $row['node_public_key'] ?? '';
        $payment->amount = (float) ($row['amount'] ?? 0);
        $payment->paymentType = $row['payment_type'] ?? self::TYPE_UPLOAD;
        $payment->status = $row['status'] ?? self::STATUS_PENDING;
        $payment->transactionId = $row['transaction_id'] ?? null;
        $payment->storageDays = (int) ($row['storage_days'] ?? 0);
        $payment->storageBytes = (int) ($row['storage_bytes'] ?? 0);
        $payment->pricePerMbPerDay = (float) ($row['price_per_mb_per_day'] ?? 0);
        $payment->createdAt = $row['created_at'] ?? '';
        $payment->completedAt = $row['completed_at'] ?? null;
        $payment->notes = $row['notes'] ?? null;

        return $payment;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'payment_id' => $this->paymentId,
            'file_id' => $this->fileId,
            'payer_public_key' => $this->payerPublicKey,
            'node_public_key' => $this->nodePublicKey,
            'amount' => $this->amount,
            'payment_type' => $this->paymentType,
            'status' => $this->status,
            'transaction_id' => $this->transactionId,
            'storage_days' => $this->storageDays,
            'storage_bytes' => $this->storageBytes,
            'price_per_mb_per_day' => $this->pricePerMbPerDay,
            'completed_at' => $this->completedAt,
            'notes' => $this->notes
        ];
    }

    /**
     * Convert to API response
     *
     * @return array
     */
    public function toApiArray(): array {
        return [
            'payment_id' => $this->paymentId,
            'file_id' => $this->fileId,
            'amount' => $this->amount,
            'payment_type' => $this->paymentType,
            'status' => $this->status,
            'storage_days' => $this->storageDays,
            'storage_mb' => round($this->storageBytes / (1024 * 1024), 2),
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt
        ];
    }

    /**
     * Check if payment is completed
     *
     * @return bool
     */
    public function isCompleted(): bool {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if payment is pending
     *
     * @return bool
     */
    public function isPending(): bool {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if payment failed
     *
     * @return bool
     */
    public function isFailed(): bool {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark payment as completed
     *
     * @param string $transactionId eIOU transaction ID
     */
    public function markCompleted(string $transactionId): void {
        $this->status = self::STATUS_COMPLETED;
        $this->transactionId = $transactionId;
        $this->completedAt = date('Y-m-d H:i:s');
    }

    /**
     * Mark payment as failed
     *
     * @param string|null $reason Failure reason
     */
    public function markFailed(?string $reason = null): void {
        $this->status = self::STATUS_FAILED;
        if ($reason) {
            $this->notes = $reason;
        }
    }

    /**
     * Generate a new payment ID
     *
     * @return string
     */
    public static function generatePaymentId(): string {
        return 'pay_' . bin2hex(random_bytes(16));
    }

    /**
     * Calculate storage cost
     *
     * @param int $sizeBytes File size in bytes
     * @param int $days Number of days
     * @param float $pricePerMbPerDay Price per MB per day
     * @return float Total cost in eIOUs
     */
    public static function calculateCost(int $sizeBytes, int $days, float $pricePerMbPerDay): float {
        $sizeMb = $sizeBytes / (1024 * 1024);
        return round($sizeMb * $days * $pricePerMbPerDay, 6);
    }

    /**
     * Create a new upload payment
     *
     * @param string $fileId File ID
     * @param string $payerPublicKey Payer's public key
     * @param string $nodePublicKey Node's public key
     * @param int $sizeBytes File size
     * @param int $days Storage days
     * @param float $pricePerMbPerDay Current price
     * @return FilePayment
     */
    public static function createUploadPayment(
        string $fileId,
        string $payerPublicKey,
        string $nodePublicKey,
        int $sizeBytes,
        int $days,
        float $pricePerMbPerDay
    ): FilePayment {
        $payment = new self();
        $payment->paymentId = self::generatePaymentId();
        $payment->fileId = $fileId;
        $payment->payerPublicKey = $payerPublicKey;
        $payment->nodePublicKey = $nodePublicKey;
        $payment->amount = self::calculateCost($sizeBytes, $days, $pricePerMbPerDay);
        $payment->paymentType = self::TYPE_UPLOAD;
        $payment->status = self::STATUS_PENDING;
        $payment->storageDays = $days;
        $payment->storageBytes = $sizeBytes;
        $payment->pricePerMbPerDay = $pricePerMbPerDay;

        return $payment;
    }

    /**
     * Create a storage extension payment
     *
     * @param string $fileId File ID
     * @param string $payerPublicKey Payer's public key
     * @param string $nodePublicKey Node's public key
     * @param int $sizeBytes File size
     * @param int $additionalDays Additional days
     * @param float $pricePerMbPerDay Current price
     * @return FilePayment
     */
    public static function createExtensionPayment(
        string $fileId,
        string $payerPublicKey,
        string $nodePublicKey,
        int $sizeBytes,
        int $additionalDays,
        float $pricePerMbPerDay
    ): FilePayment {
        $payment = new self();
        $payment->paymentId = self::generatePaymentId();
        $payment->fileId = $fileId;
        $payment->payerPublicKey = $payerPublicKey;
        $payment->nodePublicKey = $nodePublicKey;
        $payment->amount = self::calculateCost($sizeBytes, $additionalDays, $pricePerMbPerDay);
        $payment->paymentType = self::TYPE_EXTENSION;
        $payment->status = self::STATUS_PENDING;
        $payment->storageDays = $additionalDays;
        $payment->storageBytes = $sizeBytes;
        $payment->pricePerMbPerDay = $pricePerMbPerDay;

        return $payment;
    }

    /**
     * Validate the payment model
     *
     * @return array List of validation errors
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->paymentId)) {
            $errors[] = 'Payment ID is required';
        }

        if (empty($this->payerPublicKey)) {
            $errors[] = 'Payer public key is required';
        }

        if (empty($this->nodePublicKey)) {
            $errors[] = 'Node public key is required';
        }

        if ($this->amount < 0) {
            $errors[] = 'Amount cannot be negative';
        }

        if (!in_array($this->paymentType, [self::TYPE_UPLOAD, self::TYPE_EXTENSION, self::TYPE_PLAN_UPGRADE, self::TYPE_QUOTA_INCREASE])) {
            $errors[] = 'Invalid payment type';
        }

        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_REFUNDED])) {
            $errors[] = 'Invalid payment status';
        }

        return $errors;
    }
}
