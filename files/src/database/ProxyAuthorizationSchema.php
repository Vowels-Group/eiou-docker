<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

// ============================================================================
// PROXY AUTHORIZATION (Patent Claims 3, 10, 11, 15)
// Tables for proxy node authorization, shadow balances, and proxy transactions.
//
// Claim 3:  Proxy node for offline participation
// Claim 10: Multiple proxies + first-commit conflict resolution
// Claim 11: Dispute flagging for out-of-scope proxy transactions
// Claim 15: Shadow balance verification
// ============================================================================

/**
 * Proxy Authorizations table
 *
 * Stores delegation records where a principal grants scoped transaction
 * authority to a proxy node. The scope defines action types, amount limits,
 * authorized currencies, and an expiration timestamp.
 *
 * Lifecycle: active -> revoked (principal cancels)
 *            active -> expired (expiration time reached)
 */
function getProxyAuthorizationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS proxy_authorizations (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        authorization_id VARCHAR(36) NOT NULL UNIQUE,
        principal_pubkey_hash VARCHAR(64) NOT NULL,
        proxy_pubkey_hash VARCHAR(64) NOT NULL,
        scope_json TEXT NOT NULL,
        signature TEXT NOT NULL,
        status ENUM('active', 'revoked', 'expired') DEFAULT 'active',
        expires_at TIMESTAMP(6) NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        revoked_at TIMESTAMP(6) NULL,
        INDEX idx_pa_proxy_status (proxy_pubkey_hash, status),
        INDEX idx_pa_principal_status (principal_pubkey_hash, status),
        INDEX idx_pa_expires (expires_at, status),
        INDEX idx_pa_authorization_id (authorization_id)
    )";
}

/**
 * Proxy Balance Snapshots table
 *
 * Shadow copies of the principal's bilateral balances, taken at authorization
 * time. The proxy operates against these snapshots while the principal is offline.
 * Adjusted dynamically by getShadowBalance() based on proxy_transactions executed
 * since the snapshot.
 */
function getProxyBalanceSnapshotsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS proxy_balance_snapshots (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        authorization_id VARCHAR(36) NOT NULL,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        credit_limit INT NOT NULL,
        available_credit INT NOT NULL,
        current_balance INT NOT NULL DEFAULT 0,
        snapshot_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_pbs_auth_contact_currency (authorization_id, contact_pubkey_hash, currency),
        INDEX idx_pbs_authorization_id (authorization_id)
    )";
}

/**
 * Proxy Transactions table
 *
 * Records of transactions executed by proxy nodes on behalf of principals.
 * Each record includes the proxy's signature, authorization reference, and
 * chain linkage for later integration into the principal's local chain.
 *
 * Lifecycle: executed -> synced (integrated by principal)
 *            executed -> disputed (flagged by principal, Claim 11)
 *            executed -> conflicted (lost first-commit resolution, Claim 10)
 */
function getProxyTransactionsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS proxy_transactions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        proxy_transaction_id VARCHAR(36) NOT NULL UNIQUE,
        authorization_id VARCHAR(36) NOT NULL,
        discovery_hash VARCHAR(128) NULL,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        amount INT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        proxy_signature TEXT NOT NULL,
        counterparty_signature TEXT NULL,
        authorization_reference TEXT NOT NULL,
        chain_previous_txid VARCHAR(128) NULL,
        status ENUM('executed', 'synced', 'disputed', 'conflicted') DEFAULT 'executed',
        dispute_reason TEXT NULL,
        executed_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        synced_at TIMESTAMP(6) NULL,
        INDEX idx_ptx_auth_status (authorization_id, status),
        INDEX idx_ptx_discovery_hash (discovery_hash),
        INDEX idx_ptx_contact (contact_pubkey_hash),
        INDEX idx_ptx_proxy_transaction_id (proxy_transaction_id),
        INDEX idx_ptx_executed_at (executed_at)
    )";
}
