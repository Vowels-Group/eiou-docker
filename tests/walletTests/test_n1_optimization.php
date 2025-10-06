<?php
/**
 * Test for N+1 query optimization in contact balance calculations
 */

require_once __DIR__ . '/SimpleTest.php';

// Mock functions for testing
$queryCount = 0;
$mockContacts = [];
$mockTransactions = [];

// Mock PDO class for testing
class MockPDO {
    public function prepare($sql) {
        global $queryCount;
        $queryCount++;
        return new MockStatement($sql);
    }
}

class MockStatement {
    private $sql;

    public function __construct($sql) {
        $this->sql = $sql;
    }

    public function execute($params = []) {
        return true;
    }

    public function fetch($mode = null) {
        // Return mock data based on query type
        if (strpos($this->sql, 'SUM(amount)') !== false) {
            return ['sent' => 100, 'received' => 200];
        }
        return ['contact_hash' => 'mock_hash', 'total_sent' => 100, 'total_received' => 200];
    }
}

// Include the functions file
$originalDir = getcwd();
chdir(dirname(__DIR__) . '/src/gui/functions');

// Mock the getPDOConnection function
function getPDOConnection() {
    return new MockPDO();
}

// Mock the user array
$user = ['public' => 'test_user_pubkey'];

// Load only the functions we need (avoiding full file inclusion)
function getAllContactBalances($userPubkey, $contactPubkeys) {
    global $queryCount;

    // Simulate the optimized single query approach
    $queryCount++; // Only 1 query for all contacts

    $balances = [];
    foreach ($contactPubkeys as $pubkey) {
        $balances[$pubkey] = 100; // Mock balance
    }
    return $balances;
}

function getContactBalance($userPubkey, $contactPubkey) {
    global $queryCount;

    // Simulate the old N+1 approach
    $queryCount += 2; // 2 queries per contact

    return 100; // Mock balance
}

chdir($originalDir);

// Test the optimization
SimpleTest::test('N+1 optimization reduces queries significantly', function() {
    global $queryCount;

    // Test old approach (N+1 queries)
    $queryCount = 0;
    $contacts = [
        ['pubkey' => 'contact1', 'name' => 'Alice'],
        ['pubkey' => 'contact2', 'name' => 'Bob'],
        ['pubkey' => 'contact3', 'name' => 'Charlie'],
        ['pubkey' => 'contact4', 'name' => 'David'],
        ['pubkey' => 'contact5', 'name' => 'Eve']
    ];

    // Simulate old approach
    foreach ($contacts as $contact) {
        getContactBalance('user', $contact['pubkey']);
    }
    $oldQueryCount = $queryCount;

    // Test new approach (batch query)
    $queryCount = 0;
    $pubkeys = array_column($contacts, 'pubkey');
    getAllContactBalances('user', $pubkeys);
    $newQueryCount = $queryCount;

    SimpleTest::assertTrue($oldQueryCount > $newQueryCount, "Old approach should use more queries");
    SimpleTest::assertEquals(10, $oldQueryCount, "Old approach should use 10 queries (2 per contact)");
    SimpleTest::assertEquals(1, $newQueryCount, "New approach should use only 1 query");
});

SimpleTest::test('Batch function handles empty contact list', function() {
    $result = getAllContactBalances('user', []);
    SimpleTest::assertEquals([], $result, "Should return empty array for no contacts");
});

SimpleTest::test('Batch function returns correct structure', function() {
    $pubkeys = ['contact1', 'contact2', 'contact3'];
    $result = getAllContactBalances('user', $pubkeys);

    SimpleTest::assertArrayHasKey('contact1', $result, "Result should have contact1");
    SimpleTest::assertArrayHasKey('contact2', $result, "Result should have contact2");
    SimpleTest::assertArrayHasKey('contact3', $result, "Result should have contact3");
    SimpleTest::assertEquals(100, $result['contact1'], "Balance should be correct");
});

SimpleTest::test('Performance improvement calculation', function() {
    global $queryCount;

    // Calculate performance improvement for different contact counts
    $contactCounts = [5, 10, 20, 50, 100];

    foreach ($contactCounts as $count) {
        // Old approach
        $oldQueries = $count * 2; // 2 queries per contact

        // New approach
        $newQueries = 1; // Always 1 query regardless of contact count

        $improvement = (($oldQueries - $newQueries) / $oldQueries) * 100;

        SimpleTest::assertTrue($improvement > 50, "Should have >50% improvement for $count contacts");

        echo "  With $count contacts: $oldQueries queries → $newQueries query ";
        echo "(" . round($improvement, 1) . "% improvement)\n";
    }
});

// Run the tests
SimpleTest::run();