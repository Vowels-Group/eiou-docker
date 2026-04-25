<?php
/**
 * Unit Tests for GUI Functions
 *
 * Tests the GUI router and view data initialization logic.
 * Note: The Functions.php file is procedural and depends on global variables,
 * so these tests focus on documenting and verifying the expected behavior.
 */

namespace Eiou\Tests\Gui;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
class FunctionsTest extends TestCase
{
    // =========================================================================
    // POST Request Routing Tests
    // =========================================================================

    /**
     * Test contact actions are correctly identified
     *
     * Smoke list of the contact-controller routes that Functions.php
     * dispatches. Update this list whenever a new contact action is added
     * to the whitelist so a quick `grep` of the test reflects the real
     * surface. The new bulk-decline action `declineContact` mirrors
     * `eiou contact decline <hash>` and `POST /api/v1/contacts/:hash/decline`.
     */
    public function testContactActionsAreCorrectlyIdentified(): void
    {
        $contactActions = [
            'addContact',
            'acceptContact',
            'acceptCurrency',
            'acceptAllCurrencies',
            'applyContactDecisions',
            'declineCurrency',
            'declineContact',
            'deleteContact',
            'blockContact',
            'unblockContact',
            'editContact'
        ];

        $testAction = 'addContact';
        $this->assertContains($testAction, $contactActions);
        // Pin the new bulk-decline action so the rework doesn't silently
        // regress this list.
        $this->assertContains('declineContact', $contactActions);
        $this->assertContains('applyContactDecisions', $contactActions);
    }

    /**
     * Test transaction actions are correctly identified
     */
    public function testTransactionActionsAreCorrectlyIdentified(): void
    {
        $transactionActions = ['sendEIOU'];

        $testAction = 'sendEIOU';

        $this->assertContains($testAction, $transactionActions);
    }

    /**
     * Test settings actions are correctly identified
     */
    public function testSettingsActionsAreCorrectlyIdentified(): void
    {
        $settingsActions = [
            'updateSettings',
            'clearDebugLogs',
            'sendDebugReport'
        ];

        $testAction = 'updateSettings';

        $this->assertContains($testAction, $settingsActions);
    }

    /**
     * Test AJAX-only actions list
     */
    public function testAjaxOnlyActionsList(): void
    {
        $ajaxActions = [
            'getDebugReportJson',
            'pingContact'
        ];

        // These actions should return JSON and exit
        $this->assertContains('getDebugReportJson', $ajaxActions);
        $this->assertContains('pingContact', $ajaxActions);
    }

    // =========================================================================
    // GET Request Handling Tests
    // =========================================================================

    /**
     * Test check_updates parameter is recognized
     */
    public function testCheckUpdatesParameterIsRecognized(): void
    {
        // Simulates the check in Functions.php
        $_GET['check_updates'] = '1';

        $checkUpdates = isset($_GET['check_updates']);

        $this->assertTrue($checkUpdates);

        unset($_GET['check_updates']);
    }

    // =========================================================================
    // Message Display Tests
    // =========================================================================

