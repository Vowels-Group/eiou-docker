<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Exceptions;

use Eiou\Core\ErrorCodes;
use Exception;

/**
 * Fatal Service Exception
 *
 * For unrecoverable errors that should terminate the operation.
 * Examples: missing wallet, corrupted data, system-level failures,
 * unauthorized access attempts.
 *
 * Default exit code: 1
 *
 * @package Exceptions
 */
class FatalServiceException extends ServiceException
{
    /**
     * Constructor
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Error code from ErrorCodes class
     * @param array $context Additional context data
     * @param int|null $httpStatus HTTP status code (auto-detected from errorCode if null)
     * @param Exception|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::INTERNAL_ERROR,
        array $context = [],
        ?int $httpStatus = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
    }

    /**
     * Get the default exit code for CLI commands
     *
     * Fatal errors always return exit code 1.
     *
     * @return int Exit code 1
     */
    public function getExitCode(): int
    {
        return 1;
    }
}
