<?php
# Copyright 2025 The Vowels Company

/**
 * Notification Service Tests
 *
 * Tests the notification system including:
 * - Creating notifications
 * - Retrieving notifications
 * - Marking notifications as read/dismissed
 * - Resync notifications triggered by buildInvalidTransactionId
 */

require_once __DIR__ . '/../files/src/database/Pdo.php';
require_once __DIR__ . '/../files/src/services/ServiceContainer.php';
require_once __DIR__ . '/../files/src/core/UserContext.php';

class NotificationServiceTest
{
    private PDO $pdo;
    private ServiceContainer $serviceContainer;
    private NotificationService $notificationService;
    private NotificationRepository $notificationRepository;
    private UserContext $userContext;

    public function __construct()
    {
        echo "=== Notification Service Tests ===\n\n";

        // Initialize database connection
        $this->pdo = createPDOConnection();

        // Initialize user context
        $this->userContext = UserContext::getInstance();

        // Initialize service container
        $this->serviceContainer = ServiceContainer::getInstance($this->userContext, $this->pdo);

        // Get services
        $this->notificationService = $this->serviceContainer->getNotificationService();
        $this->notificationRepository = $this->serviceContainer->getNotificationRepository();
    }

    public function runTests(): bool
    {
        $allPassed = true;

        $allPassed = $this->testCreateNotification() && $allPassed;
        $allPassed = $this->testCreateResyncNotification() && $allPassed;
        $allPassed = $this->testGetNotifications() && $allPassed;
        $allPassed = $this->testGetUnreadCount() && $allPassed;
        $allPassed = $this->testMarkAsRead() && $allPassed;
        $allPassed = $this->testMarkAllAsRead() && $allPassed;
        $allPassed = $this->testDismissNotification() && $allPassed;
        $allPassed = $this->testPreventDuplicates() && $allPassed;
        $allPassed = $this->testDeleteOldNotifications() && $allPassed;

        echo "\n";
        if ($allPassed) {
            echo "✓ All notification tests passed!\n";
        } else {
            echo "✗ Some notification tests failed.\n";
        }

        return $allPassed;
    }

