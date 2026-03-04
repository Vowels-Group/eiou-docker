<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

/**
 * Database schema for voucher/trust-only onboarding system.
 */
class VoucherSchema
{
    /**
     * Issuer side: generated voucher codes
     */
    public const TABLE_VOUCHER_CODES = "
        CREATE TABLE IF NOT EXISTS voucher_codes (
            code VARCHAR(32) PRIMARY KEY,
            batch_id VARCHAR(32) NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            label VARCHAR(255) DEFAULT NULL,
            status ENUM('active','redeemed','expired','revoked') NOT NULL DEFAULT 'active',
            redeemed_by VARCHAR(512) DEFAULT NULL,
            redeemed_at DATETIME(6) DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME(6) NOT NULL,
            INDEX idx_batch (batch_id),
            INDEX idx_status (status),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    /**
     * Redeemer side: record of redeemed vouchers
     */
    public const TABLE_VOUCHER_REDEMPTIONS = "
        CREATE TABLE IF NOT EXISTS voucher_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            issuer_address VARCHAR(512) NOT NULL,
            amount DECIMAL(18,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            redeemed_at DATETIME(6) NOT NULL,
            INDEX idx_issuer (issuer_address),
            INDEX idx_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    /**
     * Trusted issuers: pre-approved voucher sources.
     * When a user scans a code from a known issuer, trust is instant.
     * Unknown issuers prompt for confirmation.
     */
    public const TABLE_TRUSTED_ISSUERS = "
        CREATE TABLE IF NOT EXISTS trusted_issuers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            address VARCHAR(512) NOT NULL,
            name VARCHAR(255) NOT NULL,
            issuer_type ENUM('retail','exchange','employer','government','custom') NOT NULL DEFAULT 'custom',
            default_fee DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            max_auto_trust DECIMAL(18,2) DEFAULT NULL COMMENT 'Max amount to auto-trust without confirmation',
            logo_url VARCHAR(512) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            added_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_address (address(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    public static function getAllSchemas(): array
    {
        return [
            'voucher_codes' => self::TABLE_VOUCHER_CODES,
            'voucher_redemptions' => self::TABLE_VOUCHER_REDEMPTIONS,
            'trusted_issuers' => self::TABLE_TRUSTED_ISSUERS,
        ];
    }
}
