<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

/**
 * Patent v3 — Database schemas for unimplemented modules
 * 
 * Module 5: Multi-Currency (complete)
 * Module 8: Regulatory Compliance
 * Module 9: Trust Graph Management
 * Module 10: Multi-Party Authorization (Multisig)
 */

// ============================================================
// MODULE 5: Multi-Currency Trust Extension (Claims 17-20)
// ============================================================

function getContactCurrenciesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS contact_currencies (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        credit_limit DECIMAL(20,8) DEFAULT 0,
        fee_percent DECIMAL(10,6) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_contact_currency (contact_pubkey_hash, currency),
        INDEX idx_currency (currency),
        INDEX idx_active (is_active)
    )";
}

function getExchangeRatesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS exchange_rates (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        from_currency VARCHAR(10) NOT NULL,
        to_currency VARCHAR(10) NOT NULL,
        rate DECIMAL(20,10) NOT NULL,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_pair (from_currency, to_currency)
    )";
}

// ============================================================
// MODULE 8: Regulatory Compliance (Claims 28-34)
// ============================================================

function getIdentityVerificationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS identity_verifications (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        tier INTEGER NOT NULL DEFAULT 0,
        verification_data JSON,
        verifier_pubkey VARCHAR(128),
        verifier_signature VARCHAR(512),
        verified_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP(6) NULL,
        is_active TINYINT(1) DEFAULT 1,
        INDEX idx_contact_tier (contact_pubkey_hash, tier),
        INDEX idx_active (is_active)
    )";
}

function getMyIdentityTableSchema() {
    return "CREATE TABLE IF NOT EXISTS my_identity (
        tier INTEGER PRIMARY KEY,
        identity_data JSON,
        attestation_signature VARCHAR(512),
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
}

function getCompliancePoliciesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS compliance_policies (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        jurisdiction VARCHAR(10) NOT NULL,
        rule_type VARCHAR(50) NOT NULL,
        threshold_amount DECIMAL(20,8),
        threshold_currency VARCHAR(10),
        required_kyc_tier INTEGER,
        action VARCHAR(50) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        INDEX idx_jurisdiction (jurisdiction),
        INDEX idx_rule_type (rule_type)
    )";
}

function getSuspiciousActivityReportsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS suspicious_activity_reports (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        detection_type VARCHAR(50) NOT NULL,
        score DECIMAL(10,4) NOT NULL,
        details JSON,
        requires_review TINYINT(1) DEFAULT 1,
        reviewed_by VARCHAR(64),
        reviewed_at TIMESTAMP(6) NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact (contact_pubkey_hash),
        INDEX idx_type (detection_type),
        INDEX idx_review (requires_review)
    )";
}

function getTravelRuleLogTableSchema() {
    return "CREATE TABLE IF NOT EXISTS travel_rule_log (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        txid VARCHAR(64) NOT NULL,
        payload_hash VARCHAR(64) NOT NULL,
        direction ENUM('sent', 'received', 'relayed') NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_txid (txid)
    )";
}

// ============================================================
// MODULE 9: Trust Graph Management (Claims 35-40)
// ============================================================

function getTrustScoresTableSchema() {
    return "CREATE TABLE IF NOT EXISTS trust_scores (
        contact_pubkey_hash VARCHAR(64) PRIMARY KEY,
        payment_reliability DECIMAL(5,4) DEFAULT 0.5000,
        routing_performance DECIMAL(5,4) DEFAULT 0.5000,
        credit_utilization DECIMAL(5,4) DEFAULT 0.5000,
        settlement_timeliness DECIMAL(5,4) DEFAULT 0.5000,
        compliance_standing DECIMAL(5,4) DEFAULT 0.5000,
        composite_score DECIMAL(5,4) DEFAULT 0.5000,
        confidence DECIMAL(5,4) DEFAULT 0.0000,
        data_points INTEGER DEFAULT 0,
        trust_stage INTEGER DEFAULT 0,
        last_computed TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
}

function getTrustScoreHistoryTableSchema() {
    return "CREATE TABLE IF NOT EXISTS trust_score_history (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        composite_score DECIMAL(5,4) NOT NULL,
        computed_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact (contact_pubkey_hash),
        INDEX idx_date (computed_at)
    )";
}

function getTrustSignalsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS trust_signals (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        subject_pubkey_hash VARCHAR(64) NOT NULL,
        issuer_pubkey VARCHAR(128) NOT NULL,
        score DECIMAL(5,4) NOT NULL,
        confidence DECIMAL(5,4) NOT NULL,
        volume_bucket VARCHAR(20),
        relationship_age_days INTEGER,
        hop_count INTEGER DEFAULT 0,
        effective_trust DECIMAL(5,4),
        signature VARCHAR(512) NOT NULL,
        received_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_subject (subject_pubkey_hash),
        INDEX idx_issuer (issuer_pubkey)
    )";
}

function getIntroductionsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS introductions (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        newcomer_pubkey VARCHAR(128) NOT NULL,
        introducer_pubkey VARCHAR(128) NOT NULL,
        vouch_level INTEGER NOT NULL DEFAULT 1,
        stake_amount DECIMAL(20,8) DEFAULT 0,
        status ENUM('pending', 'accepted', 'rejected', 'expired') DEFAULT 'pending',
        signature VARCHAR(512) NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP(6) NULL,
        INDEX idx_newcomer (newcomer_pubkey),
        INDEX idx_status (status)
    )";
}

function getTrustDamageEventsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS trust_damage_events (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        severity DECIMAL(5,4) NOT NULL,
        description TEXT,
        recovery_plan JSON,
        recovered TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact (contact_pubkey_hash),
        INDEX idx_recovered (recovered)
    )";
}

// ============================================================
// MODULE 10: Multi-Party Authorization / Multisig (Claims 51-56)
// ============================================================

function getAuthPoliciesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS auth_policies (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100),
        scope_type VARCHAR(50) NOT NULL DEFAULT 'global',
        scope_value VARCHAR(256),
        threshold_m INTEGER NOT NULL,
        threshold_n INTEGER NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        priority INTEGER DEFAULT 0,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active),
        INDEX idx_priority (priority)
    )";
}

function getAuthPolicyTiersTableSchema() {
    return "CREATE TABLE IF NOT EXISTS auth_policy_tiers (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        policy_id INTEGER NOT NULL,
        min_amount DECIMAL(20,8),
        max_amount DECIMAL(20,8),
        currency VARCHAR(10),
        required_m INTEGER NOT NULL,
        INDEX idx_policy (policy_id)
    )";
}

function getAuthorizedKeysTableSchema() {
    return "CREATE TABLE IF NOT EXISTS authorized_keys (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        policy_id INTEGER NOT NULL,
        public_key VARCHAR(128) NOT NULL,
        label VARCHAR(100),
        is_emergency TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        added_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_policy (policy_id),
        INDEX idx_key (public_key)
    )";
}

function getAuthRequestsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS auth_requests (
        id VARCHAR(36) PRIMARY KEY,
        policy_id INTEGER NOT NULL,
        transaction_hash VARCHAR(64) NOT NULL,
        transaction_data TEXT NOT NULL,
        required_m INTEGER NOT NULL,
        status ENUM('pending', 'approved', 'rejected', 'expired') DEFAULT 'pending',
        expires_at TIMESTAMP(6) NOT NULL,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP(6) NULL,
        INDEX idx_status (status),
        INDEX idx_policy (policy_id),
        INDEX idx_expires (expires_at)
    )";
}

function getAuthApprovalsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS auth_approvals (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        request_id VARCHAR(36) NOT NULL,
        approver_pubkey VARCHAR(128) NOT NULL,
        approval_hash VARCHAR(64) NOT NULL,
        signature VARCHAR(512) NOT NULL,
        approved TINYINT(1) NOT NULL,
        reason TEXT,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_request (request_id),
        INDEX idx_approver (approver_pubkey),
        UNIQUE KEY idx_request_approver (request_id, approver_pubkey)
    )";
}

function getAuthAuditLogTableSchema() {
    return "CREATE TABLE IF NOT EXISTS auth_audit_log (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        request_id VARCHAR(36),
        action VARCHAR(50) NOT NULL,
        actor_pubkey VARCHAR(128),
        details JSON,
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_request (request_id),
        INDEX idx_action (action)
    )";
}
