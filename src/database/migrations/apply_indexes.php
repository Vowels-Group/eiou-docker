<?php
/**
 * Database Index Migration Script
 * Applies performance indexes to optimize query execution
 *
 * Usage: php apply_indexes.php
 */

// Load database configuration
require_once dirname(__DIR__, 2) . '/gui/functions/functions.php';

echo "Database Index Migration\n";
echo "========================\n\n";

// Get PDO connection
$pdo = getPDOConnection();
if ($pdo === null) {
    die("Error: Could not connect to database\n");
}

// Define indexes to add
$indexes = [
    'p2p' => [
        ['name' => 'idx_status', 'column' => 'status'],
        ['name' => 'idx_created_at', 'column' => 'created_at'],
        ['name' => 'idx_sender_address', 'column' => 'sender_address'],
        ['name' => 'idx_status_created_at', 'column' => 'status, created_at'],
    ],
    'transactions' => [
        ['name' => 'idx_status', 'column' => 'status'],
        ['name' => 'idx_timestamp', 'column' => 'timestamp'],
        ['name' => 'idx_previous_txid', 'column' => 'previous_txid'],
        ['name' => 'idx_memo', 'column' => 'memo(255)'],
        ['name' => 'idx_status_timestamp', 'column' => 'status, timestamp'],
        ['name' => 'idx_sender_receiver', 'column' => 'sender_public_key_hash, receiver_public_key_hash'],
    ],
    'contacts' => [
        ['name' => 'idx_status', 'column' => 'status'],
        ['name' => 'idx_status_address', 'column' => 'status, address'],
        ['name' => 'idx_name', 'column' => 'name'],
    ],
    'rp2p' => [
        ['name' => 'idx_created_at', 'column' => 'created_at'],
        ['name' => 'idx_sender_address', 'column' => 'sender_address'],
    ],
    'debug' => [
        ['name' => 'idx_timestamp', 'column' => 'timestamp'],
        ['name' => 'idx_level', 'column' => 'level'],
        ['name' => 'idx_level_timestamp', 'column' => 'level, timestamp'],
    ],
];

$totalIndexes = 0;
$successCount = 0;
$skipCount = 0;
$errorCount = 0;

// Function to check if index exists
function indexExists($pdo, $table, $indexName) {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM $table WHERE Key_name = ?");
        $stmt->execute([$indexName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Function to check if table exists
function tableExists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Apply indexes
foreach ($indexes as $table => $tableIndexes) {
    echo "Processing table: $table\n";

    // Check if table exists
    if (!tableExists($pdo, $table)) {
        echo "  ⚠ Table '$table' does not exist, skipping...\n";
        continue;
    }

    foreach ($tableIndexes as $index) {
        $totalIndexes++;
        $indexName = $index['name'];
        $column = $index['column'];

        echo "  Adding index '$indexName' on column(s): $column... ";

        // Check if index already exists
        if (indexExists($pdo, $table, $indexName)) {
            echo "SKIPPED (already exists)\n";
            $skipCount++;
            continue;
        }

        // Try to add the index
        try {
            $sql = "ALTER TABLE $table ADD INDEX $indexName ($column)";
            $pdo->exec($sql);
            echo "✓ SUCCESS\n";
            $successCount++;
        } catch (PDOException $e) {
            echo "✗ ERROR: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    echo "\n";
}

// Print summary
echo "Migration Summary\n";
echo "=================\n";
echo "Total indexes processed: $totalIndexes\n";
echo "Successfully added:      $successCount\n";
echo "Skipped (existing):      $skipCount\n";
echo "Errors:                  $errorCount\n\n";

if ($errorCount > 0) {
    echo "⚠ Migration completed with errors\n";
    exit(1);
} else if ($successCount > 0) {
    echo "✅ Migration completed successfully\n";
} else {
    echo "ℹ No new indexes were added (all already exist)\n";
}

// Analyze tables to update statistics
echo "\nOptimizing table statistics...\n";
foreach (array_keys($indexes) as $table) {
    if (tableExists($pdo, $table)) {
        try {
            $pdo->exec("ANALYZE TABLE $table");
            echo "  ✓ Analyzed table: $table\n";
        } catch (PDOException $e) {
            echo "  ⚠ Could not analyze $table: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n✅ Index migration complete!\n";