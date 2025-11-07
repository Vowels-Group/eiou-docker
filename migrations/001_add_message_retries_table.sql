-- Migration: Add message_retries table for retry mechanism with exponential backoff
-- Issue: #139 - Retry Mechanism with Exponential Backoff
-- Created: 2025-11-07

-- Create message_retries table
CREATE TABLE IF NOT EXISTS message_retries (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,

    -- Message identification
    message_id VARCHAR(255) NOT NULL,
    message_type ENUM('transaction', 'p2p', 'rp2p') NOT NULL,
    recipient_address VARCHAR(255) NOT NULL,

    -- Retry tracking
    attempt_number INTEGER NOT NULL DEFAULT 0,
    status ENUM(
        'scheduled',  -- Retry is scheduled for future
        'sent',       -- Retry attempt was sent
        'failed',     -- Retry permanently failed
        'completed'   -- Message successfully delivered
    ) DEFAULT 'scheduled',

    -- Error tracking
    error_message TEXT,

    -- Timing
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    next_retry_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,

    -- Indexes for performance
    INDEX idx_message_retries_message_id (message_id),
    INDEX idx_message_retries_status (status),
    INDEX idx_message_retries_next_retry (next_retry_at),
    INDEX idx_message_retries_recipient (recipient_address),
    INDEX idx_message_retries_type (message_type),
    INDEX idx_message_retries_status_next_retry (status, next_retry_at),
    INDEX idx_message_retries_message_attempt (message_id, attempt_number DESC),
    INDEX idx_message_retries_recipient_created (recipient_address, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment to table
ALTER TABLE message_retries COMMENT='Tracks retry attempts for failed messages with exponential backoff';