    private function testCreateNotification(): bool
    {
        echo "Test: Create basic notification... ";

        try {
            $notificationId = $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Test Notification',
                'This is a test notification'
            );

            if ($notificationId === false) {
                echo "✗ FAILED: Could not create notification\n";
                return false;
            }

            // Verify notification was created
            $notification = $this->notificationRepository->getById($notificationId);
            if (!$notification) {
                echo "✗ FAILED: Notification not found after creation\n";
                return false;
            }

            if ($notification['type'] !== NotificationService::TYPE_INFO) {
                echo "✗ FAILED: Notification type mismatch\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testCreateResyncNotification(): bool
    {
        echo "Test: Create resync notification... ";

        try {
            $notificationId = $this->notificationService->createResyncNotification(
                'test-txid-123',
                'expected-txid-456',
                'received-txid-789'
            );

            if ($notificationId === false) {
                echo "✗ FAILED: Could not create resync notification\n";
                return false;
            }

            // Verify notification was created with correct metadata
            $notification = $this->notificationRepository->getById($notificationId);
            if (!$notification) {
                echo "✗ FAILED: Resync notification not found\n";
                return false;
            }

            if ($notification['type'] !== NotificationService::TYPE_RESYNC) {
                echo "✗ FAILED: Notification type should be 'resync'\n";
                return false;
            }

            if (empty($notification['metadata']) || !isset($notification['metadata']['expected_txid'])) {
                echo "✗ FAILED: Metadata not properly stored\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testGetNotifications(): bool
    {
        echo "Test: Get notifications... ";

        try {
            // Create a test notification
            $this->notificationService->createNotification(
                NotificationService::TYPE_WARNING,
                'Test Warning',
                'This is a test warning'
            );

            // Get unread notifications
            $notifications = $this->notificationService->getNotifications('unread');

            if (empty($notifications)) {
                echo "✗ FAILED: No notifications retrieved\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testGetUnreadCount(): bool
    {
        echo "Test: Get unread count... ";

        try {
            $countBefore = $this->notificationService->getUnreadCount();

            // Create a new notification
            $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Count Test',
                'Testing unread count'
            );

            $countAfter = $this->notificationService->getUnreadCount();

            if ($countAfter <= $countBefore) {
                echo "✗ FAILED: Unread count did not increase\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testMarkAsRead(): bool
    {
        echo "Test: Mark notification as read... ";

        try {
            // Create a test notification
            $notificationId = $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Read Test',
                'Testing mark as read'
            );

            // Mark it as read
            $result = $this->notificationService->markAsRead($notificationId);

            if (!$result) {
                echo "✗ FAILED: Could not mark notification as read\n";
                return false;
            }

            // Verify status changed
            $notification = $this->notificationRepository->getById($notificationId);
            if ($notification['status'] !== 'read') {
                echo "✗ FAILED: Status not updated to 'read'\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testMarkAllAsRead(): bool
    {
        echo "Test: Mark all notifications as read... ";

        try {
            // Create multiple test notifications
            $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Batch Test 1',
                'Testing batch mark as read'
            );
            $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Batch Test 2',
                'Testing batch mark as read'
            );

            // Mark all as read
            $result = $this->notificationService->markAllAsRead();

            if (!$result) {
                echo "✗ FAILED: Could not mark all as read\n";
                return false;
            }

            // Verify unread count is 0
            $unreadCount = $this->notificationService->getUnreadCount();
            if ($unreadCount !== 0) {
                echo "✗ FAILED: Unread count should be 0 after marking all as read\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testDismissNotification(): bool
    {
        echo "Test: Dismiss notification... ";

        try {
            // Create a test notification
            $notificationId = $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Dismiss Test',
                'Testing dismiss functionality'
            );

            // Dismiss it
            $result = $this->notificationService->dismiss($notificationId);

            if (!$result) {
                echo "✗ FAILED: Could not dismiss notification\n";
                return false;
            }

            // Verify status changed
            $notification = $this->notificationRepository->getById($notificationId);
            if ($notification['status'] !== 'dismissed') {
                echo "✗ FAILED: Status not updated to 'dismissed'\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testPreventDuplicates(): bool
    {
        echo "Test: Prevent duplicate notifications... ";

        try {
            $metadata = ['txid' => 'duplicate-test-123'];

            // Create first notification
            $id1 = $this->notificationService->createNotification(
                NotificationService::TYPE_RESYNC,
                'Duplicate Test',
                'Testing duplicate prevention',
                $metadata
            );

            // Try to create duplicate
            $id2 = $this->notificationService->createNotification(
                NotificationService::TYPE_RESYNC,
                'Duplicate Test',
                'Testing duplicate prevention',
                $metadata
            );

            if ($id2 !== false) {
                echo "✗ FAILED: Duplicate notification was created\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testDeleteOldNotifications(): bool
    {
        echo "Test: Delete old notifications... ";

        try {
            // Create and mark a notification as read (so it can be deleted)
            $notificationId = $this->notificationService->createNotification(
                NotificationService::TYPE_INFO,
                'Old Test',
                'Testing cleanup'
            );
            $this->notificationService->markAsRead($notificationId);

            // Manually update created_at to be old (simulate old notification)
            $sql = "UPDATE notifications SET created_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $notificationId]);

            // Delete old notifications (30 days)
            $deletedCount = $this->notificationService->cleanupOldNotifications(30);

            if ($deletedCount < 1) {
                echo "✗ FAILED: No old notifications were deleted\n";
                return false;
            }

            echo "✓ PASSED\n";
            return true;
        } catch (Exception $e) {
            echo "✗ FAILED: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Run tests
$test = new NotificationServiceTest();
$success = $test->runTests();

exit($success ? 0 : 1);
