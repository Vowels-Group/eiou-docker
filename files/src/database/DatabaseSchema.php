<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

// ============================================================================
// CONTACTS & NETWORK
// Tables related to contact management, network addresses, credit limits, and balances
// ============================================================================

// Contacts table
function getContactsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_id VARCHAR(128) NOT NULL UNIQUE,
        pubkey TEXT NOT NULL,
        pubkey_hash VARCHAR(64),
        name VARCHAR(255),
        status ENUM(
            'pending',  /* Contact request Created */
            'accepted', /* Contact request Accepted */
            'blocked'   /* Contact request Blocked */
        ) DEFAULT 'pending',
        online_status ENUM(
            'online',   /* Contact responded to ping, all processors running */
            'partial',  /* Contact responded to ping, some processors degraded */
            'offline',  /* Contact did not respond to ping */
            'unknown'   /* Ping not performed (default or feature disabled) */
        ) DEFAULT 'unknown',
        valid_chain TINYINT(1) DEFAULT NULL, /* true/false if chain sync was validated, NULL if not checked */
        remote_version VARCHAR(32) DEFAULT NULL, /* Remote node's self-reported APP_VERSION from message envelope */
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        last_ping_at TIMESTAMP(6) NULL, /* When the contact was last pinged */
        INDEX idx_contacts_contact_id (contact_id),
        INDEX idx_contacts_pubkey_hash (pubkey_hash),
        INDEX idx_contacts_name (name),
        INDEX idx_contacts_status (status),
        INDEX idx_contacts_pubkey_hash_status (pubkey_hash, status),
        INDEX idx_contacts_online_status (online_status)
    )";
}

// Address table
function getAddressTableSchema(){
    return "CREATE TABLE IF NOT EXISTS addresses (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        pubkey_hash TEXT NOT NULL,
        http VARCHAR(255) UNIQUE DEFAULT NULL,
        https VARCHAR(255) UNIQUE DEFAULT NULL,
        tor VARCHAR(255) UNIQUE DEFAULT NULL,
        INDEX idx_addresses_pubkey (pubkey_hash),
        INDEX idx_addresses_http (http),
        INDEX idx_addresses_https (https),
        INDEX idx_addresses_tor (tor)
    )";
}

// Contact Credit table - stores available credit information from contacts, updated during ping
function getContactCreditTableSchema() {
    return "CREATE TABLE IF NOT EXISTS contact_credit (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        pubkey_hash VARCHAR(64) NOT NULL,
        available_credit_whole BIGINT DEFAULT 0,
        available_credit_frac BIGINT DEFAULT 0,
        currency VARCHAR(10),
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_contact_credit_hash_currency (pubkey_hash, currency),
        INDEX idx_contact_credit_pubkey_hash (pubkey_hash)
    )";
}

// Contact Currencies table - per-contact currency configuration (fee, credit limit)
function getContactCurrenciesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS contact_currencies (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        pubkey_hash VARCHAR(64) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        fee_percent INT NOT NULL,
        credit_limit_whole BIGINT DEFAULT NULL,
        credit_limit_frac BIGINT DEFAULT 0,
        status ENUM('accepted', 'pending', 'blocked') DEFAULT 'pending',
        direction ENUM('incoming', 'outgoing') DEFAULT 'incoming',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_cc_hash_currency (pubkey_hash, currency),
        INDEX idx_cc_pubkey_hash (pubkey_hash)
    )";
}

// Balance table - per-contact sent/received totals, joined on pubkey_hash
function getBalancesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS balances (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        pubkey_hash TEXT NOT NULL,
        received_whole BIGINT NOT NULL,
        received_frac BIGINT NOT NULL,
        sent_whole BIGINT NOT NULL,
        sent_frac BIGINT NOT NULL,
        currency VARCHAR(10),
        INDEX idx_balances_pubkey_hash (pubkey_hash)
    )";
}

// ============================================================================
// TRANSACTIONS & CHAIN INTEGRITY
// Tables for transaction records, held transactions, and chain repairs
// ============================================================================

