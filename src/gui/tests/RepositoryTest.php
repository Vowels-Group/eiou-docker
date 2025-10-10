<?php
/**
 * Repository and Database Functions Test Suite
 * Tests all database queries and repository methods
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 */

// Include required files
require_once(__DIR__ . '/../functions/functions.php');
require_once('/etc/eiou/src/database/ContactRepository.php');
require_once('/etc/eiou/src/database/TransactionRepository.php');
require_once('/etc/eiou/src/services/ServiceContainer.php');

class RepositoryTest {
    private $testsPassed = 0;
    private $testsFailed = 0;
    private $pdo;

    public function __construct() {
        // Initialize database connection
        $this->pdo = getPDOConnection();
    }

    /**
     * Test result tracking and display
     */
    private function testResult($testName, $result, $details = '') {
        if ($result) {
            echo "✅ PASS: $testName\n";
            if ($details) echo "   Details: $details\n";
            $this->testsPassed++;
        } else {
            echo "❌ FAIL: $testName\n";
            if ($details) echo "   Error: $details\n";
            $this->testsFailed++;
        }
    }

    /**
     * Test database connection
     */
    public function testDatabaseConnection() {
        echo "\n=== Testing Database Connection ===\n";

        // Test 1: PDO connection established
        $this->testResult(
            "PDO connection established",
            $this->pdo !== null && $this->pdo instanceof PDO,
            "Connection type: " . get_class($this->pdo ?? new stdClass())
        );

        // Test 2: Can execute simple query
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query("SELECT 1 as test");
                $result = $stmt->fetch();
                $this->testResult(
                    "Can execute simple query",
                    $result['test'] === 1,
                    "Query returned expected result"
                );
            } catch (Exception $e) {
                $this->testResult(
                    "Can execute simple query",
                    false,
                    "Error: " . $e->getMessage()
                );
            }
        }

        // Test 3: Lazy initialization works
        global $pdo;
        $pdo = null;
        $newConnection = getPDOConnection();
        $this->testResult(
            "Lazy initialization creates connection",
            $newConnection !== null,
            "Connection created on demand"
        );

        // Test 4: Same connection returned on subsequent calls
        $connection2 = getPDOConnection();
        $this->testResult(
            "Same connection reused",
            $newConnection === $connection2,
            "Connection singleton pattern works"
        );
    }

    /**
     * Test contact query functions
     */
    public function testContactQueries() {
        echo "\n=== Testing Contact Query Functions ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping contact queries - no database connection\n";
            return;
        }

        // Test 1: Get accepted contacts
        $acceptedContacts = getAcceptedContacts();
        $this->testResult(
            "Get accepted contacts query",
            is_array($acceptedContacts),
            "Retrieved " . count($acceptedContacts) . " accepted contacts"
        );

        // Test 2: Get pending contacts
        $pendingContacts = getPendingContacts();
        $this->testResult(
            "Get pending contacts query",
            is_array($pendingContacts),
            "Retrieved " . count($pendingContacts) . " pending contacts"
        );

        // Test 3: Get user pending contacts (where name is set)
        $userPendingContacts = getUserPendingContacts();
        $this->testResult(
            "Get user pending contacts query",
            is_array($userPendingContacts),
            "Retrieved " . count($userPendingContacts) . " user-initiated pending contacts"
        );

        // Test 4: Get blocked contacts
        $blockedContacts = getBlockedContacts();
        $this->testResult(
            "Get blocked contacts query",
            is_array($blockedContacts),
            "Retrieved " . count($blockedContacts) . " blocked contacts"
        );

        // Test 5: Get all contacts
        $allContacts = getAllContacts();
        $this->testResult(
            "Get all contacts query",
            is_array($allContacts),
            "Retrieved " . count($allContacts) . " total contacts"
        );

        // Test 6: All contacts count matches sum of categories
        $calculatedTotal = count($acceptedContacts) + count($pendingContacts) +
                          count($userPendingContacts) + count($blockedContacts);
        $this->testResult(
            "Contact counts are consistent",
            count($allContacts) >= $calculatedTotal,
            "Total: " . count($allContacts) . ", Categories sum: $calculatedTotal"
        );

        // Test 7: Accepted contacts have required fields
        if (!empty($acceptedContacts)) {
            $firstContact = $acceptedContacts[0];
            $hasRequiredFields = isset($firstContact['address']) &&
                               isset($firstContact['pubkey']) &&
                               isset($firstContact['status']);
            $this->testResult(
                "Accepted contacts have required fields",
                $hasRequiredFields,
                "Fields: " . implode(', ', array_keys($firstContact))
            );
        }

        // Test 8: Pending contacts structure
        if (!empty($pendingContacts)) {
            $firstPending = $pendingContacts[0];
            $this->testResult(
                "Pending contacts have address and pubkey",
                isset($firstPending['address']) && isset($firstPending['pubkey']),
                "Pending contact structure valid"
            );
        }
    }

    /**
     * Test balance calculation functions
     */
    public function testBalanceCalculations() {
        echo "\n=== Testing Balance Calculation Functions ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping balance calculations - no database connection\n";
            return;
        }

        // Test 1: Get user total balance
        $totalBalance = getUserTotalBalance();
        $this->testResult(
            "Get user total balance",
            $totalBalance !== null && is_string($totalBalance),
            "Balance: \$$totalBalance"
        );

        // Test 2: Balance format is correct (2 decimal places)
        if ($totalBalance !== null) {
            $parts = explode('.', $totalBalance);
            $this->testResult(
                "Balance formatted with 2 decimals",
                count($parts) === 2 && strlen($parts[1]) === 2,
                "Format: $totalBalance"
            );
        }

        // Test 3: Contact balance calculation (if contacts exist)
        $contacts = getAcceptedContacts();
        if (!empty($contacts)) {
            global $user;
            if (isset($user['public']) && isset($contacts[0]['pubkey'])) {
                $contactBalance = getContactBalance($user['public'], $contacts[0]['pubkey']);
                $this->testResult(
                    "Calculate individual contact balance",
                    is_numeric($contactBalance),
                    "Balance: $contactBalance cents"
                );
            }
        }

        // Test 4: Batch balance calculation (getAllContactBalances)
        if (!empty($contacts) && isset($user['public'])) {
            $pubkeys = array_column($contacts, 'pubkey');
            $balances = getAllContactBalances($user['public'], $pubkeys);

            $this->testResult(
                "Batch contact balances query",
                is_array($balances) && count($balances) === count($pubkeys),
                "Retrieved " . count($balances) . " balances"
            );

            // Test 5: All contacts have balance entries
            $allHaveBalances = true;
            foreach ($pubkeys as $pubkey) {
                if (!isset($balances[$pubkey])) {
                    $allHaveBalances = false;
                    break;
                }
            }
            $this->testResult(
                "All contacts have balance entries",
                $allHaveBalances,
                "Batch query covers all contacts"
            );

            // Test 6: Balances are numeric
            $allNumeric = true;
            foreach ($balances as $balance) {
                if (!is_numeric($balance)) {
                    $allNumeric = false;
                    break;
                }
            }
            $this->testResult(
                "All balances are numeric values",
                $allNumeric,
                "Balance data types correct"
            );
        }

        // Test 7: Empty pubkey array handling
        global $user;
        if (isset($user['public'])) {
            $emptyBalances = getAllContactBalances($user['public'], []);
            $this->testResult(
                "Empty pubkey array returns empty result",
                is_array($emptyBalances) && empty($emptyBalances),
                "Handles empty input correctly"
            );
        }

        // Test 8: Contact conversion with balances
        $acceptedContacts = getAcceptedContacts();
        if (!empty($acceptedContacts)) {
            $convertedContacts = contactConversion($acceptedContacts);
            $this->testResult(
                "Contact conversion processes data",
                is_array($convertedContacts) && count($convertedContacts) === count($acceptedContacts),
                "Converted " . count($convertedContacts) . " contacts"
            );

            // Test 9: Converted contacts have required output fields
            if (!empty($convertedContacts)) {
                $first = $convertedContacts[0];
                $hasFields = isset($first['name']) && isset($first['address']) &&
                           isset($first['balance']) && isset($first['fee']) &&
                           isset($first['credit_limit']) && isset($first['currency']);
                $this->testResult(
                    "Converted contacts have required output fields",
                    $hasFields,
                    "Fields: " . implode(', ', array_keys($first))
                );
            }
        }
    }

    /**
     * Test transaction query functions
     */
    public function testTransactionQueries() {
        echo "\n=== Testing Transaction Query Functions ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping transaction queries - no database connection\n";
            return;
        }

        // Test 1: Get transaction history
        $transactions = getTransactionHistory(10);
        $this->testResult(
            "Get transaction history query",
            is_array($transactions),
            "Retrieved " . count($transactions) . " transactions"
        );

        // Test 2: Transaction limit works
        $limited = getTransactionHistory(5);
        $this->testResult(
            "Transaction limit parameter works",
            is_array($limited) && count($limited) <= 5,
            "Limited to " . count($limited) . " transactions"
        );

        // Test 3: Transaction structure
        if (!empty($transactions)) {
            $firstTx = $transactions[0];
            $hasFields = isset($firstTx['date']) && isset($firstTx['type']) &&
                        isset($firstTx['amount']) && isset($firstTx['currency']) &&
                        isset($firstTx['counterparty']);
            $this->testResult(
                "Transactions have required fields",
                $hasFields,
                "Fields: " . implode(', ', array_keys($firstTx))
            );
        }

        // Test 4: Transaction types are valid
        if (!empty($transactions)) {
            $validTypes = true;
            foreach ($transactions as $tx) {
                if (!in_array($tx['type'], ['sent', 'received'])) {
                    $validTypes = false;
                    break;
                }
            }
            $this->testResult(
                "Transaction types are valid",
                $validTypes,
                "All types are 'sent' or 'received'"
            );
        }

        // Test 5: Amounts are numeric and positive
        if (!empty($transactions)) {
            $validAmounts = true;
            foreach ($transactions as $tx) {
                if (!is_numeric($tx['amount']) || $tx['amount'] < 0) {
                    $validAmounts = false;
                    break;
                }
            }
            $this->testResult(
                "Transaction amounts are valid",
                $validAmounts,
                "All amounts are numeric and non-negative"
            );
        }

        // Test 6: Transactions ordered by date (most recent first)
        if (count($transactions) >= 2) {
            $isOrdered = true;
            for ($i = 0; $i < count($transactions) - 1; $i++) {
                if (strtotime($transactions[$i]['date']) < strtotime($transactions[$i + 1]['date'])) {
                    $isOrdered = false;
                    break;
                }
            }
            $this->testResult(
                "Transactions ordered by date (newest first)",
                $isOrdered,
                "Date ordering verified"
            );
        }
    }

    /**
     * Test contact name lookup
     */
    public function testContactNameLookup() {
        echo "\n=== Testing Contact Name Lookup ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping contact lookup - no database connection\n";
            return;
        }

        // Test 1: Lookup existing contact
        $contacts = getAcceptedContacts();
        if (!empty($contacts)) {
            $testContact = $contacts[0];
            $name = getContactNameByAddress($testContact['address']);
            $this->testResult(
                "Lookup contact name by address",
                $name === $testContact['name'],
                "Found: " . ($name ?? 'null')
            );
        }

        // Test 2: Lookup non-existent address
        $fakeName = getContactNameByAddress('nonexistent_address_12345');
        $this->testResult(
            "Non-existent address returns null",
            $fakeName === null,
            "Correctly returns null for invalid address"
        );

        // Test 3: Empty address handling
        $emptyResult = getContactNameByAddress('');
        $this->testResult(
            "Empty address handled gracefully",
            $emptyResult === null,
            "Returns null for empty address"
        );
    }

    /**
     * Test new transaction/contact detection
     */
    public function testNewItemDetection() {
        echo "\n=== Testing New Item Detection ===\n";

        if ($this->pdo === null) {
            echo "⚠️  Skipping new item detection - no database connection\n";
            return;
        }

        // Test 1: Check for new transactions (very old timestamp)
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 year'));
        $hasNew = checkForNewTransactions($oldTime);
        $this->testResult(
            "Detect new transactions since old timestamp",
            is_bool($hasNew),
            "Result: " . ($hasNew ? 'has new' : 'no new')
        );

        // Test 2: Check for new transactions (recent timestamp)
        $recentTime = date('Y-m-d H:i:s', time());
        $hasNew = checkForNewTransactions($recentTime);
        $this->testResult(
            "No new transactions since current time",
            $hasNew === false,
            "Correctly returns false for current timestamp"
        );

        // Test 3: Check for new contact requests (old timestamp)
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 year'));
        $hasNewContacts = checkForNewContactRequests($oldTime);
        $this->testResult(
            "Detect new contact requests",
            is_bool($hasNewContacts),
            "Result: " . ($hasNewContacts ? 'has new' : 'no new')
        );

        // Test 4: No new contacts since now
        $recentTime = date('Y-m-d H:i:s', time());
        $hasNewContacts = checkForNewContactRequests($recentTime);
        $this->testResult(
            "No new contact requests since current time",
            $hasNewContacts === false,
            "Correctly returns false for current timestamp"
        );
    }

    /**
     * Run all repository tests
     */
    public function runAllTests() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "GUI REPOSITORY & DATABASE TEST SUITE\n";
        echo str_repeat("=", 60) . "\n";

        $this->testDatabaseConnection();
        $this->testContactQueries();
        $this->testBalanceCalculations();
        $this->testTransactionQueries();
        $this->testContactNameLookup();
        $this->testNewItemDetection();

        // Summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "REPOSITORY TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "✅ Tests Passed: {$this->testsPassed}\n";
        echo "❌ Tests Failed: {$this->testsFailed}\n";
        echo "Total Tests: " . ($this->testsPassed + $this->testsFailed) . "\n";

        if ($this->testsFailed === 0) {
            echo "\n🎉 All repository tests passed!\n";
        } else {
            echo "\n⚠️ Some tests failed. Please review the errors above.\n";
        }
        echo str_repeat("=", 60) . "\n";

        return ['passed' => $this->testsPassed, 'failed' => $this->testsFailed];
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new RepositoryTest();
    $results = $tester->runAllTests();
    exit($results['failed'] > 0 ? 1 : 0);
}
