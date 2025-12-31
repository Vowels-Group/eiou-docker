<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Notification Repository
 *
 * Manages database operations for user notifications.
 * Notifications are used to inform users about important events like
 * transaction resync requirements, completion statuses, and errors.
 *
 * @package Database
 */
class NotificationRepository extends AbstractRepository
{
    /**
     * Create a new notification
     *
     * @param string $type Notification type (resync, success, error, info, warning)
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array|null $metadata Optional additional metadata (stored as JSON)
     * @return int|false The notification ID on success, false on failure
     */
    public function create(string $type, string $title, string $message, ?array $metadata = null): int|false
    {
        try {
            $sql = "INSERT INTO notifications (type, title, message, metadata, status, created_at)
                    VALUES (:type, :title, :message, :metadata, 'unread', NOW(6))";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':title' => $title,
                ':message' => $message,
                ':metadata' => $metadata ? json_encode($metadata) : null
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::create',
                'type' => $type
            ]);
            return false;
        }
    }

    /**
     * Get all notifications for the current user
     *
     * @param string|null $status Filter by status (unread, read, dismissed)
     * @param int $limit Maximum number of notifications to retrieve
     * @return array Array of notifications
     */
    public function getAll(?string $status = null, int $limit = 50): array
    {
        try {
            $sql = "SELECT * FROM notifications";
            $params = [];

            if ($status !== null) {
                $sql .= " WHERE status = :status";
                $params[':status'] = $status;
            }

            $sql .= " ORDER BY created_at DESC LIMIT :limit";

            $stmt = $this->pdo->prepare($sql);

            if ($status !== null) {
                $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

            $stmt->execute();

            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode metadata JSON for each notification
            foreach ($notifications as &$notification) {
                if (!empty($notification['metadata'])) {
                    $notification['metadata'] = json_decode($notification['metadata'], true);
                }
            }

            return $notifications;
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::getAll'
            ]);
            return [];
        }
    }

    /**
     * Get unread notifications count
     *
     * @return int Number of unread notifications
     */
    public function getUnreadCount(): int
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM notifications WHERE status = 'unread'";
            $stmt = $this->pdo->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::getUnreadCount'
            ]);
            return 0;
        }
    }

    /**
     * Get a notification by ID
     *
     * @param int $id Notification ID
     * @return array|null Notification data or null if not found
     */
    public function getById(int $id): ?array
    {
        try {
            $sql = "SELECT * FROM notifications WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);

            $notification = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($notification && !empty($notification['metadata'])) {
                $notification['metadata'] = json_decode($notification['metadata'], true);
            }

            return $notification ?: null;
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::getById',
                'id' => $id
            ]);
            return null;
        }
    }

    /**
     * Mark a notification as read
     *
     * @param int $id Notification ID
     * @return bool True on success, false on failure
     */
    public function markAsRead(int $id): bool
    {
        try {
            $sql = "UPDATE notifications SET status = 'read', read_at = NOW(6) WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::markAsRead',
                'id' => $id
            ]);
            return false;
        }
    }

    /**
     * Mark all notifications as read
     *
     * @return bool True on success, false on failure
     */
    public function markAllAsRead(): bool
    {
        try {
            $sql = "UPDATE notifications SET status = 'read', read_at = NOW(6) WHERE status = 'unread'";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute();
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::markAllAsRead'
            ]);
            return false;
        }
    }

    /**
     * Dismiss a notification
     *
     * @param int $id Notification ID
     * @return bool True on success, false on failure
     */
    public function dismiss(int $id): bool
    {
        try {
            $sql = "UPDATE notifications SET status = 'dismissed' WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::dismiss',
                'id' => $id
            ]);
            return false;
        }
    }

    /**
     * Delete old notifications
     *
     * @param int $daysOld Number of days to keep notifications
     * @return int Number of deleted notifications
     */
    public function deleteOld(int $daysOld = 30): int
    {
        try {
            $sql = "DELETE FROM notifications
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
                    AND status != 'unread'";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':days' => $daysOld]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::deleteOld',
                'daysOld' => $daysOld
            ]);
            return 0;
        }
    }

    /**
     * Check if a notification with specific metadata exists
     * Useful to prevent duplicate notifications
     *
     * @param string $type Notification type
     * @param array $metadata Metadata to match
     * @return bool True if exists, false otherwise
     */
    public function existsByMetadata(string $type, array $metadata): bool
    {
        try {
            $sql = "SELECT COUNT(*) as count FROM notifications
                    WHERE type = :type
                    AND metadata = :metadata
                    AND status = 'unread'";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':type' => $type,
                ':metadata' => json_encode($metadata)
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0) > 0;
        } catch (PDOException $e) {
            SecureLogger::logException($e, [
                'method' => 'NotificationRepository::existsByMetadata'
            ]);
            return false;
        }
    }
}
