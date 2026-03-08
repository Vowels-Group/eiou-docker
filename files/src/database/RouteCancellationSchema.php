<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

// ============================================================================
// ROUTE CANCELLATION
// Tables for tracking cancelled P2P routes and capacity reservations.
//
// Patent Claim 16: Supports cancellation RP2P messages along unselected routes
// to release reserved credit capacity after payer selects the best route.
// ============================================================================

/**
 * Route Cancellations table - tracks cancellation messages sent along unselected routes
 *
 * When the payer selects the best route from RP2P candidates, cancellation messages
 * are sent along all other routes. This table records each cancellation and its status
 * (sent, acknowledged by the intermediary, or failed).
 */
function getRouteCancellationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS route_cancellations (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(128) NOT NULL,
        candidate_id INT NULL,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        contact_address VARCHAR(255) NOT NULL,
        released_amount INT NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL,
        status ENUM('sent', 'acknowledged', 'failed') DEFAULT 'sent',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        acknowledged_at TIMESTAMP(6) NULL,
        INDEX idx_route_cancel_hash (hash),
        INDEX idx_route_cancel_pubkey_hash (contact_pubkey_hash),
        INDEX idx_route_cancel_status (status)
    )";
}

/**
 * Capacity Reservations table - tracks credit capacity reserved during route discovery
 *
 * When an intermediary node participates in route discovery, it reserves credit capacity
 * for the potential transaction. If the route is not selected, the reservation is released
 * (status='released'). If the route is selected and the transaction proceeds, the
 * reservation is committed (status='committed').
 */
function getCapacityReservationsTableSchema() {
    return "CREATE TABLE IF NOT EXISTS capacity_reservations (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        hash VARCHAR(128) NOT NULL,
        contact_pubkey_hash VARCHAR(64) NOT NULL,
        reserved_amount INT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        status ENUM('active', 'released', 'committed') DEFAULT 'active',
        created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP,
        released_at TIMESTAMP(6) NULL,
        UNIQUE INDEX idx_cap_res_hash_pubkey_currency (hash, contact_pubkey_hash, currency),
        INDEX idx_cap_res_status (status)
    )";
}
