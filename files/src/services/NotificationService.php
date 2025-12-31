<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/../utils/SecureLogger.php';

/**
 * Notification Service
 *
 * Handles all business logic for user notifications.
 * Provides methods to create, retrieve, and manage notifications
 * for various events like transaction resync, errors, and completions.
 *
 * @package Services
 */
class NotificationService
{
    /**
     * @var NotificationRepository Notification repository instance
     */
    private NotificationRepository $notificationRepository;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Notification types
     */
    public const TYPE_RESYNC = 'resync';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_ERROR = 'error';
    public const TYPE_INFO = 'info';
    public const TYPE_WARNING = 'warning';

    /**
     * Constructor
     *
     * @param NotificationRepository $notificationRepository Notification repository
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        NotificationRepository $notificationRepository,
        UserContext $currentUser
    ) {
        $this->notificationRepository = $notificationRepository;
        $this->currentUser = $currentUser;
    }

    /**
     * Create a notification
     *
     * @param string $type Notification type (use class constants)
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array|null $metadata Optional additional metadata
     * @return int|false The notification ID on success, false on failure
     */
    public function createNotification(
        string $type,
        string $title,
        string $message,
        ?array $metadata = null
    ): int|false {
        // Prevent duplicate notifications for the same event
        if ($metadata !== null && $this->notificationRepository->existsByMetadata($type, $metadata)) {
            SecureLogger::debug("Duplicate notification prevented", [
                'type' => $type,
                'metadata' => $metadata
            ]);
            return false;
        }

        $notificationId = $this->notificationRepository->create($type, $title, $message, $metadata);

        if ($notificationId !== false) {
            SecureLogger::info("Notification created", [
                'id' => $notificationId,
                'type' => $type,
                'title' => $title
            ]);
        }

        return $notificationId;
    }

    /**
     * Create a resync notification
     * Used when buildInvalidTransactionId is returned and resync is needed
     *
     * @param string|null $txid Transaction ID requiring resync (optional)
     * @param string|null $expectedTxid Expected transaction ID
     * @param string|null $receivedTxid Received transaction ID
     * @return int|false The notification ID on success, false on failure
     */
    public function createResyncNotification(
        ?string $txid = null,
        ?string $expectedTxid = null,
        ?string $receivedTxid = null
    ): int|false {
        $title = "Transaction Resync Required";
        $message = "Transaction data needs resyncing. Transaction will be sent after sync completes.";

        $metadata = [];
        if ($txid !== null) {
            $metadata['txid'] = $txid;
        }
        if ($expectedTxid !== null) {
            $metadata['expected_txid'] = $expectedTxid;
        }
        if ($receivedTxid !== null) {
            $metadata['received_txid'] = $receivedTxid;
        }

        return $this->createNotification(
            self::TYPE_RESYNC,
            $title,
            $message,
            !empty($metadata) ? $metadata : null
        );
    }

    /**
     * Create a transaction success notification
     *
     * @param string $txid Transaction ID
     * @param float $amount Transaction amount (in cents)
     * @param string $currency Currency code
     * @param string|null $recipient Recipient address or name
     * @return int|false The notification ID on success, false on failure
     */
    public function createTransactionSuccessNotification(
        string $txid,
        float $amount,
        string $currency,
        ?string $recipient = null
    ): int|false {
        $formattedAmount = number_format($amount / 100, 2);
        $title = "Transaction Completed";
        $message = "Your $$formattedAmount $currency transfer has completed successfully.";

        if ($recipient !== null) {
            $message .= " Recipient: $recipient";
        }

        return $this->createNotification(
            self::TYPE_SUCCESS,
            $title,
            $message,
            ['txid' => $txid, 'amount' => $amount, 'currency' => $currency]
        );
    }

    /**
     * Create a transaction error notification
     *
     * @param string $txid Transaction ID
     * @param string $errorMessage Error message
     * @return int|false The notification ID on success, false on failure
     */
    public function createTransactionErrorNotification(
        string $txid,
        string $errorMessage
    ): int|false {
        $title = "Transaction Failed";
        $message = "Transaction failed: $errorMessage";

        return $this->createNotification(
            self::TYPE_ERROR,
            $title,
            $message,
            ['txid' => $txid, 'error' => $errorMessage]
        );
    }

    /**
     * Get all notifications
     *
     * @param string|null $status Filter by status (unread, read, dismissed)
     * @param int $limit Maximum number of notifications
     * @return array Array of notifications
     */
    public function getNotifications(?string $status = null, int $limit = 50): array
    {
        return $this->notificationRepository->getAll($status, $limit);
    }

    /**
     * Get unread notifications count
     *
     * @return int Number of unread notifications
     */
    public function getUnreadCount(): int
    {
        return $this->notificationRepository->getUnreadCount();
    }

    /**
     * Mark a notification as read
     *
     * @param int $id Notification ID
     * @return bool True on success, false on failure
     */
    public function markAsRead(int $id): bool
    {
        return $this->notificationRepository->markAsRead($id);
    }

    /**
     * Mark all notifications as read
     *
     * @return bool True on success, false on failure
     */
    public function markAllAsRead(): bool
    {
        return $this->notificationRepository->markAllAsRead();
    }

    /**
     * Dismiss a notification
     *
     * @param int $id Notification ID
     * @return bool True on success, false on failure
     */
    public function dismiss(int $id): bool
    {
        return $this->notificationRepository->dismiss($id);
    }

    /**
     * Clean up old notifications
     *
     * @param int $daysOld Number of days to keep notifications
     * @return int Number of deleted notifications
     */
    public function cleanupOldNotifications(int $daysOld = 30): int
    {
        $deletedCount = $this->notificationRepository->deleteOld($daysOld);

        if ($deletedCount > 0) {
            SecureLogger::info("Old notifications cleaned up", [
                'deleted_count' => $deletedCount,
                'days_old' => $daysOld
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get notification by ID
     *
     * @param int $id Notification ID
     * @return array|null Notification data or null if not found
     */
    public function getNotificationById(int $id): ?array
    {
        return $this->notificationRepository->getById($id);
    }
}