// Transactions table
function getTransactionsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        tx_type ENUM(
            'standard',  /* Transaction is direct to known contact */
            'p2p',       /* Transaction is p2p to unknown contact (or part of p2p chain to known contact) */
            'contact'    /* Contact request transaction (amount=0, first transaction to establish contact) */
        ) DEFAULT 'standard',
        type ENUM(
            'received',  /* Transaction was received by user */
            'sent',      /* Transaction was sent by user */
            'relay'      /* Transaction was a relay, user is intermediary */
        ) DEFAULT 'sent',
        status ENUM(
            'pending',   /* Transaction has been created */
            'sending',   /* Transaction claimed for processing, prevents duplicates */
            'sent',      /* Transaction has been sent onwards*/
            'accepted',  /* Transaction has been accepted by peer */
            'rejected',  /* Transaction has been rejected by peer */
            'cancelled', /* Transaction has not been received by peer in time */
            'completed', /* Transaction has been accepted by final recipient */
            'failed'     /* Transaction failed after max recovery attempts, needs manual review */
        ) DEFAULT 'pending',
        sender_address VARCHAR(255) NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_public_key_hash VARCHAR(64),
        receiver_address VARCHAR(255) NOT NULL,
        receiver_public_key TEXT NOT NULL,
        receiver_public_key_hash VARCHAR(64),
        amount_whole BIGINT NOT NULL,
        amount_frac BIGINT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        timestamp DATETIME(6) DEFAULT CURRENT_TIMESTAMP,
        txid VARCHAR(255) UNIQUE NOT NULL,
        previous_txid VARCHAR(255),
        sender_signature TEXT,
        recipient_signature TEXT,                      /* Signature of receiver upon accepting transaction */
        signature_nonce VARCHAR(64),
        time BIGINT NULL,
        memo TEXT,
        description TEXT,
        initial_sender_address VARCHAR(255) DEFAULT NULL,
        end_recipient_address VARCHAR(255) DEFAULT NULL,
        sending_started_at DATETIME(6) DEFAULT NULL,  /* When processing started, for recovery timeout detection */
        recovery_count INT DEFAULT 0,                  /* Number of times this transaction has been recovered */
        needs_manual_review TINYINT(1) DEFAULT 0,      /* Flag for transactions needing manual intervention */
        expires_at DATETIME(6) DEFAULT NULL,           /* Absolute delivery deadline: NULL = no expiry (direct tx default).
                                                          P2P transactions set to p2p_expiry + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS.
                                                          Direct transactions set only if user configures directTxExpiration > 0.
                                                          CleanupService cancels transactions past this time that are still pending/sending. */
        signed_message_content TEXT DEFAULT NULL,      /* Raw signed JSON for E2E encrypted transactions.
                                                          Stored verbatim so sync signature verification can use it
                                                          directly instead of reconstructing from plaintext fields. */
        INDEX idx_transactions_receiver_public_key_hash (receiver_public_key_hash),
        INDEX idx_transactions_sender_public_key_hash (sender_public_key_hash),
        INDEX idx_transactions_sender_receiver (sender_public_key_hash, receiver_public_key_hash),
        INDEX idx_transactions_chain (sender_public_key_hash, receiver_public_key_hash, timestamp DESC),
        INDEX idx_transactions_currency_chain (sender_public_key_hash, receiver_public_key_hash, currency, timestamp DESC),
        INDEX idx_transactions_status (status),
        INDEX idx_transactions_timestamp (timestamp),
        INDEX idx_transactions_status_timestamp (status, timestamp DESC),
        INDEX idx_transactions_txid (txid),
        INDEX idx_transactions_previous_txid (previous_txid),
        INDEX idx_transactions_memo (memo(255)),
        INDEX idx_transactions_initial_sender (initial_sender_address),
        INDEX idx_transactions_end_recipient (end_recipient_address),
        INDEX idx_transactions_sending_recovery (status, sending_started_at),  /* For finding stuck transactions */
        INDEX idx_transactions_expires_at (expires_at)  /* For efficient expired-transaction cleanup queries */
    )";
}

// Held Transactions table - tracks transactions pending resync completion
function getHeldTransactionsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS held_transactions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        txid VARCHAR(255) NOT NULL,
        original_previous_txid VARCHAR(255),
        expected_previous_txid VARCHAR(255),
        transaction_type ENUM('standard', 'p2p') DEFAULT 'standard',
        hold_reason ENUM(
            'invalid_previous_txid',
            'sync_in_progress'
        ) DEFAULT 'invalid_previous_txid',
        sync_status ENUM(
            'not_started',
            'in_progress',
            'completed',
            'failed'
        ) DEFAULT 'not_started',
        retry_count INT DEFAULT 0,
        max_retries INT DEFAULT 3,
        held_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        last_sync_attempt TIMESTAMP(6) NULL,
        next_retry_at TIMESTAMP(6) NULL,
        resolved_at TIMESTAMP(6) NULL,
        INDEX idx_held_contact (contact_pubkey_hash),
        INDEX idx_held_txid (txid),
        INDEX idx_held_status (sync_status),
        INDEX idx_held_contact_status (contact_pubkey_hash, sync_status),
        INDEX idx_held_next_retry (next_retry_at, sync_status)
    )";
}

