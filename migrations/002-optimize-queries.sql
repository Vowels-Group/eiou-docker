-- Migration: Query Optimization and Database Tuning
-- Issue: #146 - Security Hardening Phase
-- Sub-issue: #52 - Database Query Performance Optimization
-- Date: 2025-11-07
-- Description: Additional database optimizations and query performance tuning

-- =============================================================================
-- PART 1: ANALYZE TABLES (Update Query Optimizer Statistics)
-- =============================================================================

-- Analyze all tables to update statistics after index creation
-- This helps the MySQL query optimizer make better execution plan decisions

ANALYZE TABLE contacts;
ANALYZE TABLE transactions;
ANALYZE TABLE p2p;
ANALYZE TABLE rp2p;
ANALYZE TABLE debug;

-- =============================================================================
-- PART 2: OPTIMIZE TABLES (Defragment and Rebuild)
-- =============================================================================

-- IMPORTANT: These operations may take several minutes and lock tables
-- Schedule during maintenance window or low-traffic period

-- Optimize contacts table (rebuild indexes, reclaim space)
-- OPTIMIZE TABLE contacts;

-- Optimize transactions table (rebuild indexes, reclaim space)
-- OPTIMIZE TABLE transactions;

-- Optimize p2p table (rebuild indexes, reclaim space)
-- OPTIMIZE TABLE p2p;

-- Optimize rp2p table (rebuild indexes, reclaim space)
-- OPTIMIZE TABLE rp2p;

-- Note: OPTIMIZE TABLE is commented out by default
-- Uncomment and run manually during maintenance window

-- =============================================================================
-- PART 3: DATABASE CONFIGURATION TUNING
-- =============================================================================

-- These settings improve query performance but may require MySQL restart
-- Add to my.cnf or mysqld.conf and restart MySQL:

-- # Increase query cache size (if query cache is enabled)
-- query_cache_size = 64M
-- query_cache_limit = 2M
--
-- # Increase InnoDB buffer pool (set to 70-80% of available RAM)
-- innodb_buffer_pool_size = 1G
--
-- # Increase join buffer size for complex queries
-- join_buffer_size = 8M
--
-- # Increase sort buffer size for ORDER BY queries
-- sort_buffer_size = 4M
--
-- # Enable slow query log for performance monitoring
-- slow_query_log = 1
-- slow_query_log_file = /var/log/mysql/slow-query.log
-- long_query_time = 1  # Log queries taking more than 1 second
--
-- # Log queries not using indexes (for optimization)
-- log_queries_not_using_indexes = 1

-- =============================================================================
-- PART 4: CREATE MONITORING VIEWS
-- =============================================================================

-- Create view for slow query monitoring
CREATE OR REPLACE VIEW v_slow_queries AS
SELECT
    DIGEST_TEXT as query_pattern,
    SCHEMA_NAME as database_name,
    COUNT_STAR as execution_count,
    ROUND(AVG_TIMER_WAIT/1000000000, 2) as avg_time_ms,
    ROUND(MAX_TIMER_WAIT/1000000000, 2) as max_time_ms,
    ROUND(SUM_TIMER_WAIT/1000000000000, 2) as total_time_sec,
    ROUND(AVG_ROWS_EXAMINED, 0) as avg_rows_examined,
    ROUND(AVG_ROWS_SENT, 0) as avg_rows_sent,
    FIRST_SEEN,
    LAST_SEEN
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME = 'eiou'
  AND AVG_TIMER_WAIT > 50000000  -- Queries taking more than 50ms
ORDER BY total_time_sec DESC;

-- Create view for index usage statistics
CREATE OR REPLACE VIEW v_index_usage AS
SELECT
    object_name as table_name,
    index_name,
    count_star as queries_using_index,
    count_read as index_reads,
    count_write as index_writes,
    ROUND(count_star / NULLIF((
        SELECT SUM(count_star)
        FROM performance_schema.table_io_waits_summary_by_index_usage
        WHERE object_schema = 'eiou' AND object_name = t.object_name
    ), 0) * 100, 2) as usage_percent
