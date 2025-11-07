<?php
# Copyright 2025

// Contacts table
function getContactsTableSchema() {
    return "CREATE TABLE contacts (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        http VARCHAR(255) UNIQUE,
        tor VARCHAR(255) UNIQUE,
        pubkey TEXT NOT NULL,
        pubkey_hash VARCHAR(64),
        name VARCHAR(255),
        status ENUM(
            'pending',  /* Contact request Created */ 
            'accepted', /* Contact request Accepted */ 
            'blocked'   /* Contact request Blocked */ 
        ) DEFAULT 'pending',
        fee_percent INT,
        credit_limit INT,
        currency VARCHAR(10),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contacts_tor (tor),
        INDEX idx_contacts_pubkey_hash (pubkey_hash),
        INDEX idx_contacts_name (name),
        INDEX idx_contacts_status (status),
        INDEX idx_contacts_address_status (tor, status)
    )";
}

// Debug table
function getDebugTableSchema() {
    return "CREATE TABLE debug (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
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

// P2p table
function getP2pTableSchema() {
    return "CREATE TABLE p2p (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL UNIQUE, /* This is the hash of the final recipient address + salt + time*/
        salt VARCHAR(255) NOT NULL,
        time BIGINT NOT NULL,
        expiration BIGINT NOT NULL, /* unix epoch (micro) seconds */
        currency VARCHAR(10) NOT NULL,
        amount INTEGER NOT NULL,
        my_fee_amount INTEGER,
        destination_address VARCHAR(255), /* only set if you are the original sender */
        destination_pubkey TEXT,
        destination_signature TEXT,
        request_level INTEGER NOT NULL,
        max_request_level INTEGER NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_signature TEXT,
        status ENUM(
            'initial',      /* First received p2p request */
            'queued',       /* Waiting to be processed */
            'sent',         /* Request has been sent to contacts */
            'found',        /* Contact has been found and being reported back */
            'paid',         /* Payment has been sent to the next peer */
            'completed',    /* Transaction successfully executed */
            'cancelled',    /* Transaction cancelled or failed */
            'expired'       /* Request timed out */
        ) DEFAULT 'initial',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        incoming_txid VARCHAR(255),
        outgoing_txid VARCHAR(255),
        completed_at TIMESTAMP NULL,
        INDEX idx_p2p_hash (hash),
        INDEX idx_p2p_status (status),
        INDEX idx_p2p_created_at (created_at),
        INDEX idx_p2p_status_created_at (status, created_at ASC),  
        INDEX idx_p2p_sender_address (sender_address),
        INDEX idx_p2p_sender_address_status (sender_address, status),
        INDEX idx_p2p_destination (destination_address),
        INDEX idx_p2p_incoming_txid (incoming_txid),
        INDEX idx_p2p_outgoing_txid (outgoing_txid),
        INDEX idx_p2p_status_expiration (status, expiration)
    )";
}

// Response to peer to peer request table
function getRp2pTableSchema() {
    return "CREATE TABLE rp2p (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(255) NOT NULL UNIQUE, /*This is the hash of the final recipient address + salt + time*/
        time BIGINT NOT NULL,
        amount INTEGER NOT NULL,
        currency VARCHAR(10) NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_address VARCHAR(255) NOT NULL,
        sender_signature TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rp2p_hash (hash),
        INDEX idx_rp2p_created_at (created_at),
        INDEX idx_rp2p_sender_address (sender_address)
    )";
}

// Message deduplication table
function getMessageDeduplicationTableSchema() {
    return "CREATE TABLE message_deduplication (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        fingerprint VARCHAR(64) NOT NULL UNIQUE,
        message_type ENUM('p2p', 'rp2p', 'transaction', 'contact', 'other') NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_fingerprint_expires (fingerprint, expires_at),
        INDEX idx_expires_at (expires_at),
        INDEX idx_message_type (message_type),
        INDEX idx_created_at (created_at)
    )";
}