// Chain Drop Proposals table - tracks mutual agreements to drop missing transactions
function getChainDropProposalsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS chain_drop_proposals (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        proposal_id VARCHAR(255) NOT NULL UNIQUE,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        missing_txid VARCHAR(255) NOT NULL,
        broken_txid VARCHAR(255) NOT NULL,
        previous_txid_before_gap VARCHAR(255),
        direction ENUM('outgoing', 'incoming') NOT NULL,
        status ENUM(
            'pending',
            'accepted',
            'executed',
            'rejected',
            'expired',
            'failed'
        ) DEFAULT 'pending',
        gap_context JSON,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expires_at TIMESTAMP(6) NOT NULL,
        resolved_at TIMESTAMP(6) NULL,
        INDEX idx_cdp_proposal_id (proposal_id),
        INDEX idx_cdp_contact (contact_pubkey_hash),
        INDEX idx_cdp_status (status),
        INDEX idx_cdp_contact_status (contact_pubkey_hash, status),
        INDEX idx_cdp_missing_txid (missing_txid),
        INDEX idx_cdp_expires (expires_at, status)
    )";
}

// ============================================================================
// P2P ROUTING
// Tables for peer-to-peer payment routing, responses, candidates, and relays
// ============================================================================

// P2p table
function getP2pTableSchema() {
    return "CREATE TABLE IF NOT EXISTS p2p (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL UNIQUE, /* sha256(receiver_address + salt + time) */
        salt VARCHAR(255) NOT NULL, /* hash(inquiry_secret) — derived from secret, serves as both salt and token */
        inquiry_secret VARCHAR(64), /* only stored on original sender; hash(secret) = salt for inquiry verification */
        time BIGINT NOT NULL,
        expiration BIGINT NOT NULL, /* unix epoch (micro) seconds */
        currency VARCHAR(10) NOT NULL,
        amount_whole BIGINT NOT NULL,
        amount_frac BIGINT NOT NULL,
        my_fee_amount_whole BIGINT,
        my_fee_amount_frac BIGINT,
        rp2p_amount_whole BIGINT,  /* Total amount from RP2P response (including all relay fees) - set when awaiting_approval */
        rp2p_amount_frac BIGINT,
        destination_address VARCHAR(255), /* only set if you are the original sender */
        destination_pubkey TEXT,
        destination_signature TEXT,
        request_level INTEGER NOT NULL,
        max_request_level INTEGER NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_signature TEXT,
        description TEXT,
        fast TINYINT(1) DEFAULT 1, /* 1=fast mode (first rp2p wins), 0=best-fee mode (collect all, pick cheapest) */
        hop_wait INT DEFAULT 0, /* seconds to wait at each hop before forwarding (best-fee mode delay) */
        contacts_sent_count INT DEFAULT 0, /* number of contacts the p2p was sent to */
        contacts_responded_count INT DEFAULT 0, /* number of contacts that responded with rp2p */
        contacts_relayed_count INT DEFAULT 0, /* number of contacts that returned already_relayed (two-phase selection) */
        contacts_relayed_responded_count INT DEFAULT 0, /* number of relayed contacts that responded with rp2p (phase 2) */
        phase1_sent TINYINT(1) DEFAULT 0, /* 1=Phase 1 best candidate already sent to relayed contacts */
        status ENUM(
            'initial',      /* First received p2p request */
            'queued',       /* Waiting to be processed */
            'sending',      /* Claimed by a worker process, prevents duplicate processing */
            'sent',         /* Request has been sent to contacts */
            'found',        /* Contact has been found and being reported back */
            'awaiting_approval', /* Route found, waiting for user to approve the transaction */
            'paid',         /* Payment has been sent to the next peer */
            'completed',    /* Transaction successfully executed */
            'cancelled',    /* Transaction cancelled or failed */
            'expired'       /* Request timed out */
        ) DEFAULT 'initial',
        sending_started_at TIMESTAMP(6) NULL, /* When worker claimed this P2P for processing */
        sending_worker_pid INT NULL, /* PID of the worker process handling this P2P */
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        incoming_txid VARCHAR(255),
        outgoing_txid VARCHAR(255),
        completed_at TIMESTAMP(6) NULL,
        INDEX idx_p2p_hash (hash),
        INDEX idx_p2p_status (status),
        INDEX idx_p2p_created_at (created_at),
        INDEX idx_p2p_status_created_at (status, created_at ASC),
        INDEX idx_p2p_sender_address (sender_address),
        INDEX idx_p2p_sender_address_status (sender_address, status),
        INDEX idx_p2p_destination (destination_address),
        INDEX idx_p2p_incoming_txid (incoming_txid),
        INDEX idx_p2p_outgoing_txid (outgoing_txid),
        INDEX idx_p2p_status_expiration (status, expiration),
        INDEX idx_p2p_sending_recovery (status, sending_started_at)
    )";
}