FROM performance_schema.table_io_waits_summary_by_index_usage t
WHERE object_schema = 'eiou'
  AND index_name IS NOT NULL
  AND index_name != 'PRIMARY'
ORDER BY queries_using_index DESC;

-- Create view for unused indexes (candidates for removal)
CREATE OR REPLACE VIEW v_unused_indexes AS
SELECT
    object_name as table_name,
    index_name,
    'UNUSED - Consider dropping' as recommendation
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = 'eiou'
  AND index_name IS NOT NULL
  AND index_name != 'PRIMARY'
  AND count_star = 0
ORDER BY object_name, index_name;

-- Create view for table size statistics
CREATE OR REPLACE VIEW v_table_stats AS
SELECT
    TABLE_NAME as table_name,
    ENGINE as engine,
    TABLE_ROWS as estimated_rows,
    ROUND(DATA_LENGTH / 1024 / 1024, 2) as data_size_mb,
    ROUND(INDEX_LENGTH / 1024 / 1024, 2) as index_size_mb,
    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as total_size_mb,
    ROUND(INDEX_LENGTH / NULLIF(DATA_LENGTH, 0) * 100, 2) as index_overhead_percent,
    TABLE_COLLATION as collation,
    CREATE_TIME as created_at,
    UPDATE_TIME as last_updated
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'eiou'
  AND TABLE_TYPE = 'BASE TABLE'
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC;

-- Create view for database health check
CREATE OR REPLACE VIEW v_db_health_check AS
SELECT
    'Query Performance' as check_category,
    CASE
        WHEN MAX(ROUND(AVG_TIMER_WAIT/1000000000, 2)) > 1000 THEN 'CRITICAL'
        WHEN MAX(ROUND(AVG_TIMER_WAIT/1000000000, 2)) > 100 THEN 'WARNING'
        ELSE 'OK'
    END as status,
    CONCAT('Slowest avg query: ', MAX(ROUND(AVG_TIMER_WAIT/1000000000, 2)), 'ms') as details
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME = 'eiou'

UNION ALL

SELECT
    'Index Usage' as check_category,
    CASE
        WHEN COUNT(*) > 0 THEN 'WARNING'
        ELSE 'OK'
    END as status,
    CONCAT(COUNT(*), ' unused indexes found') as details
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = 'eiou'
  AND index_name IS NOT NULL
  AND index_name != 'PRIMARY'
  AND count_star = 0

UNION ALL

SELECT
    'Table Size' as check_category,
    CASE
        WHEN MAX((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024) > 1000 THEN 'WARNING'
        ELSE 'OK'
    END as status,
    CONCAT('Largest table: ', MAX(ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)), 'MB') as details
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'eiou';

-- =============================================================================
-- PART 5: QUERY OPTIMIZATION EXAMPLES
-- =============================================================================

-- Example 1: Optimized contact lookup using UNION instead of OR
-- This allows MySQL to use both indexes instead of just one

-- OLD QUERY (slower - can only use one index):
-- SELECT name, http, tor, pubkey FROM contacts
-- WHERE http = 'address' OR tor = 'address';

-- NEW QUERY (faster - uses both indexes):
-- (SELECT name, http, tor, pubkey FROM contacts WHERE http = 'address')
-- UNION
-- (SELECT name, http, tor, pubkey FROM contacts WHERE tor = 'address')
-- LIMIT 1;

-- Example 2: Optimized transaction history with proper index usage

-- OLD QUERY (slower - may cause full table scan):
-- SELECT * FROM transactions
-- WHERE sender_address = 'addr1' OR receiver_address = 'addr1'
-- ORDER BY timestamp DESC LIMIT 10;

-- NEW QUERY (faster - uses indexes):
-- (SELECT * FROM transactions WHERE sender_address = 'addr1')
-- UNION
-- (SELECT * FROM transactions WHERE receiver_address = 'addr1')
-- ORDER BY timestamp DESC LIMIT 10;

