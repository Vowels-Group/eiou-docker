<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Wallet Service Interface
 *
 * Defines the contract for wallet management operations.
 * Handles wallet existence checks and related wallet business logic.
 */
interface WalletServiceInterface
{
    /**
     * Check if wallet exists
     *
     * Verifies that a wallet exists before processing requests.
     * If no wallet exists and the request is not 'generate' or 'restore',
     * outputs an error message and exits.
     *
     * @param string $request Request type (e.g., 'generate', 'restore', 'send', etc.)
     * @return void
     */
    public function checkWalletExists(string $request): void;
}