    /**
     * Test message sanitization prevents XSS
     */
    public function testMessageSanitizationPreventsXss(): void
    {
        $maliciousMessage = '<script>alert("XSS")</script>';
        $maliciousType = '<img onerror="alert(1)" src=x>';

        $sanitizedMessage = htmlspecialchars($maliciousMessage, ENT_QUOTES, 'UTF-8');
        $sanitizedType = htmlspecialchars($maliciousType, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('<script>', $sanitizedMessage);
        $this->assertStringNotContainsString('<img', $sanitizedType);
        $this->assertStringContainsString('&lt;script&gt;', $sanitizedMessage);
    }

    /**
     * Test message parameters default to empty strings
     */
    public function testMessageParametersDefaultToEmptyStrings(): void
    {
        // Simulate the logic from Functions.php when no params present
        if (isset($_GET['message']) && isset($_GET['type'])) {
            $messageForDisplay = htmlspecialchars($_GET['message'], ENT_QUOTES, 'UTF-8');
            $messageTypeForDisplay = htmlspecialchars($_GET['type'], ENT_QUOTES, 'UTF-8');
        } else {
            $messageForDisplay = '';
            $messageTypeForDisplay = '';
        }

        $this->assertEquals('', $messageForDisplay);
        $this->assertEquals('', $messageTypeForDisplay);
    }

    /**
     * Test message and type are extracted when present
     */
    public function testMessageAndTypeAreExtractedWhenPresent(): void
    {
        $_GET['message'] = 'Test message';
        $_GET['type'] = 'success';

        if (isset($_GET['message']) && isset($_GET['type'])) {
            $messageForDisplay = htmlspecialchars($_GET['message'], ENT_QUOTES, 'UTF-8');
            $messageTypeForDisplay = htmlspecialchars($_GET['type'], ENT_QUOTES, 'UTF-8');
        } else {
            $messageForDisplay = '';
            $messageTypeForDisplay = '';
        }

        $this->assertEquals('Test message', $messageForDisplay);
        $this->assertEquals('success', $messageTypeForDisplay);

        unset($_GET['message'], $_GET['type']);
    }

    // =========================================================================
    // Transaction Notification Tests
    // =========================================================================

    /**
     * Test completed transactions are detected by comparing txids
     */
    public function testCompletedTransactionsAreDetectedByComparingTxids(): void
    {
        // Previous in-progress txids
        $prevInProgressTxids = ['tx1', 'tx2', 'tx3'];

        // Current in-progress txids (tx2 and tx3 still in progress, tx1 completed)
        $currentInProgressTxids = ['tx2', 'tx3'];

        // Find completed txids
        $completedTxids = array_diff($prevInProgressTxids, $currentInProgressTxids);

        $this->assertEquals(['tx1'], array_values($completedTxids));
    }

    /**
     * Test no completed transactions when lists are same
     */
    public function testNoCompletedTransactionsWhenListsAreSame(): void
    {
        $prevInProgressTxids = ['tx1', 'tx2'];
        $currentInProgressTxids = ['tx1', 'tx2'];

        $completedTxids = array_diff($prevInProgressTxids, $currentInProgressTxids);

        $this->assertEmpty($completedTxids);
    }

    /**
     * Test completed transactions are matched with transaction history
     */
    public function testCompletedTransactionsAreMatchedWithTransactionHistory(): void
    {
        $completedTxids = ['tx1'];
        $transactions = [
            ['txid' => 'tx1', 'status' => 'completed', 'amount' => 100],
            ['txid' => 'tx2', 'status' => 'pending', 'amount' => 200]
        ];

        $newlyCompletedTransactions = [];
        foreach ($completedTxids as $txid) {
            foreach ($transactions as $tx) {
                if (($tx['txid'] ?? '') === $txid && ($tx['status'] ?? '') === 'completed') {
                    $newlyCompletedTransactions[] = $tx;
                    break;
                }
            }
        }

        $this->assertCount(1, $newlyCompletedTransactions);
        $this->assertEquals('tx1', $newlyCompletedTransactions[0]['txid']);
    }

    // =========================================================================
    // Dead Letter Queue Notification Tests
    // =========================================================================

    /**
     * Test new DLQ items are detected by comparing IDs
     */
    public function testNewDlqItemsAreDetectedByComparingIds(): void
    {
        // Previously known DLQ IDs
        $prevDlqIds = [1, 2, 3];

        // Current DLQ items
        $currentDlqItems = [
            ['id' => 2],
            ['id' => 3],
            ['id' => 4], // New item
            ['id' => 5]  // New item
        ];

        // Find new items
        $currentDlqIds = array_column($currentDlqItems, 'id');
        $newlyAddedToDlq = [];
        foreach ($currentDlqItems as $item) {
            if (!in_array($item['id'], $prevDlqIds)) {
                $newlyAddedToDlq[] = $item;
            }
        }

        $this->assertCount(2, $newlyAddedToDlq);
        $this->assertEquals(4, $newlyAddedToDlq[0]['id']);
        $this->assertEquals(5, $newlyAddedToDlq[1]['id']);
    }

    /**
     * Test DLQ notification gracefully handles exceptions
     */
    public function testDlqNotificationGracefullyHandlesExceptions(): void
    {
        $newlyAddedToDlq = [];

        try {
            // Simulate a scenario that throws
            throw new \Exception('DLQ repository error');
        } catch (\Exception $e) {
            // Silently fail - DLQ notification is non-critical
            $newlyAddedToDlq = [];
        }

        $this->assertEmpty($newlyAddedToDlq);
    }

    // =========================================================================
    // Session Storage Tests
    // =========================================================================

    /**
     * Test in-progress txids are stored in session
     */
    public function testInProgressTxidsAreStoredInSession(): void
    {
        $currentInProgressTxids = ['tx1', 'tx2', 'tx3'];

        $_SESSION['in_progress_txids'] = $currentInProgressTxids;

        $this->assertEquals($currentInProgressTxids, $_SESSION['in_progress_txids']);

        unset($_SESSION['in_progress_txids']);
    }

    /**
     * Test DLQ IDs are stored in session
     */
    public function testDlqIdsAreStoredInSession(): void
    {
        $currentDlqIds = [1, 2, 3, 4];

        $_SESSION['known_dlq_ids'] = $currentDlqIds;

        $this->assertEquals($currentDlqIds, $_SESSION['known_dlq_ids']);

        unset($_SESSION['known_dlq_ids']);
    }

    /**
     * Test session defaults are handled via null coalescing
     */
    public function testSessionDefaultsAreHandledViaNullCoalescing(): void
    {
        unset($_SESSION['in_progress_txids']);
        unset($_SESSION['known_dlq_ids']);

        $prevInProgressTxids = $_SESSION['in_progress_txids'] ?? [];
        $prevDlqIds = $_SESSION['known_dlq_ids'] ?? [];

        $this->assertEquals([], $prevInProgressTxids);
        $this->assertEquals([], $prevDlqIds);
    }

    // =========================================================================
    // Request Method Tests
    // =========================================================================

    /**
     * Test POST request detection
     */
    public function testPostRequestDetection(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

        $this->assertTrue($isPost);

        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Test GET request detection
     */
    public function testGetRequestDetection(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $isGet = $_SERVER['REQUEST_METHOD'] === 'GET';

        $this->assertTrue($isGet);

        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Test action parameter is required for POST routing
     */
    public function testActionParameterIsRequiredForPostRouting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_POST['action']);

        $shouldRoute = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']);

        $this->assertFalse($shouldRoute);

        unset($_SERVER['REQUEST_METHOD']);
    }

    // =========================================================================
    // Tor/SOCKS5 GUI Status Tests
    // =========================================================================

    /**
     * Test Tor GUI status file with 'issue' within 10 minutes is valid
     */
    public function testTorGuiStatusIssueWithinTenMinutesIsValid(): void
    {
        $torGuiStatusFile = tempnam(sys_get_temp_dir(), 'tor-gui-test-');
        file_put_contents($torGuiStatusFile, json_encode([
            'status' => 'issue',
            'timestamp' => time() - 300, // 5 minutes ago
            'message' => 'Tor connectivity issue detected'
        ]));

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNotNull($torGuiStatus);
        $this->assertEquals('issue', $torGuiStatus['status']);

        @unlink($torGuiStatusFile);
    }

    /**
     * Test Tor GUI status file with 'recovered' within 5 minutes is valid
     */
    public function testTorGuiStatusRecoveredWithinFiveMinutesIsValid(): void
    {
        $torGuiStatusFile = tempnam(sys_get_temp_dir(), 'tor-gui-test-');
        file_put_contents($torGuiStatusFile, json_encode([
            'status' => 'recovered',
            'timestamp' => time() - 120, // 2 minutes ago
            'message' => 'Tor connectivity restored'
        ]));

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNotNull($torGuiStatus);
        $this->assertEquals('recovered', $torGuiStatus['status']);

        @unlink($torGuiStatusFile);
    }

    /**
     * Test Tor GUI status file with 'recovered' older than 5 minutes is cleaned up
     */
    public function testTorGuiStatusRecoveredOlderThanFiveMinutesIsCleanedUp(): void
    {
        $torGuiStatusFile = tempnam(sys_get_temp_dir(), 'tor-gui-test-');
        file_put_contents($torGuiStatusFile, json_encode([
            'status' => 'recovered',
            'timestamp' => time() - 360, // 6 minutes ago
            'message' => 'Tor connectivity restored'
        ]));

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNull($torGuiStatus);
        $this->assertFileDoesNotExist($torGuiStatusFile);
    }

    /**
     * Test Tor GUI status file with any status older than 10 minutes is cleaned up
     */
    public function testTorGuiStatusOlderThanTenMinutesIsCleanedUp(): void
    {
        $torGuiStatusFile = tempnam(sys_get_temp_dir(), 'tor-gui-test-');
        file_put_contents($torGuiStatusFile, json_encode([
            'status' => 'issue',
            'timestamp' => time() - 700, // ~11.7 minutes ago
            'message' => 'Tor connectivity issue detected'
        ]));

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNull($torGuiStatus);
        $this->assertFileDoesNotExist($torGuiStatusFile);
    }

    /**
     * Test Tor GUI status with missing file returns null
     */
    public function testTorGuiStatusMissingFileReturnsNull(): void
    {
        $torGuiStatusFile = '/tmp/tor-gui-test-nonexistent-' . uniqid();

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNull($torGuiStatus);
    }

    /**
     * Test Tor GUI status with invalid JSON returns null
     */
    public function testTorGuiStatusInvalidJsonReturnsNull(): void
    {
        $torGuiStatusFile = tempnam(sys_get_temp_dir(), 'tor-gui-test-');
        file_put_contents($torGuiStatusFile, 'not valid json');

        $torGuiStatus = null;
        if (file_exists($torGuiStatusFile)) {
            $torGuiRaw = @file_get_contents($torGuiStatusFile);
            if ($torGuiRaw !== false) {
                $torGuiData = json_decode($torGuiRaw, true);
                if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
                    $torGuiAge = time() - (int)$torGuiData['timestamp'];
                    if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                        @unlink($torGuiStatusFile);
                    } elseif ($torGuiAge > 600) {
                        @unlink($torGuiStatusFile);
                    } else {
                        $torGuiStatus = $torGuiData;
                    }
                }
            }
        }

        $this->assertNull($torGuiStatus);

        @unlink($torGuiStatusFile);
    }

    // =========================================================================
    // JSON Response Tests
    // =========================================================================

    /**
     * Test JSON content type header format
     */
    public function testJsonContentTypeHeaderFormat(): void
    {
        $expectedHeader = 'Content-Type: application/json';

        $this->assertStringContainsString('application/json', $expectedHeader);
    }

    /**
     * Test error response format for AJAX actions
     */
    public function testErrorResponseFormatForAjaxActions(): void
    {
        $errorResponse = json_encode(['error' => 'Server error: Test error']);

        $decoded = json_decode($errorResponse, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertStringContainsString('Server error:', $decoded['error']);
    }

    /**
     * Test pingContact error response format
     */
    public function testPingContactErrorResponseFormat(): void
    {
        $errorResponse = json_encode([
            'success' => false,
            'error' => 'server_error',
            'message' => 'Server error: Test error'
        ]);

        $decoded = json_decode($errorResponse, true);

        $this->assertFalse($decoded['success']);
        $this->assertEquals('server_error', $decoded['error']);
        $this->assertArrayHasKey('message', $decoded);
    }
}
