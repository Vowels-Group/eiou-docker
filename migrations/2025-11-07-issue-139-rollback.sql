-- ============================================================================
-- EIOU Message Reliability Rollback - Issue #139
-- ============================================================================
-- Purpose: Safely rollback message reliability enhancements
-- Author: EIOU Development Team
-- Date: 2025-11-07
-- Issue: https://github.com/eiou-org/eiou/issues/139
-- ============================================================================

-- WARNING: This script will permanently delete all message reliability data
-- including acknowledgments, retries, deduplication entries, and dead letter queue items.
-- Make sure to backup your database before running this rollback!

-- ============================================================================
-- ROLLBACK VERIFICATION
-- ============================================================================

-- Check what will be deleted
SELECT 'ROLLBACK PREVIEW' AS action;

SELECT 'message_acknowledgments' AS table_name,
       COUNT(*) AS records_to_delete
FROM message_acknowledgments
UNION ALL
SELECT 'message_retries', COUNT(*)
FROM message_retries
UNION ALL
SELECT 'message_deduplication', COUNT(*)
FROM message_deduplication
UNION ALL
SELECT 'dead_letter_queue', COUNT(*)
FROM dead_letter_queue
UNION ALL
SELECT 'message_reliability_config', COUNT(*)
FROM message_reliability_config;

-- ============================================================================
-- STEP 1: Drop Stored Procedures and Functions
-- ============================================================================

DROP PROCEDURE IF EXISTS cleanup_expired_deduplication;
DROP PROCEDURE IF EXISTS move_to_dead_letter_queue;
DROP FUNCTION IF EXISTS calculate_next_retry;

-- ============================================================================
-- STEP 2: Drop Tables (in reverse dependency order)
-- ============================================================================

-- Drop configuration table (no dependencies)
DROP TABLE IF EXISTS message_reliability_config;

-- Drop message reliability tables (have foreign keys to transactions)
-- Order matters due to potential cross-references
DROP TABLE IF EXISTS message_acknowledgments;
DROP TABLE IF EXISTS message_retries;
DROP TABLE IF EXISTS message_deduplication;
DROP TABLE IF EXISTS dead_letter_queue;

-- ============================================================================
-- STEP 3: Verify Rollback
-- ============================================================================

-- Verify all tables were dropped successfully
SELECT
    'Rollback Complete' AS status,
    (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE()
     AND table_name IN (
         'message_acknowledgments',
         'message_retries',
         'message_deduplication',
         'dead_letter_queue',
         'message_reliability_config'
     )) AS tables_remaining,
    NOW() AS rollback_timestamp;

-- Show remaining tables in database
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name;

-- ============================================================================
-- ROLLBACK NOTES
-- ============================================================================

/*
After running this rollback:

1. All message acknowledgment tracking is removed
2. All retry attempts and scheduling is deleted
3. All deduplication entries are removed
4. All dead letter queue items are lost
5. Helper procedures and functions are dropped

To restore message reliability features:
- Re-run the forward migration: 2025-11-07-issue-139-message-reliability.sql

Data Loss:
- This rollback permanently deletes all message reliability tracking data
- Transaction data in the main transactions table is NOT affected
- No impact on existing transactions, contacts, or p2p entries

Backup Recommendation:
Before running rollback, create a backup:
  mysqldump -u root -proot eiou > backup_before_rollback_$(date +%Y%m%d_%H%M%S).sql
*/

-- ============================================================================
-- END OF ROLLBACK
-- ============================================================================