// Transactions table
function getTransactionsTableSchema() {
    return "CREATE TABLE transactions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        tx_type ENUM(
            'standard',
            'p2p'
        ) DEFAULT 'standard',
        status ENUM(
            'pending',   /* Transaction has been created */
            'sent',      /* Transaction has been sent onwards*/
            'accepted',  /* Transaction has been accepted by peer */
            'rejected',  /* Transaction has been rejected by peer */
            'cancelled', /* Transaction has not been received by peer in time */
            'completed'  /* Transaction has been accepted by final recipient */
        ) DEFAULT 'pending',
        sender_address VARCHAR(255) NOT NULL,
        sender_public_key TEXT NOT NULL,
        sender_public_key_hash VARCHAR(64),
        receiver_address VARCHAR(255) NOT NULL,
        receiver_public_key TEXT NOT NULL,
        receiver_public_key_hash VARCHAR(64),
        amount INT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        txid VARCHAR(255) UNIQUE NOT NULL,
        previous_txid VARCHAR(255),
        sender_signature TEXT,
        memo TEXT,
        INDEX idx_transactions_receiver_public_key_hash (receiver_public_key_hash),
        INDEX idx_transactions_sender_public_key_hash (sender_public_key_hash),
        INDEX idx_transactions_sender_receiver (sender_public_key_hash, receiver_public_key_hash),
        INDEX idx_transactions_chain (sender_public_key_hash, receiver_public_key_hash, timestamp DESC),
        INDEX idx_transactions_status (status),
        INDEX idx_transactions_timestamp (timestamp),
        INDEX idx_transactions_status_timestamp (status, timestamp DESC),
        INDEX idx_transactions_txid (txid),
        INDEX idx_transactions_previous_txid (previous_txid),
        INDEX idx_transactions_memo (memo(255))
    )";
}

// Message retries table
function getMessageRetriesTableSchema() {
    return "CREATE TABLE message_retries (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        message_id VARCHAR(255) NOT NULL,
        message_type ENUM('transaction', 'p2p', 'rp2p') NOT NULL,
        recipient_address VARCHAR(255) NOT NULL,
        attempt_number INTEGER NOT NULL DEFAULT 0,
        status ENUM(
            'scheduled',  /* Retry is scheduled for future */
            'sent',       /* Retry attempt was sent */
            'failed',     /* Retry permanently failed */
            'completed'   /* Message successfully delivered */
        ) DEFAULT 'scheduled',
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        next_retry_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        INDEX idx_message_retries_message_id (message_id),
        INDEX idx_message_retries_status (status),
        INDEX idx_message_retries_next_retry (next_retry_at),
        INDEX idx_message_retries_recipient (recipient_address),
        INDEX idx_message_retries_type (message_type),
        INDEX idx_message_retries_status_next_retry (status, next_retry_at),
        INDEX idx_message_retries_message_attempt (message_id, attempt_number DESC),
        INDEX idx_message_retries_recipient_created (recipient_address, created_at DESC)
    )";
}

// Dead Letter Queue table
function getDeadLetterQueueTableSchema() {
    return "CREATE TABLE dead_letter_queue (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        message_type VARCHAR(50) NOT NULL,
        sender_address VARCHAR(255),
        transaction_hash VARCHAR(255),
        original_message TEXT NOT NULL,
        failure_reason VARCHAR(255) NOT NULL,
        last_error TEXT,
        retry_count INT DEFAULT 0,
        manual_retry_count INT DEFAULT 0,
        status ENUM(
            'failed',      /* Message failed all retry attempts */
            'retrying',    /* Manual retry in progress */
            'resolved',    /* Successfully retried and processed */
            'archived'     /* Manually archived/dismissed */
        ) DEFAULT 'failed',
        failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_retry_at TIMESTAMP NULL,
        resolved_at TIMESTAMP NULL,
        resolution_notes TEXT,
        INDEX idx_dlq_status (status),
        INDEX idx_dlq_message_type (message_type),
        INDEX idx_dlq_failed_at (failed_at),
        INDEX idx_dlq_status_failed_at (status, failed_at DESC),
        INDEX idx_dlq_transaction_hash (transaction_hash),
        INDEX idx_dlq_failure_reason (failure_reason)
    )";
}