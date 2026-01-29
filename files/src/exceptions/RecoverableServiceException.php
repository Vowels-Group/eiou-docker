<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Exceptions;

use Eiou\Core\ErrorCodes;
use Exception;

/**
 * Recoverable Service Exception
 *
 * For errors that might be resolved by retry or user action.
 * Examples: network timeouts, temporary unavailability, rate limiting.
 *
 * Default exit code: 0 (operation failed but not critically)
 *
 * @package Exceptions
 */
class RecoverableServiceException extends ServiceException
{
    /**
     * @var int Custom exit code (default 0)
     */
    private int $exitCode;

    /**
     * Constructor
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Error code from ErrorCodes class
     * @param array $context Additional context data
     * @param int|null $httpStatus HTTP status code (auto-detected from errorCode if null)
     * @param int $exitCode Exit code for CLI (default 0)
     * @param Exception|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::GENERAL_ERROR,
        array $context = [],
        ?int $httpStatus = null,
        int $exitCode = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
        $this->exitCode = $exitCode;
    }

    /**
     * Get the default exit code for CLI commands
     *
     * Recoverable errors return configurable exit code (default 0).
     *
     * @return int Exit code
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
