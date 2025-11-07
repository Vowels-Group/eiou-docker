-- Migration: Add message_deduplication table
-- Purpose: Prevent duplicate message processing through fingerprinting
-- Issue: #139 - Duplicate message detection
-- Created: 2025-11-07

CREATE TABLE IF NOT EXISTS message_deduplication (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    fingerprint VARCHAR(64) NOT NULL UNIQUE,
    message_type ENUM('p2p', 'rp2p', 'transaction', 'contact', 'other') NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes for performance
    INDEX idx_fingerprint_expires (fingerprint, expires_at),
    INDEX idx_expires_at (expires_at),
    INDEX idx_message_type (message_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Message deduplication fingerprints';

-- Note: Cleanup of expired records should be handled by CleanupService or cron job
-- Example cleanup query: DELETE FROM message_deduplication WHERE expires_at <= NOW();
