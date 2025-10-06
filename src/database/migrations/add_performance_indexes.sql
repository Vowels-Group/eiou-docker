-- Migration: Add performance indexes to optimize query execution
-- Issue: #52 - Add database indexes for improved performance
-- Date: 2025-10-03

-- ============================================
-- p2p table indexes
-- ============================================

-- Index for status filtering (frequently used in WHERE clauses)
ALTER TABLE p2p ADD INDEX idx_status (status);

-- Index for timestamp ordering (used in ORDER BY)
ALTER TABLE p2p ADD INDEX idx_created_at (created_at);

-- Index for sender_address lookups
ALTER TABLE p2p ADD INDEX idx_sender_address (sender_address);

-- Composite index for common query pattern: WHERE status = ? ORDER BY created_at
ALTER TABLE p2p ADD INDEX idx_status_created_at (status, created_at);

-- Index for hash lookups (if not already unique)
-- Note: hash already has UNIQUE constraint which acts as an index

-- ============================================
-- transactions table indexes
-- ============================================

-- Index for status filtering (pending transactions query)
ALTER TABLE transactions ADD INDEX idx_status (status);

-- Index for timestamp ordering
ALTER TABLE transactions ADD INDEX idx_timestamp (timestamp);

-- Index for previous_txid lookups (transaction chain queries)
ALTER TABLE transactions ADD INDEX idx_previous_txid (previous_txid);

-- Index for memo field searches
ALTER TABLE transactions ADD INDEX idx_memo (memo(255));

-- Composite index for pending transaction queries: WHERE status = 'pending' ORDER BY timestamp
ALTER TABLE transactions ADD INDEX idx_status_timestamp (status, timestamp);

-- Composite index for balance queries (sender/receiver combinations)
ALTER TABLE transactions ADD INDEX idx_sender_receiver (sender_public_key_hash, receiver_public_key_hash);

-- ============================================
-- contacts table indexes
-- ============================================

-- Index for status filtering
ALTER TABLE contacts ADD INDEX idx_status (status);

-- Composite index for status-based queries with address
ALTER TABLE contacts ADD INDEX idx_status_address (status, address);

-- Index for name lookups (NULL/NOT NULL checks)
ALTER TABLE contacts ADD INDEX idx_name (name);

-- ============================================
-- rp2p table indexes
-- ============================================

-- Index for timestamp ordering
ALTER TABLE rp2p ADD INDEX idx_created_at (created_at);

-- Index for sender_address if needed for queries
ALTER TABLE rp2p ADD INDEX idx_sender_address (sender_address);

-- ============================================
-- debug table indexes (if needed for monitoring)
-- ============================================

-- Index for timestamp-based log queries
ALTER TABLE debug ADD INDEX idx_timestamp (timestamp);

-- Index for error level filtering
ALTER TABLE debug ADD INDEX idx_level (level);

-- Composite index for time-based error filtering
ALTER TABLE debug ADD INDEX idx_level_timestamp (level, timestamp);