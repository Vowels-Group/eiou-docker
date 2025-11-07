-- ============================================================================
-- EIOU Message Reliability Enhancement - Issue #139
-- ============================================================================
-- Purpose: Add comprehensive message acknowledgment, retry, deduplication,
--          and dead letter queue functionality to ensure transaction reliability
-- Author: EIOU Development Team
-- Date: 2025-11-07
-- Issue: https://github.com/eiou-org/eiou/issues/139
-- ============================================================================

-- ============================================================================
-- 1. MESSAGE ACKNOWLEDGMENTS TABLE
-- ============================================================================
-- Purpose: Track acknowledgment status for all transaction messages
-- Supports: 3-stage acknowledgment (received, processing, completed)

CREATE TABLE IF NOT EXISTS message_acknowledgments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Message identification
    txid VARCHAR(255) NOT NULL,
    message_hash VARCHAR(64) NOT NULL UNIQUE,

    -- Acknowledgment tracking
    ack_stage ENUM(
        'none',         -- No acknowledgment received yet
        'received',     -- Message received by recipient
        'processing',   -- Message being processed
        'completed'     -- Transaction completed successfully
    ) NOT NULL DEFAULT 'none',

    -- Sender/receiver information
    sender_address VARCHAR(255) NOT NULL,
    receiver_address VARCHAR(255) NOT NULL,

    -- Status tracking
    status ENUM(
        'pending',      -- Waiting for acknowledgment
        'acked',        -- Acknowledgment received
        'timeout',      -- Acknowledgment timeout
        'failed'        -- Acknowledgment failed
    ) NOT NULL DEFAULT 'pending',

    -- Timing information
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ack_received_at TIMESTAMP NULL,
    ack_timeout_at TIMESTAMP NULL,

    -- Acknowledgment data
    ack_payload JSON,

    -- Metadata
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key to transactions table
    CONSTRAINT fk_message_ack_txid
        FOREIGN KEY (txid)
        REFERENCES transactions(txid)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Indexes for performance
    INDEX idx_ack_txid (txid),
    INDEX idx_ack_message_hash (message_hash),
    INDEX idx_ack_status (status),
    INDEX idx_ack_stage (ack_stage),
    INDEX idx_ack_sender (sender_address),
    INDEX idx_ack_receiver (receiver_address),
    INDEX idx_ack_timeout (ack_timeout_at),
    INDEX idx_ack_status_timeout (status, ack_timeout_at),
    INDEX idx_ack_created_at (created_at),
    INDEX idx_ack_sender_receiver (sender_address, receiver_address),
    INDEX idx_ack_composite (status, ack_stage, ack_timeout_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. MESSAGE RETRIES TABLE
-- ============================================================================
-- Purpose: Track retry attempts for failed message deliveries
-- Supports: Exponential backoff and retry limit enforcement

CREATE TABLE IF NOT EXISTS message_retries (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Message identification
    txid VARCHAR(255) NOT NULL,
    message_hash VARCHAR(64) NOT NULL,

    -- Retry tracking
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_retries INT UNSIGNED NOT NULL DEFAULT 5,

    -- Backoff strategy
    backoff_strategy ENUM(
        'linear',       -- Fixed delay between retries
        'exponential',  -- Exponentially increasing delay
        'fibonacci'     -- Fibonacci sequence delay
    ) NOT NULL DEFAULT 'exponential',

    base_delay_seconds INT UNSIGNED NOT NULL DEFAULT 30,
    next_retry_at TIMESTAMP NULL,

    -- Status tracking
    retry_status ENUM(
        'scheduled',    -- Retry scheduled
        'in_progress',  -- Retry in progress
        'succeeded',    -- Retry succeeded
        'failed',       -- Retry failed
        'exhausted'     -- Max retries reached
    ) NOT NULL DEFAULT 'scheduled',

    -- Failure tracking
    last_error_message TEXT,
    last_error_code VARCHAR(50),
    last_retry_at TIMESTAMP NULL,

    -- Original message data (for retry)
    original_message JSON NOT NULL,

    -- Metadata
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key to transactions table
    CONSTRAINT fk_retry_txid
        FOREIGN KEY (txid)
        REFERENCES transactions(txid)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Indexes for performance
    INDEX idx_retry_txid (txid),
    INDEX idx_retry_message_hash (message_hash),
    INDEX idx_retry_status (retry_status),
    INDEX idx_retry_next_at (next_retry_at),
    INDEX idx_retry_status_next (retry_status, next_retry_at),
    INDEX idx_retry_count (retry_count),
    INDEX idx_retry_created_at (created_at),
    INDEX idx_retry_composite (retry_status, next_retry_at, retry_count)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. MESSAGE DEDUPLICATION TABLE
-- ============================================================================
-- Purpose: Prevent duplicate message processing using idempotency keys
-- Supports: Configurable TTL for deduplication entries

CREATE TABLE IF NOT EXISTS message_deduplication (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Deduplication key
    message_hash VARCHAR(64) NOT NULL UNIQUE,
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,

    -- Message identification
    txid VARCHAR(255) NOT NULL,

    -- Sender/receiver information
    sender_address VARCHAR(255) NOT NULL,
    receiver_address VARCHAR(255) NOT NULL,

    -- Processing status
    processing_status ENUM(
        'processing',   -- Message currently being processed
        'completed',    -- Message processing completed
        'failed'        -- Message processing failed
    ) NOT NULL DEFAULT 'processing',

    -- Result data
    result_payload JSON,

    -- TTL management
    expires_at TIMESTAMP NOT NULL,

    -- Metadata
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key to transactions table
    CONSTRAINT fk_dedup_txid
        FOREIGN KEY (txid)
        REFERENCES transactions(txid)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Indexes for performance
    INDEX idx_dedup_message_hash (message_hash),
    INDEX idx_dedup_idempotency (idempotency_key),
    INDEX idx_dedup_txid (txid),
    INDEX idx_dedup_status (processing_status),
    INDEX idx_dedup_expires (expires_at),
    INDEX idx_dedup_sender (sender_address),
    INDEX idx_dedup_receiver (receiver_address),
    INDEX idx_dedup_created_at (created_at),
    INDEX idx_dedup_composite (processing_status, expires_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. DEAD LETTER QUEUE TABLE
-- ============================================================================
-- Purpose: Store messages that failed after all retry attempts
-- Supports: Manual review and reprocessing of failed messages

CREATE TABLE IF NOT EXISTS dead_letter_queue (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Message identification
    txid VARCHAR(255) NOT NULL,
    message_hash VARCHAR(64) NOT NULL,

    -- Original message data
    original_message JSON NOT NULL,

    -- Failure information
    failure_reason TEXT NOT NULL,
    failure_code VARCHAR(50),
    failure_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Retry history
    total_retry_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_retry_at TIMESTAMP NULL,

    -- Queue status
    dlq_status ENUM(
        'queued',       -- In dead letter queue
        'investigating',-- Being investigated
        'reprocessing', -- Being reprocessed
        'resolved',     -- Successfully resolved
        'archived'      -- Permanently failed, archived
    ) NOT NULL DEFAULT 'queued',

    -- Resolution tracking
    resolved_at TIMESTAMP NULL,
    resolved_by VARCHAR(255),
    resolution_notes TEXT,

    -- Sender/receiver information
    sender_address VARCHAR(255) NOT NULL,
    receiver_address VARCHAR(255) NOT NULL,

    -- Metadata
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key to transactions table
    CONSTRAINT fk_dlq_txid
        FOREIGN KEY (txid)
        REFERENCES transactions(txid)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Indexes for performance
    INDEX idx_dlq_txid (txid),
    INDEX idx_dlq_message_hash (message_hash),
    INDEX idx_dlq_status (dlq_status),
    INDEX idx_dlq_failure_timestamp (failure_timestamp),
    INDEX idx_dlq_sender (sender_address),
    INDEX idx_dlq_receiver (receiver_address),
    INDEX idx_dlq_created_at (created_at),
    INDEX idx_dlq_composite (dlq_status, failure_timestamp),
    INDEX idx_dlq_resolved (resolved_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. HELPER FUNCTIONS AND PROCEDURES
-- ============================================================================

-- Procedure to clean up expired deduplication entries
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS cleanup_expired_deduplication()
BEGIN
    DELETE FROM message_deduplication
    WHERE expires_at < NOW()
    AND processing_status = 'completed';
END$$

-- Procedure to move exhausted retries to dead letter queue
CREATE PROCEDURE IF NOT EXISTS move_to_dead_letter_queue()
BEGIN
    INSERT INTO dead_letter_queue (
        txid,
        message_hash,
        original_message,
        failure_reason,
        failure_code,
        total_retry_attempts,
        last_retry_at,
        sender_address,
        receiver_address
    )
    SELECT
        mr.txid,
        mr.message_hash,
        mr.original_message,
        COALESCE(mr.last_error_message, 'Max retries exhausted'),
        mr.last_error_code,
        mr.retry_count,
        mr.last_retry_at,
        t.sender_address,
        t.receiver_address
    FROM message_retries mr
    INNER JOIN transactions t ON mr.txid = t.txid
    WHERE mr.retry_status = 'exhausted'
    AND NOT EXISTS (
        SELECT 1 FROM dead_letter_queue dlq
        WHERE dlq.txid = mr.txid
        AND dlq.message_hash = mr.message_hash
    );

    -- Clean up moved entries from retries table
    DELETE FROM message_retries
    WHERE retry_status = 'exhausted'
    AND EXISTS (
        SELECT 1 FROM dead_letter_queue dlq
        WHERE dlq.txid = message_retries.txid
        AND dlq.message_hash = message_retries.message_hash
    );
END$$

-- Function to calculate next retry timestamp with exponential backoff
CREATE FUNCTION IF NOT EXISTS calculate_next_retry(
    retry_count INT,
    base_delay INT,
    strategy VARCHAR(20)
)
RETURNS TIMESTAMP
DETERMINISTIC
BEGIN
    DECLARE delay_seconds INT;

    IF strategy = 'linear' THEN
        SET delay_seconds = base_delay;
    ELSEIF strategy = 'fibonacci' THEN
        -- Simplified Fibonacci: 1, 1, 2, 3, 5, 8, 13, 21...
        SET delay_seconds = base_delay * CASE retry_count
            WHEN 0 THEN 1
            WHEN 1 THEN 1
            WHEN 2 THEN 2
            WHEN 3 THEN 3
            WHEN 4 THEN 5
            WHEN 5 THEN 8
            ELSE 13
        END;
    ELSE  -- exponential (default)
        SET delay_seconds = base_delay * POWER(2, retry_count);
    END IF;

    -- Cap at 24 hours
    IF delay_seconds > 86400 THEN
        SET delay_seconds = 86400;
    END IF;

    RETURN TIMESTAMPADD(SECOND, delay_seconds, NOW());
END$$

DELIMITER ;

-- ============================================================================
-- 6. INITIAL DATA AND CONFIGURATION
-- ============================================================================

-- Create a configuration table for message reliability settings
CREATE TABLE IF NOT EXISTS message_reliability_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default configuration values
INSERT INTO message_reliability_config (config_key, config_value, description) VALUES
('ack_timeout_seconds', '300', 'Default acknowledgment timeout in seconds'),
('max_retry_attempts', '5', 'Maximum number of retry attempts'),
('retry_base_delay', '30', 'Base delay in seconds for retry backoff'),
('retry_strategy', 'exponential', 'Default retry backoff strategy'),
('dedup_ttl_seconds', '3600', 'Deduplication entry TTL in seconds (1 hour)'),
('dlq_auto_archive_days', '30', 'Days before auto-archiving dead letter items')
ON DUPLICATE KEY UPDATE
    config_value = VALUES(config_value),
    description = VALUES(description);

-- ============================================================================
-- 7. MIGRATION VERIFICATION
-- ============================================================================

-- Verify all tables were created successfully
SELECT
    'Migration Complete' AS status,
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE()
     AND table_name IN (
         'message_acknowledgments',
         'message_retries',
         'message_deduplication',
         'dead_letter_queue',
         'message_reliability_config'
     )) AS tables_created,
    NOW() AS migration_timestamp;

-- Show table sizes
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
AND table_name IN (
    'message_acknowledgments',
    'message_retries',
    'message_deduplication',
    'dead_letter_queue',
    'message_reliability_config'
)
ORDER BY table_name;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
