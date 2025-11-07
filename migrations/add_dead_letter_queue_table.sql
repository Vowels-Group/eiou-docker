-- Migration: Add Dead Letter Queue table
-- Date: 2025-11-07
-- Issue: #139 - Dead Letter Queue for Failed Messages
-- Description: Creates dead_letter_queue table for storing messages that failed all retry attempts

-- Create dead_letter_queue table
CREATE TABLE IF NOT EXISTS dead_letter_queue (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    message_type VARCHAR(50) NOT NULL COMMENT 'Type of message (transaction, contact, p2p, etc)',
    sender_address VARCHAR(255) COMMENT 'Address of message sender',
    transaction_hash VARCHAR(255) COMMENT 'Transaction hash if applicable',
    original_message TEXT NOT NULL COMMENT 'Full original message JSON',
    failure_reason VARCHAR(255) NOT NULL COMMENT 'Primary reason for failure',
    last_error TEXT COMMENT 'Last error message received',
    retry_count INT DEFAULT 0 COMMENT 'Number of automatic retry attempts',
    manual_retry_count INT DEFAULT 0 COMMENT 'Number of manual retry attempts',
    status ENUM(
        'failed',      -- Message failed all retry attempts
        'retrying',    -- Manual retry in progress
        'resolved',    -- Successfully retried and processed
        'archived'     -- Manually archived/dismissed
    ) DEFAULT 'failed' COMMENT 'Current status of the DLQ message',
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When message was added to DLQ',
    last_retry_at TIMESTAMP NULL COMMENT 'Last manual retry attempt timestamp',
    resolved_at TIMESTAMP NULL COMMENT 'When message was resolved or archived',
    resolution_notes TEXT COMMENT 'Notes about resolution or archival',

    -- Indexes for efficient querying
    INDEX idx_dlq_status (status),
    INDEX idx_dlq_message_type (message_type),
    INDEX idx_dlq_failed_at (failed_at),
    INDEX idx_dlq_status_failed_at (status, failed_at DESC),
    INDEX idx_dlq_transaction_hash (transaction_hash),
    INDEX idx_dlq_failure_reason (failure_reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dead Letter Queue for messages that failed all retry attempts';

-- Verification query (optional - for testing)
-- SELECT COUNT(*) as table_exists FROM information_schema.tables
-- WHERE table_schema = DATABASE() AND table_name = 'dead_letter_queue';