// P2P Senders table - tracks all upstream senders per P2P hash for multi-path RP2P delivery
function getP2pSendersTableSchema() {
    return "CREATE TABLE IF NOT EXISTS p2p_senders (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_public_key TEXT NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_p2p_senders_hash_addr (hash, sender_address),
        INDEX idx_p2p_senders_hash (hash),
        INDEX idx_p2p_senders_created_at (created_at)
    )";
}

// P2P Relayed Contacts table - tracks contacts that returned already_relayed during broadcast (two-phase selection)
function getP2pRelayedContactsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS p2p_relayed_contacts (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL,
        contact_address VARCHAR(255) NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_p2p_relayed_hash_addr (hash, contact_address),
        INDEX idx_p2p_relayed_hash (hash),
        INDEX idx_p2p_relayed_created_at (created_at)
    )";
}

// Response to peer to peer request table
function getRp2pTableSchema() {
    return "CREATE TABLE IF NOT EXISTS rp2p (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL UNIQUE, /* sha256(receiver_address + salt + time) */
        time BIGINT NOT NULL,
        amount_whole BIGINT NOT NULL,
        amount_frac BIGINT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_signature TEXT NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rp2p_hash (hash),
        INDEX idx_rp2p_created_at (created_at),
        INDEX idx_rp2p_sender_address (sender_address)
    )";
}

// RP2P Candidates table - stores candidate rp2p responses for best-fee route selection
function getRp2pCandidatesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS rp2p_candidates (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL,
        time BIGINT NOT NULL,
        amount_whole BIGINT NOT NULL,
        amount_frac BIGINT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_signature TEXT NOT NULL,
        fee_amount_whole BIGINT NOT NULL,
        fee_amount_frac BIGINT NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rp2p_cand_hash (hash),
        INDEX idx_rp2p_cand_hash_amount (hash, amount_whole ASC, amount_frac ASC),
        INDEX idx_rp2p_cand_created_at (created_at)
    )";
}

// ============================================================================
// MESSAGE DELIVERY
// Tables for tracking message delivery, failed messages, and delivery metrics
// ============================================================================

// Message Delivery Tracking table - tracks multi-stage acknowledgments and retries
function getMessageDeliveryTableSchema() {
    return "CREATE TABLE IF NOT EXISTS message_delivery (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        message_type ENUM('transaction', 'p2p', 'rp2p', 'contact') NOT NULL,
        message_id VARCHAR(255) NOT NULL,  /* txid, hash, etc. */
        recipient_address VARCHAR(255) NOT NULL,
        payload JSON NULL,  /* Stored payload for retry attempts */
        delivery_stage ENUM(
            'pending',      /* Message queued for delivery */
            'sent',         /* Message sent, awaiting acknowledgment */
            'received',     /* Recipient acknowledged receipt */
            'inserted',     /* Recipient confirmed database insertion */
            'forwarded',    /* Recipient confirmed forwarding to next hop */
            'completed',    /* Full delivery chain completed */
            'failed'        /* Delivery failed after all retries */
        ) DEFAULT 'pending',
        retry_count INT DEFAULT 0,
        max_retries INT DEFAULT 5,
        next_retry_at TIMESTAMP(6) NULL,
        last_error TEXT,
        last_response TEXT,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_delivery_unique (message_type, message_id, recipient_address),
        INDEX idx_delivery_stage (delivery_stage),
        INDEX idx_delivery_retry (delivery_stage, next_retry_at),
        INDEX idx_delivery_message_type (message_type),
        INDEX idx_delivery_created_at (created_at)
    )";
}

