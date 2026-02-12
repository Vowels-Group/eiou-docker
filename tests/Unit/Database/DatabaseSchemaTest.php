<?php
/**
 * Unit Tests for DatabaseSchema
 *
 * Tests database schema definitions and SQL generation functions.
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

// Import the schema functions - use constant if defined, otherwise calculate path
$filesRoot = defined('EIOU_FILES_ROOT') ? EIOU_FILES_ROOT : dirname(__DIR__, 3) . '/files';
require_once $filesRoot . '/src/database/DatabaseSchema.php';

use function Eiou\Database\getContactsTableSchema;
use function Eiou\Database\getAddressTableSchema;
use function Eiou\Database\getBalancesTableSchema;
use function Eiou\Database\getDebugTableSchema;
use function Eiou\Database\getP2pTableSchema;
use function Eiou\Database\getRp2pTableSchema;
use function Eiou\Database\getTransactionsTableSchema;
use function Eiou\Database\getApiKeysTableSchema;
use function Eiou\Database\getApiRequestLogTableSchema;
use function Eiou\Database\getMessageDeliveryTableSchema;
use function Eiou\Database\getDeadLetterQueueTableSchema;
use function Eiou\Database\getDeliveryMetricsTableSchema;
use function Eiou\Database\getRateLimitsTableSchema;
use function Eiou\Database\getHeldTransactionsTableSchema;
use function Eiou\Database\getP2pSendersTableSchema;

#[CoversFunction('Eiou\Database\getContactsTableSchema')]
#[CoversFunction('Eiou\Database\getAddressTableSchema')]
#[CoversFunction('Eiou\Database\getBalancesTableSchema')]
#[CoversFunction('Eiou\Database\getDebugTableSchema')]
#[CoversFunction('Eiou\Database\getP2pTableSchema')]
#[CoversFunction('Eiou\Database\getRp2pTableSchema')]
#[CoversFunction('Eiou\Database\getTransactionsTableSchema')]
#[CoversFunction('Eiou\Database\getApiKeysTableSchema')]
#[CoversFunction('Eiou\Database\getApiRequestLogTableSchema')]
#[CoversFunction('Eiou\Database\getMessageDeliveryTableSchema')]
#[CoversFunction('Eiou\Database\getDeadLetterQueueTableSchema')]
#[CoversFunction('Eiou\Database\getDeliveryMetricsTableSchema')]
#[CoversFunction('Eiou\Database\getRateLimitsTableSchema')]
#[CoversFunction('Eiou\Database\getHeldTransactionsTableSchema')]
#[CoversFunction('Eiou\Database\getP2pSendersTableSchema')]
class DatabaseSchemaTest extends TestCase
{
    // =========================================================================
    // Contacts Table Tests
    // =========================================================================

    /**
     * Test contacts table schema returns valid SQL
     */
    public function testGetContactsTableSchemaReturnsValidSql(): void
    {
        $schema = getContactsTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS contacts', $schema);
    }

    /**
     * Test contacts table schema has required columns
     */
    public function testContactsTableSchemaHasRequiredColumns(): void
    {
        $schema = getContactsTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'contact_id VARCHAR(128) NOT NULL UNIQUE',
            'pubkey TEXT NOT NULL',
            'pubkey_hash VARCHAR(64)',
            'name VARCHAR(255)',
            'status ENUM',
            'online_status ENUM',
            'valid_chain TINYINT(1)',
            'currency VARCHAR(10)',
            'fee_percent INT',
            'credit_limit INT',
            'created_at TIMESTAMP(6)',
            'last_ping_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test contacts table status enum values
     */
    public function testContactsTableStatusEnumValues(): void
    {
        $schema = getContactsTableSchema();

        $expectedStatusValues = ['pending', 'accepted', 'blocked'];

        foreach ($expectedStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing status enum value: $status");
        }
    }

    /**
     * Test contacts table online_status enum values
     */
    public function testContactsTableOnlineStatusEnumValues(): void
    {
        $schema = getContactsTableSchema();

        $expectedOnlineStatusValues = ['online', 'offline', 'unknown'];

        foreach ($expectedOnlineStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing online_status enum value: $status");
        }
    }

    /**
     * Test contacts table has required indexes
     */
    public function testContactsTableSchemaHasRequiredIndexes(): void
    {
        $schema = getContactsTableSchema();

        $expectedIndexes = [
            'idx_contacts_contact_id',
            'idx_contacts_pubkey_hash',
            'idx_contacts_name',
            'idx_contacts_status',
            'idx_contacts_pubkey_hash_status',
            'idx_contacts_online_status'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Addresses Table Tests
    // =========================================================================

    /**
     * Test addresses table schema returns valid SQL
     */
    public function testGetAddressTableSchemaReturnsValidSql(): void
    {
        $schema = getAddressTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS addresses', $schema);
    }

    /**
     * Test addresses table schema has required columns
     */
    public function testAddressTableSchemaHasRequiredColumns(): void
    {
        $schema = getAddressTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'pubkey_hash TEXT NOT NULL',
            'http VARCHAR(255)',
            'https VARCHAR(255)',
            'tor VARCHAR(255)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test addresses table has unique constraints
     */
    public function testAddressTableSchemaHasUniqueConstraints(): void
    {
        $schema = getAddressTableSchema();

        // Each address type should have UNIQUE constraint
        $this->assertStringContainsString('http VARCHAR(255) UNIQUE', $schema);
        $this->assertStringContainsString('https VARCHAR(255) UNIQUE', $schema);
        $this->assertStringContainsString('tor VARCHAR(255) UNIQUE', $schema);
    }

    /**
     * Test addresses table has required indexes
     */
    public function testAddressTableSchemaHasRequiredIndexes(): void
    {
        $schema = getAddressTableSchema();

        $expectedIndexes = [
            'idx_addresses_pubkey',
            'idx_addresses_http',
            'idx_addresses_https',
            'idx_addresses_tor'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Balances Table Tests
    // =========================================================================

    /**
     * Test balances table schema returns valid SQL
     */
    public function testGetBalancesTableSchemaReturnsValidSql(): void
    {
        $schema = getBalancesTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS balances', $schema);
    }

    /**
     * Test balances table schema has required columns
     */
    public function testBalancesTableSchemaHasRequiredColumns(): void
    {
        $schema = getBalancesTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'pubkey_hash TEXT NOT NULL',
            'received INT NOT NULL',
            'sent INT NOT NULL',
            'currency VARCHAR(10)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test balances table has required indexes
     */
    public function testBalancesTableSchemaHasRequiredIndexes(): void
    {
        $schema = getBalancesTableSchema();

        $this->assertStringContainsString('idx_balances_pubkey_hash', $schema);
    }

    // =========================================================================
    // Debug Table Tests
    // =========================================================================

    /**
     * Test debug table schema returns valid SQL
     */
    public function testGetDebugTableSchemaReturnsValidSql(): void
    {
        $schema = getDebugTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS debug', $schema);
    }

    /**
     * Test debug table schema has required columns
     */
    public function testDebugTableSchemaHasRequiredColumns(): void
    {
        $schema = getDebugTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'timestamp DATETIME(6)',
            'level ENUM',
            'message TEXT NOT NULL',
            'context JSON',
            'file VARCHAR(255)',
            'line INTEGER',
            'trace TEXT'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test debug table level enum values
     */
    public function testDebugTableLevelEnumValues(): void
    {
        $schema = getDebugTableSchema();

        $expectedLevelValues = ['SILENT', 'ECHO', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

        foreach ($expectedLevelValues as $level) {
            $this->assertStringContainsString("'$level'", $schema, "Missing level enum value: $level");
        }
    }

    /**
     * Test debug table has required indexes
     */
    public function testDebugTableSchemaHasRequiredIndexes(): void
    {
        $schema = getDebugTableSchema();

        $expectedIndexes = [
            'idx_timestamp',
            'idx_level',
            'idx_level_timestamp'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // P2P Table Tests
    // =========================================================================

    /**
     * Test p2p table schema returns valid SQL
     */
    public function testGetP2pTableSchemaReturnsValidSql(): void
    {
        $schema = getP2pTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS p2p', $schema);
    }

    /**
     * Test p2p table schema has required columns
     */
    public function testP2pTableSchemaHasRequiredColumns(): void
    {
        $schema = getP2pTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'hash VARCHAR(255) NOT NULL UNIQUE',
            'salt VARCHAR(255) NOT NULL',
            'time BIGINT NOT NULL',
            'expiration BIGINT NOT NULL',
            'currency VARCHAR(10) NOT NULL',
            'amount INTEGER NOT NULL',
            'my_fee_amount INTEGER',
            'destination_address VARCHAR(255)',
            'destination_pubkey TEXT',
            'destination_signature TEXT',
            'request_level INTEGER NOT NULL',
            'max_request_level INTEGER NOT NULL',
            'sender_public_key TEXT NOT NULL',
            'sender_address VARCHAR(255) NOT NULL',
            'sender_signature TEXT',
            'description TEXT',
            'status ENUM',
            'created_at TIMESTAMP(6)',
            'incoming_txid VARCHAR(255)',
            'outgoing_txid VARCHAR(255)',
            'completed_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test p2p table status enum values
     */
    public function testP2pTableStatusEnumValues(): void
    {
        $schema = getP2pTableSchema();

        $expectedStatusValues = [
            'initial',
            'queued',
            'sent',
            'found',
            'paid',
            'completed',
            'cancelled',
            'expired'
        ];

        foreach ($expectedStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing status enum value: $status");
        }
    }

    /**
     * Test p2p table has required indexes
     */
    public function testP2pTableSchemaHasRequiredIndexes(): void
    {
        $schema = getP2pTableSchema();

        $expectedIndexes = [
            'idx_p2p_hash',
            'idx_p2p_status',
            'idx_p2p_created_at',
            'idx_p2p_status_created_at',
            'idx_p2p_sender_address',
            'idx_p2p_sender_address_status',
            'idx_p2p_destination',
            'idx_p2p_incoming_txid',
            'idx_p2p_outgoing_txid',
            'idx_p2p_status_expiration'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // RP2P Table Tests
    // =========================================================================

    /**
     * Test rp2p table schema returns valid SQL
     */
    public function testGetRp2pTableSchemaReturnsValidSql(): void
    {
        $schema = getRp2pTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS rp2p', $schema);
    }

    /**
     * Test rp2p table schema has required columns
     */
    public function testRp2pTableSchemaHasRequiredColumns(): void
    {
        $schema = getRp2pTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'hash VARCHAR(255) NOT NULL UNIQUE',
            'time BIGINT NOT NULL',
            'amount INTEGER NOT NULL',
            'currency VARCHAR(10) NOT NULL',
            'sender_public_key TEXT NOT NULL',
            'sender_address VARCHAR(255) NOT NULL',
            'sender_signature TEXT NOT NULL',
            'created_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test rp2p table has required indexes
     */
    public function testRp2pTableSchemaHasRequiredIndexes(): void
    {
        $schema = getRp2pTableSchema();

        $expectedIndexes = [
            'idx_rp2p_hash',
            'idx_rp2p_created_at',
            'idx_rp2p_sender_address'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Transactions Table Tests
    // =========================================================================

    /**
     * Test transactions table schema returns valid SQL
     */
    public function testGetTransactionsTableSchemaReturnsValidSql(): void
    {
        $schema = getTransactionsTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS transactions', $schema);
    }

    /**
     * Test transactions table schema has required columns
     */
    public function testTransactionsTableSchemaHasRequiredColumns(): void
    {
        $schema = getTransactionsTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'tx_type ENUM',
            'type ENUM',
            'status ENUM',
            'sender_address VARCHAR(255) NOT NULL',
            'sender_public_key TEXT NOT NULL',
            'sender_public_key_hash VARCHAR(64)',
            'receiver_address VARCHAR(255) NOT NULL',
            'receiver_public_key TEXT NOT NULL',
            'receiver_public_key_hash VARCHAR(64)',
            'amount INT NOT NULL',
            'currency VARCHAR(10) NOT NULL',
            'timestamp DATETIME(6)',
            'txid VARCHAR(255) UNIQUE NOT NULL',
            'previous_txid VARCHAR(255)',
            'sender_signature TEXT',
            'recipient_signature TEXT',
            'signature_nonce BIGINT',
            'time BIGINT',
            'memo TEXT',
            'description TEXT',
            'initial_sender_address VARCHAR(255)',
            'end_recipient_address VARCHAR(255)',
            'sending_started_at DATETIME(6)',
            'recovery_count INT',
            'needs_manual_review TINYINT(1)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test transactions table tx_type enum values
     */
    public function testTransactionsTableTxTypeEnumValues(): void
    {
        $schema = getTransactionsTableSchema();

        $expectedTxTypeValues = ['standard', 'p2p', 'contact'];

        foreach ($expectedTxTypeValues as $txType) {
            $this->assertStringContainsString("'$txType'", $schema, "Missing tx_type enum value: $txType");
        }
    }

    /**
     * Test transactions table type enum values
     */
    public function testTransactionsTableTypeEnumValues(): void
    {
        $schema = getTransactionsTableSchema();

        $expectedTypeValues = ['received', 'sent', 'relay'];

        foreach ($expectedTypeValues as $type) {
            $this->assertStringContainsString("'$type'", $schema, "Missing type enum value: $type");
        }
    }

    /**
     * Test transactions table status enum values
     */
    public function testTransactionsTableStatusEnumValues(): void
    {
        $schema = getTransactionsTableSchema();

        $expectedStatusValues = [
            'pending',
            'sending',
            'sent',
            'accepted',
            'rejected',
            'cancelled',
            'completed',
            'failed'
        ];

        foreach ($expectedStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing status enum value: $status");
        }
    }

    /**
     * Test transactions table has required indexes
     */
    public function testTransactionsTableSchemaHasRequiredIndexes(): void
    {
        $schema = getTransactionsTableSchema();

        $expectedIndexes = [
            'idx_transactions_receiver_public_key_hash',
            'idx_transactions_sender_public_key_hash',
            'idx_transactions_sender_receiver',
            'idx_transactions_chain',
            'idx_transactions_status',
            'idx_transactions_timestamp',
            'idx_transactions_status_timestamp',
            'idx_transactions_txid',
            'idx_transactions_previous_txid',
            'idx_transactions_memo',
            'idx_transactions_initial_sender',
            'idx_transactions_end_recipient',
            'idx_transactions_sending_recovery'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // API Keys Table Tests
    // =========================================================================

    /**
     * Test api_keys table schema returns valid SQL
     */
    public function testGetApiKeysTableSchemaReturnsValidSql(): void
    {
        $schema = getApiKeysTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS api_keys', $schema);
    }

    /**
     * Test api_keys table schema has required columns
     */
    public function testApiKeysTableSchemaHasRequiredColumns(): void
    {
        $schema = getApiKeysTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'key_id VARCHAR(32) NOT NULL UNIQUE',
            'encrypted_secret JSON NOT NULL',
            'name VARCHAR(255) NOT NULL',
            'permissions JSON NOT NULL',
            'rate_limit_per_minute INT',
            'enabled TINYINT(1)',
            'created_at TIMESTAMP(6)',
            'last_used_at TIMESTAMP(6)',
            'expires_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test api_keys table has required indexes
     */
    public function testApiKeysTableSchemaHasRequiredIndexes(): void
    {
        $schema = getApiKeysTableSchema();

        $expectedIndexes = [
            'idx_api_keys_key_id',
            'idx_api_keys_enabled',
            'idx_api_keys_expires'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // API Request Log Table Tests
    // =========================================================================

    /**
     * Test api_request_log table schema returns valid SQL
     */
    public function testGetApiRequestLogTableSchemaReturnsValidSql(): void
    {
        $schema = getApiRequestLogTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS api_request_log', $schema);
    }

    /**
     * Test api_request_log table schema has required columns
     */
    public function testApiRequestLogTableSchemaHasRequiredColumns(): void
    {
        $schema = getApiRequestLogTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'key_id VARCHAR(32) NOT NULL',
            'endpoint VARCHAR(255) NOT NULL',
            'method VARCHAR(10) NOT NULL',
            'ip_address VARCHAR(45) NOT NULL',
            'request_timestamp TIMESTAMP(6)',
            'response_code INT NOT NULL',
            'response_time_ms INT'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test api_request_log table has required indexes
     */
    public function testApiRequestLogTableSchemaHasRequiredIndexes(): void
    {
        $schema = getApiRequestLogTableSchema();

        $expectedIndexes = [
            'idx_api_log_key_id',
            'idx_api_log_timestamp',
            'idx_api_log_endpoint'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Message Delivery Table Tests
    // =========================================================================

    /**
     * Test message_delivery table schema returns valid SQL
     */
    public function testGetMessageDeliveryTableSchemaReturnsValidSql(): void
    {
        $schema = getMessageDeliveryTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS message_delivery', $schema);
    }

    /**
     * Test message_delivery table schema has required columns
     */
    public function testMessageDeliveryTableSchemaHasRequiredColumns(): void
    {
        $schema = getMessageDeliveryTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'message_type ENUM',
            'message_id VARCHAR(255) NOT NULL',
            'recipient_address VARCHAR(255) NOT NULL',
            'payload JSON',
            'delivery_stage ENUM',
            'retry_count INT',
            'max_retries INT',
            'next_retry_at TIMESTAMP(6)',
            'last_error TEXT',
            'last_response TEXT',
            'created_at TIMESTAMP(6)',
            'updated_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test message_delivery table message_type enum values
     */
    public function testMessageDeliveryTableMessageTypeEnumValues(): void
    {
        $schema = getMessageDeliveryTableSchema();

        $expectedMessageTypeValues = ['transaction', 'p2p', 'rp2p', 'contact'];

        foreach ($expectedMessageTypeValues as $type) {
            $this->assertStringContainsString("'$type'", $schema, "Missing message_type enum value: $type");
        }
    }

    /**
     * Test message_delivery table delivery_stage enum values
     */
    public function testMessageDeliveryTableDeliveryStageEnumValues(): void
    {
        $schema = getMessageDeliveryTableSchema();

        $expectedDeliveryStageValues = [
            'pending',
            'sent',
            'received',
            'inserted',
            'forwarded',
            'completed',
            'failed'
        ];

        foreach ($expectedDeliveryStageValues as $stage) {
            $this->assertStringContainsString("'$stage'", $schema, "Missing delivery_stage enum value: $stage");
        }
    }

    /**
     * Test message_delivery table has required indexes
     */
    public function testMessageDeliveryTableSchemaHasRequiredIndexes(): void
    {
        $schema = getMessageDeliveryTableSchema();

        $expectedIndexes = [
            'idx_delivery_unique',
            'idx_delivery_stage',
            'idx_delivery_retry',
            'idx_delivery_message_type',
            'idx_delivery_created_at'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Dead Letter Queue Table Tests
    // =========================================================================

    /**
     * Test dead_letter_queue table schema returns valid SQL
     */
    public function testGetDeadLetterQueueTableSchemaReturnsValidSql(): void
    {
        $schema = getDeadLetterQueueTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS dead_letter_queue', $schema);
    }

    /**
     * Test dead_letter_queue table schema has required columns
     */
    public function testDeadLetterQueueTableSchemaHasRequiredColumns(): void
    {
        $schema = getDeadLetterQueueTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'message_type ENUM',
            'message_id VARCHAR(255) NOT NULL',
            'payload JSON NOT NULL',
            'recipient_address VARCHAR(255) NOT NULL',
            'retry_count INT',
            'last_retry_at TIMESTAMP(6)',
            'failure_reason TEXT',
            'status ENUM',
            'created_at TIMESTAMP(6)',
            'resolved_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test dead_letter_queue table message_type enum values
     */
    public function testDeadLetterQueueTableMessageTypeEnumValues(): void
    {
        $schema = getDeadLetterQueueTableSchema();

        $expectedMessageTypeValues = ['transaction', 'p2p', 'rp2p', 'contact'];

        foreach ($expectedMessageTypeValues as $type) {
            $this->assertStringContainsString("'$type'", $schema, "Missing message_type enum value: $type");
        }
    }

    /**
     * Test dead_letter_queue table status enum values
     */
    public function testDeadLetterQueueTableStatusEnumValues(): void
    {
        $schema = getDeadLetterQueueTableSchema();

        $expectedStatusValues = ['pending', 'retrying', 'resolved', 'abandoned'];

        foreach ($expectedStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing status enum value: $status");
        }
    }

    /**
     * Test dead_letter_queue table has required indexes
     */
    public function testDeadLetterQueueTableSchemaHasRequiredIndexes(): void
    {
        $schema = getDeadLetterQueueTableSchema();

        $expectedIndexes = [
            'idx_dlq_status',
            'idx_dlq_message_type',
            'idx_dlq_created_at',
            'idx_dlq_status_created'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Delivery Metrics Table Tests
    // =========================================================================

    /**
     * Test delivery_metrics table schema returns valid SQL
     */
    public function testGetDeliveryMetricsTableSchemaReturnsValidSql(): void
    {
        $schema = getDeliveryMetricsTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS delivery_metrics', $schema);
    }

    /**
     * Test delivery_metrics table schema has required columns
     */
    public function testDeliveryMetricsTableSchemaHasRequiredColumns(): void
    {
        $schema = getDeliveryMetricsTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'period_start TIMESTAMP(6) NOT NULL',
            'period_end TIMESTAMP(6) NOT NULL',
            'message_type ENUM',
            'total_sent INT',
            'total_delivered INT',
            'total_failed INT',
            'avg_delivery_time_ms INT',
            'avg_retry_count DECIMAL(5,2)',
            'created_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test delivery_metrics table message_type enum values
     */
    public function testDeliveryMetricsTableMessageTypeEnumValues(): void
    {
        $schema = getDeliveryMetricsTableSchema();

        $expectedMessageTypeValues = ['transaction', 'p2p', 'rp2p', 'contact', 'all'];

        foreach ($expectedMessageTypeValues as $type) {
            $this->assertStringContainsString("'$type'", $schema, "Missing message_type enum value: $type");
        }
    }

    /**
     * Test delivery_metrics table has required indexes
     */
    public function testDeliveryMetricsTableSchemaHasRequiredIndexes(): void
    {
        $schema = getDeliveryMetricsTableSchema();

        $expectedIndexes = [
            'idx_metrics_period',
            'idx_metrics_type',
            'idx_metrics_created'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Rate Limits Table Tests
    // =========================================================================

    /**
     * Test rate_limits table schema returns valid SQL
     */
    public function testGetRateLimitsTableSchemaReturnsValidSql(): void
    {
        $schema = getRateLimitsTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS rate_limits', $schema);
    }

    /**
     * Test rate_limits table schema has required columns
     */
    public function testRateLimitsTableSchemaHasRequiredColumns(): void
    {
        $schema = getRateLimitsTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'identifier VARCHAR(255) NOT NULL',
            'action VARCHAR(100) NOT NULL',
            'attempts INTEGER',
            'first_attempt TIMESTAMP',
            'last_attempt TIMESTAMP',
            'blocked_until TIMESTAMP'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test rate_limits table has required indexes
     */
    public function testRateLimitsTableSchemaHasRequiredIndexes(): void
    {
        $schema = getRateLimitsTableSchema();

        $expectedIndexes = [
            'idx_identifier_action',
            'idx_blocked_until'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // Held Transactions Table Tests
    // =========================================================================

    /**
     * Test held_transactions table schema returns valid SQL
     */
    public function testGetHeldTransactionsTableSchemaReturnsValidSql(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS held_transactions', $schema);
    }

    /**
     * Test held_transactions table schema has required columns
     */
    public function testHeldTransactionsTableSchemaHasRequiredColumns(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $expectedColumns = [
            'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'contact_pubkey_hash VARCHAR(64) NOT NULL',
            'txid VARCHAR(255) NOT NULL',
            'original_previous_txid VARCHAR(255)',
            'expected_previous_txid VARCHAR(255)',
            'transaction_type ENUM',
            'hold_reason ENUM',
            'sync_status ENUM',
            'retry_count INT',
            'max_retries INT',
            'held_at TIMESTAMP(6)',
            'last_sync_attempt TIMESTAMP(6)',
            'next_retry_at TIMESTAMP(6)',
            'resolved_at TIMESTAMP(6)'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertStringContainsString($column, $schema, "Missing column definition: $column");
        }
    }

    /**
     * Test held_transactions table transaction_type enum values
     */
    public function testHeldTransactionsTableTransactionTypeEnumValues(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $expectedTransactionTypeValues = ['standard', 'p2p'];

        foreach ($expectedTransactionTypeValues as $type) {
            $this->assertStringContainsString("'$type'", $schema, "Missing transaction_type enum value: $type");
        }
    }

    /**
     * Test held_transactions table hold_reason enum values
     */
    public function testHeldTransactionsTableHoldReasonEnumValues(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $expectedHoldReasonValues = ['invalid_previous_txid', 'sync_in_progress'];

        foreach ($expectedHoldReasonValues as $reason) {
            $this->assertStringContainsString("'$reason'", $schema, "Missing hold_reason enum value: $reason");
        }
    }

    /**
     * Test held_transactions table sync_status enum values
     */
    public function testHeldTransactionsTableSyncStatusEnumValues(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $expectedSyncStatusValues = ['not_started', 'in_progress', 'completed', 'failed'];

        foreach ($expectedSyncStatusValues as $status) {
            $this->assertStringContainsString("'$status'", $schema, "Missing sync_status enum value: $status");
        }
    }

    /**
     * Test held_transactions table has required indexes
     */
    public function testHeldTransactionsTableSchemaHasRequiredIndexes(): void
    {
        $schema = getHeldTransactionsTableSchema();

        $expectedIndexes = [
            'idx_held_contact',
            'idx_held_txid',
            'idx_held_status',
            'idx_held_contact_status',
            'idx_held_next_retry'
        ];

        foreach ($expectedIndexes as $index) {
            $this->assertStringContainsString($index, $schema, "Missing index: $index");
        }
    }

    // =========================================================================
    // General Schema Tests
    // =========================================================================

    /**
     * Test all schema functions return non-empty strings
     */
    public function testAllSchemaFunctionsReturnNonEmptyStrings(): void
    {
        $schemas = [
            'contacts' => getContactsTableSchema(),
            'addresses' => getAddressTableSchema(),
            'balances' => getBalancesTableSchema(),
            'debug' => getDebugTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'rate_limits' => getRateLimitsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemas as $tableName => $schema) {
            $this->assertIsString($schema, "Schema for $tableName is not a string");
            $this->assertNotEmpty($schema, "Schema for $tableName is empty");
        }
    }

    /**
     * Test all schema functions return CREATE TABLE statements
     */
    public function testAllSchemaFunctionsReturnCreateTableStatements(): void
    {
        $schemas = [
            'contacts' => getContactsTableSchema(),
            'addresses' => getAddressTableSchema(),
            'balances' => getBalancesTableSchema(),
            'debug' => getDebugTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'rate_limits' => getRateLimitsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemas as $tableName => $schema) {
            $this->assertStringContainsString(
                'CREATE TABLE IF NOT EXISTS',
                $schema,
                "Schema for $tableName does not contain CREATE TABLE IF NOT EXISTS"
            );
        }
    }

    /**
     * Test all schema functions return statements with primary key
     */
    public function testAllSchemaFunctionsReturnStatementsWithPrimaryKey(): void
    {
        $schemas = [
            'contacts' => getContactsTableSchema(),
            'addresses' => getAddressTableSchema(),
            'balances' => getBalancesTableSchema(),
            'debug' => getDebugTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'rate_limits' => getRateLimitsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemas as $tableName => $schema) {
            $this->assertStringContainsString(
                'PRIMARY KEY',
                $schema,
                "Schema for $tableName does not contain PRIMARY KEY"
            );
        }
    }

    /**
     * Test schema function count matches expected number of tables
     */
    public function testSchemaFunctionCountMatchesExpectedNumberOfTables(): void
    {
        // There are 14 schema functions defined
        $expectedTableCount = 14;

        $schemas = [
            getContactsTableSchema(),
            getAddressTableSchema(),
            getBalancesTableSchema(),
            getDebugTableSchema(),
            getP2pTableSchema(),
            getRp2pTableSchema(),
            getTransactionsTableSchema(),
            getApiKeysTableSchema(),
            getApiRequestLogTableSchema(),
            getMessageDeliveryTableSchema(),
            getDeadLetterQueueTableSchema(),
            getDeliveryMetricsTableSchema(),
            getRateLimitsTableSchema(),
            getHeldTransactionsTableSchema()
        ];

        $this->assertCount($expectedTableCount, $schemas);
    }

    /**
     * Test schema SQL uses consistent naming conventions for indexes
     */
    public function testSchemaUsesConsistentIndexNamingConventions(): void
    {
        $schemas = [
            'contacts' => getContactsTableSchema(),
            'addresses' => getAddressTableSchema(),
            'balances' => getBalancesTableSchema(),
            'debug' => getDebugTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'rate_limits' => getRateLimitsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemas as $tableName => $schema) {
            // All indexes should start with idx_
            if (preg_match_all('/INDEX\s+(\w+)/', $schema, $matches)) {
                foreach ($matches[1] as $indexName) {
                    $this->assertStringStartsWith(
                        'idx_',
                        $indexName,
                        "Index $indexName in $tableName does not follow idx_ naming convention"
                    );
                }
            }
        }
    }

    /**
     * Test all tables use AUTO_INCREMENT for primary key
     */
    public function testAllTablesUseAutoIncrementForPrimaryKey(): void
    {
        $schemas = [
            'contacts' => getContactsTableSchema(),
            'addresses' => getAddressTableSchema(),
            'balances' => getBalancesTableSchema(),
            'debug' => getDebugTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'rate_limits' => getRateLimitsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemas as $tableName => $schema) {
            $this->assertStringContainsString(
                'AUTO_INCREMENT',
                $schema,
                "Schema for $tableName does not use AUTO_INCREMENT"
            );
        }
    }

    /**
     * Test temporal columns use microsecond precision where appropriate
     */
    public function testTemporalColumnsUseMicrosecondPrecision(): void
    {
        // Tables that should have TIMESTAMP(6) or DATETIME(6) for microsecond precision
        $schemasWithMicrosecondTimestamps = [
            'contacts' => getContactsTableSchema(),
            'p2p' => getP2pTableSchema(),
            'rp2p' => getRp2pTableSchema(),
            'transactions' => getTransactionsTableSchema(),
            'api_keys' => getApiKeysTableSchema(),
            'api_request_log' => getApiRequestLogTableSchema(),
            'message_delivery' => getMessageDeliveryTableSchema(),
            'dead_letter_queue' => getDeadLetterQueueTableSchema(),
            'delivery_metrics' => getDeliveryMetricsTableSchema(),
            'held_transactions' => getHeldTransactionsTableSchema()
        ];

        foreach ($schemasWithMicrosecondTimestamps as $tableName => $schema) {
            $hasTimestamp6 = str_contains($schema, 'TIMESTAMP(6)');
            $hasDatetime6 = str_contains($schema, 'DATETIME(6)');
            $this->assertTrue(
                $hasTimestamp6 || $hasDatetime6,
                "Schema for $tableName should use TIMESTAMP(6) or DATETIME(6) for microsecond precision"
            );
        }
    }

    /**
     * Test debug table uses DATETIME(6) for timestamp column
     */
    public function testDebugTableUsesDatetimeForTimestamp(): void
    {
        $schema = getDebugTableSchema();
        $this->assertStringContainsString('DATETIME(6)', $schema);
    }

    /**
     * Test transactions table uses DATETIME(6) for timestamp columns
     */
    public function testTransactionsTableUsesDatetimeForTimestamps(): void
    {
        $schema = getTransactionsTableSchema();
        $this->assertStringContainsString('DATETIME(6)', $schema);
    }

    // =========================================================================
    // P2P Senders Table Tests
    // =========================================================================

    /**
     * Test p2p_senders table schema returns valid SQL
     */
    public function testGetP2pSendersTableSchemaReturnsValidSql(): void
    {
        $schema = getP2pSendersTableSchema();

        $this->assertIsString($schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS p2p_senders', $schema);
    }

    /**
     * Test p2p_senders table has required columns
     */
    public function testP2pSendersTableHasRequiredColumns(): void
    {
        $schema = getP2pSendersTableSchema();

        $this->assertStringContainsString('hash VARCHAR(255)', $schema);
        $this->assertStringContainsString('sender_address VARCHAR(255)', $schema);
        $this->assertStringContainsString('sender_public_key TEXT', $schema);
        $this->assertStringContainsString('created_at TIMESTAMP(6)', $schema);
    }

    /**
     * Test p2p_senders table has unique index on hash + sender_address
     */
    public function testP2pSendersTableHasUniqueIndex(): void
    {
        $schema = getP2pSendersTableSchema();

        $this->assertStringContainsString('UNIQUE INDEX idx_p2p_senders_hash_addr (hash, sender_address)', $schema);
    }

    /**
     * Test p2p_senders table has required indexes
     */
    public function testP2pSendersTableHasRequiredIndexes(): void
    {
        $schema = getP2pSendersTableSchema();

        $this->assertStringContainsString('INDEX idx_p2p_senders_hash (hash)', $schema);
        $this->assertStringContainsString('INDEX idx_p2p_senders_created_at (created_at)', $schema);
    }
}
