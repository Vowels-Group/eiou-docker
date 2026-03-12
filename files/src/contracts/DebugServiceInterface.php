<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Debug Service Interface
 *
 * Defines the contract for debug management services.
 */
interface DebugServiceInterface
{
    /**
     * Get debug context information
     *
     * Returns a JSON-encoded string containing context information including:
     * - Command line arguments (if CLI)
     * - Server request information
     * - User information (if initialized)
     * - Database connection information
     * - PHP environment details
     * - Current script details
     *
     * @return string JSON-encoded context information
     */
    public function getContext(): string;

    /**
     * Output a debug message
     *
     * Logs the message to the database if debug mode is enabled.
     * Outputs to console if running in CLI mode and level is not 'SILENT'.
     *
     * @param string $message The message to output
     * @param string $level The log level (default: 'ECHO')
     * @return void
     */
    public function output(string $message, string $level = 'ECHO'): void;

    /**
     * Setup error logging configuration
     *
     * Configures PHP error reporting and logging settings.
     * Sets up log directory and file with appropriate permissions.
     *
     * @return void
     */
    public function setupErrorLogging(): void;
}