-- Example 3: Batch contact lookup to avoid N+1 queries

-- OLD APPROACH (N+1 problem - 100 queries for 100 contacts):
-- foreach contact:
--   SELECT * FROM contacts WHERE http = 'address' OR tor = 'address';

-- NEW APPROACH (single query for all contacts):
-- SELECT * FROM contacts
-- WHERE http IN ('addr1', 'addr2', 'addr3', ...)
--    OR tor IN ('addr1', 'addr2', 'addr3', ...);

-- =============================================================================
-- PART 6: PERFORMANCE TESTING QUERIES
-- =============================================================================

-- Test query performance after optimization
-- These queries should be fast after index creation

-- Test 1: Transaction history query (Target: < 100ms)
EXPLAIN SELECT sender_address, receiver_address, amount, currency, timestamp
FROM transactions
WHERE sender_address IN ('test_addr_1', 'test_addr_2')
   OR receiver_address IN ('test_addr_1', 'test_addr_2')
ORDER BY timestamp DESC
LIMIT 10;

-- Test 2: Contact lookup query (Target: < 50ms)
EXPLAIN SELECT name, http, tor, pubkey, fee_percent, status
FROM contacts
WHERE http = 'test_address' OR tor = 'test_address';

-- Test 3: Balance calculation query (Target: < 10ms)
EXPLAIN SELECT COALESCE(SUM(amount), 0) as balance
FROM transactions
WHERE sender_public_key_hash = 'test_hash'
  AND receiver_public_key_hash = 'test_hash';

-- Test 4: Contact search query (Target: < 50ms)
EXPLAIN SELECT * FROM contacts
WHERE name LIKE '%test%' AND status = 'accepted';

-- Test 5: P2P credit calculation query (Target: < 20ms)
EXPLAIN SELECT SUM(amount) as total_amount
FROM p2p
WHERE sender_address = 'test_address'
  AND status IN ('initial', 'queued', 'sent', 'found');

-- =============================================================================
-- PART 7: MAINTENANCE PROCEDURES
-- =============================================================================

-- Procedure to identify slow queries
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS sp_identify_slow_queries(IN min_time_ms INT)
BEGIN
    SELECT
        DIGEST_TEXT as query_pattern,
        ROUND(AVG_TIMER_WAIT/1000000000, 2) as avg_time_ms,
        COUNT_STAR as execution_count,
        ROUND(MAX_TIMER_WAIT/1000000000, 2) as max_time_ms,
        LAST_SEEN
    FROM performance_schema.events_statements_summary_by_digest
    WHERE SCHEMA_NAME = 'eiou'
      AND AVG_TIMER_WAIT/1000000000 > min_time_ms
    ORDER BY avg_time_ms DESC
    LIMIT 20;
END$$
DELIMITER ;

-- Procedure to check index health
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS sp_check_index_health()
BEGIN
    -- Show indexes with low usage
    SELECT
        object_name as table_name,
        index_name,
        count_star as usage_count,
        CASE
            WHEN count_star = 0 THEN 'UNUSED - Consider dropping'
            WHEN count_star < 100 THEN 'LOW USAGE - Review'
            ELSE 'ACTIVE'
        END as recommendation
    FROM performance_schema.table_io_waits_summary_by_index_usage
    WHERE object_schema = 'eiou'
      AND index_name IS NOT NULL
      AND index_name != 'PRIMARY'
    ORDER BY count_star ASC;
END$$
DELIMITER ;