// Dead Letter Queue table - stores failed messages for manual review
function getDeadLetterQueueTableSchema() {
    return "CREATE TABLE IF NOT EXISTS dead_letter_queue (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        message_type ENUM('transaction', 'p2p', 'rp2p', 'contact') NOT NULL,
        message_id VARCHAR(255) NOT NULL,  /* txid, hash, etc. - matches message_delivery.message_id */
        payload JSON NOT NULL,
        recipient_address VARCHAR(255) NOT NULL,
        retry_count INT DEFAULT 0,
        last_retry_at TIMESTAMP(6) NULL,
        failure_reason TEXT,
        status ENUM(
            'pending',      /* Awaiting manual review */
            'retrying',     /* Being retried manually */
            'resolved',     /* Successfully resolved/reprocessed */
            'abandoned'     /* Manually marked as abandoned */
        ) DEFAULT 'pending',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP(6) NULL,
        INDEX idx_dlq_status (status),
        INDEX idx_dlq_message_type (message_type),
        INDEX idx_dlq_created_at (created_at),
        INDEX idx_dlq_status_created (status, created_at)
    )";
}

// Message Delivery Metrics table - tracks delivery success/failure rates
function getDeliveryMetricsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS delivery_metrics (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        period_start TIMESTAMP(6) NOT NULL,
        period_end TIMESTAMP(6) NOT NULL,
        message_type ENUM('transaction', 'p2p', 'rp2p', 'contact', 'all') NOT NULL,
        total_sent INT DEFAULT 0,
        total_delivered INT DEFAULT 0,
        total_failed INT DEFAULT 0,
        avg_delivery_time_ms INT DEFAULT 0,
        avg_retry_count DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_metrics_period (period_start, period_end),
        INDEX idx_metrics_type (message_type),
        INDEX idx_metrics_created (created_at)
    )";
}

// ============================================================================
// API
// Tables for API key management, request logging, and replay protection
// ============================================================================

// API Keys table for external API access
function getApiKeysTableSchema() {
    return "CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        key_id VARCHAR(32) NOT NULL UNIQUE,
        encrypted_secret JSON NOT NULL,
        name VARCHAR(255) NOT NULL,
        permissions JSON NOT NULL,
        rate_limit_per_minute INT DEFAULT 100,
        enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP(6) NULL,
        expires_at TIMESTAMP(6) NULL,
        INDEX idx_api_keys_key_id (key_id),
        INDEX idx_api_keys_enabled (enabled),
        INDEX idx_api_keys_expires (expires_at)
    )";
}

// API Request Log table for audit trail
function getApiRequestLogTableSchema() {
    return "CREATE TABLE IF NOT EXISTS api_request_log (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        key_id VARCHAR(32) NOT NULL,
        endpoint VARCHAR(255) NOT NULL,
        method VARCHAR(10) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        request_timestamp TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        response_code INT NOT NULL,
        response_time_ms INT,
        INDEX idx_api_log_key_id (key_id),
        INDEX idx_api_log_timestamp (request_timestamp),
        INDEX idx_api_log_endpoint (endpoint)
    )";
}

// API Nonce tracking table for replay protection
function getApiNoncesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS api_nonces (
        nonce VARCHAR(64) NOT NULL,
        key_id VARCHAR(32) NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (key_id, nonce),
        INDEX idx_api_nonces_created (created_at)
    )";
}

// ============================================================================
// SYSTEM & SECURITY
// Tables for debugging, rate limiting, and system-level concerns
// ============================================================================

// Debug table
function getDebugTableSchema() {
    return "CREATE TABLE IF NOT EXISTS debug (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        timestamp DATETIME(6) DEFAULT CURRENT_TIMESTAMP,
        level ENUM('SILENT', 'ECHO', 'INFO', 'WARNING', 'ERROR', 'CRITICAL') NOT NULL,
        message TEXT NOT NULL,
        context JSON,
        file VARCHAR(255),
        line INTEGER,
        trace TEXT,
        INDEX idx_timestamp (timestamp),
        INDEX idx_level (level),
        INDEX idx_level_timestamp (level, timestamp)
    )";
}

