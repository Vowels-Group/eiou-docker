<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

// ============================================================================
// SPLIT PAYMENTS
// Tables for split payment routing when no single route has sufficient capacity
// (Patent Claim 9: dividing transaction amount across multiple routes)
// ============================================================================

/**
 * Split Payments table - tracks split payment plans and their execution status.
 *
 * Created when the payer node determines that no single RP2P route has
 * sufficient confirmed credit capacity and the transaction amount must be
 * divided across two or more routes.
 *
 * @return string SQL CREATE TABLE statement
 */
function getSplitPaymentsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS split_payments (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        split_id VARCHAR(36) NOT NULL UNIQUE,
        original_hash VARCHAR(128) NOT NULL,
        total_amount INT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        route_count INT NOT NULL,
        status ENUM(
            'planned',     /* Split plan created, not yet executing */
            'executing',   /* Route commitments in progress */
            'completed',   /* All route commitments succeeded */
            'partial',     /* Some routes succeeded, some failed */
            'cancelled',   /* Split payment cancelled before completion */
            'reconciled'   /* All partials confirmed, settlement verified */
        ) DEFAULT 'planned',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP(6) NULL,
        INDEX idx_split_payments_original_hash (original_hash),
        INDEX idx_split_payments_status (status)
    )";
}

/**
 * Split Payment Routes table - tracks individual route allocations within a split.
 *
 * Each row represents one partial amount allocated to a specific RP2P candidate
 * route. The sum of allocated_amount across all routes for a split_id equals
 * the original total_amount in split_payments.
 *
 * @return string SQL CREATE TABLE statement
 */
function getSplitPaymentRoutesTableSchema() {
    return "CREATE TABLE IF NOT EXISTS split_payment_routes (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        split_id VARCHAR(36) NOT NULL,
        candidate_id INT NOT NULL,
        route_hash VARCHAR(128) NULL,
        allocated_amount INT NOT NULL,
        confirmed_capacity INT NOT NULL,
        fee_amount INT NOT NULL DEFAULT 0,
        status ENUM(
            'planned',    /* Route allocated, not yet executing */
            'executing',  /* Bilateral IOU creation in progress */
            'completed',  /* IOU committed successfully */
            'failed',     /* IOU commitment failed */
            'cancelled'   /* Route cancelled before commitment */
        ) DEFAULT 'planned',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP(6) NULL,
        INDEX idx_split_routes_split_id (split_id),
        INDEX idx_split_routes_status (status)
    )";
}
