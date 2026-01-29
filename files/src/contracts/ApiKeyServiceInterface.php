<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * API Key Service Interface
 *
 * Defines the contract for CLI management of API keys.
 * Provides commands for creating, listing, deleting, disabling, and enabling API keys.
 */
interface ApiKeyServiceInterface
{
    /**
     * Handle CLI command for API key management
     *
     * Routes to appropriate action based on command line arguments:
     * - create: Create a new API key
     * - list: List all API keys
     * - delete: Delete an API key
     * - disable: Disable an API key
     * - enable: Enable an API key
     * - help: Show help information
     *
     * @param array $argv Command line arguments
     * @return void
     */
    public function handleCommand(array $argv): void;
}