// Rate Limits table - prevents abuse and brute force attacks
function getRateLimitsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS rate_limits (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        identifier VARCHAR(255) NOT NULL,
        action VARCHAR(100) NOT NULL,
        attempts INTEGER DEFAULT 0,
        first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        blocked_until TIMESTAMP NULL,
        INDEX idx_identifier_action (identifier, action),
        INDEX idx_blocked_until (blocked_until)
    )";
}

// ============================================================================
// PAYMENT REQUESTS
// Table for peer-to-peer payment requests (ask a contact to pay you)
// ============================================================================

// Payment Requests table - stores both outgoing (we sent) and incoming (sent to us) requests
function getPaymentRequestsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS payment_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        request_id VARCHAR(64) UNIQUE NOT NULL,         /* SHA-256 hex, uniquely identifies this request */
        direction ENUM(
            'incoming',  /* Request was sent to us — we are being asked to pay */
            'outgoing'   /* We sent this request — we are asking someone to pay us */
        ) NOT NULL,
        status ENUM(
            'pending',   /* Awaiting response */
            'approved',  /* Recipient paid (resulting_txid set) */
            'declined',  /* Recipient declined */
            'cancelled', /* Requester cancelled before a response */
            'expired'    /* Request expired (future: TTL-based cleanup) */
        ) NOT NULL DEFAULT 'pending',
        requester_pubkey_hash VARCHAR(64) NOT NULL,     /* Public key hash of the person requesting payment */
        requester_address     VARCHAR(255) DEFAULT NULL, /* Transport address of requester (for sending response) */
        contact_name          VARCHAR(255) DEFAULT NULL, /* Display name of the other party */
        recipient_pubkey_hash VARCHAR(64) NOT NULL,     /* Public key hash of the person being asked to pay */
        amount_whole BIGINT NOT NULL,
        amount_frac  BIGINT NOT NULL DEFAULT 0,
        currency     VARCHAR(10) NOT NULL,
        description  VARCHAR(255) DEFAULT NULL,         /* Optional memo visible to both parties */
        created_at   DATETIME(6) NOT NULL,
        expires_at   DATETIME(6) DEFAULT NULL,          /* Optional TTL (future use) */
        responded_at DATETIME(6) DEFAULT NULL,          /* When approve/decline was recorded */
        resulting_txid VARCHAR(64) DEFAULT NULL,        /* txid of the payment transaction if approved */
        signed_message_content TEXT DEFAULT NULL,       /* Raw signed payload for audit/verification */
        INDEX idx_pr_requester (requester_pubkey_hash),
        INDEX idx_pr_recipient (recipient_pubkey_hash),
        INDEX idx_pr_status (status),
        INDEX idx_pr_direction_status (direction, status),
        INDEX idx_pr_created_at (created_at)
    )";
}

// ============================================================================
// CAPACITY RESERVATIONS & ROUTE CANCELLATIONS
// Tables for tracking reserved credit capacity during P2P route discovery
// and audit trail for route cancellation messages.
// ============================================================================

// Capacity Reservations table - tracks credit reserved at each relay hop
function getCapacityReservationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS capacity_reservations (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(128) NOT NULL,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        base_amount_whole BIGINT NOT NULL,
        base_amount_frac BIGINT NOT NULL,
        total_amount_whole BIGINT NOT NULL,
        total_amount_frac BIGINT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        status ENUM('active', 'released', 'committed') DEFAULT 'active',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
        released_at TIMESTAMP(6) NULL,
        release_reason ENUM('cancelled', 'expired', 'committed') NULL,
        UNIQUE INDEX idx_cap_res_hash_contact (hash, contact_pubkey_hash, currency),
        INDEX idx_cap_res_status (status),
        INDEX idx_cap_res_hash (hash)
    )";
}

// Route Cancellations table - audit trail for cancellation messages sent to unselected routes
function getRouteCancellationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS route_cancellations (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(128) NOT NULL,
        candidate_id INT NULL,
        contact_address VARCHAR(255) NOT NULL,
        status ENUM('sent', 'acknowledged', 'failed') DEFAULT 'sent',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
        acknowledged_at TIMESTAMP(6) NULL,
        INDEX idx_route_cancel_hash (hash),
        INDEX idx_route_cancel_status (status)
    )";
}
