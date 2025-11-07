-- Migration: Add message_acknowledgments table for 3-stage ACK protocol
-- Issue: #139 - Transaction Reliability & Message Handling System
-- Created: 2025-11-07
-- Author: Coder Agent #1

-- Create message_acknowledgments table
CREATE TABLE IF NOT EXISTS message_acknowledgments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,

    -- Message Identification
    message_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Deterministic hash of message content',
    message_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of message',
    message_type ENUM('transaction', 'contact', 'p2p', 'util') NOT NULL COMMENT 'Type of message',

    -- Sender/Receiver Information
    sender_address VARCHAR(255) NOT NULL COMMENT 'Sender address (HTTP or Tor)',
    receiver_address VARCHAR(255) NOT NULL COMMENT 'Receiver address (HTTP or Tor)',

    -- Acknowledgment Stages
    stage ENUM('received', 'processed', 'confirmed', 'failed') DEFAULT 'received' COMMENT 'Current ACK stage',

    -- Timestamps for each stage
    received_at TIMESTAMP NULL COMMENT 'Stage 1: Message received timestamp',
    processed_at TIMESTAMP NULL COMMENT 'Stage 2: Message processed and stored timestamp',
    confirmed_at TIMESTAMP NULL COMMENT 'Stage 3: Message confirmed/forwarded timestamp',
    failed_at TIMESTAMP NULL COMMENT 'Failure timestamp if applicable',

    -- Retry Mechanism
    retry_count INT DEFAULT 0 COMMENT 'Number of retry attempts made',
    max_retries INT DEFAULT 5 COMMENT 'Maximum retry attempts allowed',
    next_retry_at TIMESTAMP NULL COMMENT 'Scheduled time for next retry',
    last_retry_at TIMESTAMP NULL COMMENT 'Last retry attempt timestamp',

    -- Failure Tracking
    failure_reason TEXT NULL COMMENT 'Reason for failure if stage=failed',
    is_dead_letter BOOLEAN DEFAULT FALSE COMMENT 'True if moved to dead letter queue',
    dead_letter_at TIMESTAMP NULL COMMENT 'Timestamp when moved to DLQ',

    -- Related Transaction/P2P IDs
    related_txid VARCHAR(255) NULL COMMENT 'Related transaction ID if applicable',
    related_p2p_hash VARCHAR(255) NULL COMMENT 'Related P2P hash if applicable',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Indexes for performance
    INDEX idx_message_id (message_id),
    INDEX idx_message_hash (message_hash),
    INDEX idx_stage (stage),
    INDEX idx_sender_address (sender_address),
    INDEX idx_receiver_address (receiver_address),
    INDEX idx_retry_next (next_retry_at, retry_count),
    INDEX idx_dead_letter (is_dead_letter, dead_letter_at),
    INDEX idx_related_txid (related_txid),
    INDEX idx_related_p2p (related_p2p_hash),
    INDEX idx_created_stage (created_at, stage),
    INDEX idx_type_stage (message_type, stage)
) COMMENT='Tracks 3-stage acknowledgment protocol for message reliability';

-- Add indexes for monitoring queries
CREATE INDEX idx_stage_created ON message_acknowledgments(stage, created_at DESC);
CREATE INDEX idx_failed_reason ON message_acknowledgments(stage, failure_reason(255)) WHERE stage = 'failed';
CREATE INDEX idx_dlq_created ON message_acknowledgments(is_dead_letter, dead_letter_at DESC) WHERE is_dead_letter = TRUE;