-- Procedure to analyze query performance
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS sp_analyze_query_performance()
BEGIN
    -- Show query performance summary
    SELECT
        'Slowest Queries' as report_section,
        DIGEST_TEXT as query_pattern,
        ROUND(AVG_TIMER_WAIT/1000000000, 2) as avg_time_ms,
        COUNT_STAR as executions
    FROM performance_schema.events_statements_summary_by_digest
    WHERE SCHEMA_NAME = 'eiou'
    ORDER BY AVG_TIMER_WAIT DESC
    LIMIT 10;

    -- Show most frequent queries
    SELECT
        'Most Frequent Queries' as report_section,
        DIGEST_TEXT as query_pattern,
        COUNT_STAR as executions,
        ROUND(AVG_TIMER_WAIT/1000000000, 2) as avg_time_ms
    FROM performance_schema.events_statements_summary_by_digest
    WHERE SCHEMA_NAME = 'eiou'
    ORDER BY COUNT_STAR DESC
    LIMIT 10;
END$$
DELIMITER ;

-- =============================================================================
-- PART 8: USAGE EXAMPLES FOR MONITORING VIEWS
-- =============================================================================

-- Check slow queries:
-- SELECT * FROM v_slow_queries LIMIT 10;

-- Check index usage:
-- SELECT * FROM v_index_usage WHERE usage_percent < 1.0;

-- Find unused indexes:
-- SELECT * FROM v_unused_indexes;

-- Check table sizes:
-- SELECT * FROM v_table_stats;

-- Database health check:
-- SELECT * FROM v_db_health_check;

-- Run stored procedures:
-- CALL sp_identify_slow_queries(100);  -- Find queries slower than 100ms
-- CALL sp_check_index_health();
-- CALL sp_analyze_query_performance();

-- =============================================================================
-- PART 9: VERIFICATION QUERIES
-- =============================================================================

-- Verify all indexes exist
SELECT
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'eiou'
  AND INDEX_NAME IN (
    'idx_contacts_http',
    'idx_transactions_sender_address',
    'idx_transactions_receiver_address',
    'idx_transactions_sender_timestamp',
    'idx_transactions_receiver_timestamp',
    'idx_transactions_balance_calc',
    'idx_contacts_name_status',
    'idx_contacts_http_status'
  )
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- Check index sizes and row estimates
SELECT
    t.TABLE_NAME,
    t.TABLE_ROWS as estimated_rows,
    ROUND(t.DATA_LENGTH / 1024 / 1024, 2) as data_mb,
    ROUND(t.INDEX_LENGTH / 1024 / 1024, 2) as index_mb,
    COUNT(s.INDEX_NAME) as index_count
FROM information_schema.TABLES t
LEFT JOIN information_schema.STATISTICS s
    ON t.TABLE_NAME = s.TABLE_NAME AND t.TABLE_SCHEMA = s.TABLE_SCHEMA
WHERE t.TABLE_SCHEMA = 'eiou'
  AND t.TABLE_TYPE = 'BASE TABLE'
GROUP BY t.TABLE_NAME
ORDER BY t.TABLE_NAME;

-- =============================================================================
-- ROLLBACK SCRIPT
-- =============================================================================

-- To rollback monitoring views and procedures:
-- DROP VIEW IF EXISTS v_slow_queries;
-- DROP VIEW IF EXISTS v_index_usage;
-- DROP VIEW IF EXISTS v_unused_indexes;
-- DROP VIEW IF EXISTS v_table_stats;
-- DROP VIEW IF EXISTS v_db_health_check;
-- DROP PROCEDURE IF EXISTS sp_identify_slow_queries;
-- DROP PROCEDURE IF EXISTS sp_check_index_health;
-- DROP PROCEDURE IF EXISTS sp_analyze_query_performance;

-- =============================================================================
-- MIGRATION COMPLETE
-- =============================================================================

-- Next steps:
-- 1. Monitor performance for 24-48 hours
-- 2. Review v_slow_queries view daily
-- 3. Check v_unused_indexes weekly
-- 4. Run sp_analyze_query_performance() monthly
-- 5. Schedule OPTIMIZE TABLE during next maintenance window

-- Expected results:
-- - All queries should meet performance targets
-- - No full table scans on large tables
-- - Index usage > 90% on critical indexes
-- - Zero unused indexes after optimization period

-- End of optimization migration
