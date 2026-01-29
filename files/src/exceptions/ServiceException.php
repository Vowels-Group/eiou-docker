<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Exceptions;

use Eiou\Core\ErrorCodes;
use Exception;

/**
 * Base Service Exception
 *
 * Abstract base class for all service-level exceptions.
 * Replaces direct exit() calls in service methods, enabling proper
 * exception handling, error recovery, and testability.
 *
 * @package Exceptions
 */
abstract class ServiceException extends Exception
{
    /**
     * @var string Error code from ErrorCodes class
     */
    protected string $errorCode;

    /**
     * @var int HTTP status code for API responses
     */
    protected int $httpStatus;

    /**
     * @var array Additional context data for debugging
     */
    protected array $context;

    /**
     * Constructor
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Error code from ErrorCodes class
     * @param int|null $httpStatus HTTP status code (auto-detected from errorCode if null)
     * @param array $context Additional context data
     * @param Exception|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::GENERAL_ERROR,
        ?int $httpStatus = null,
        array $context = [],
        ?Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus ?? ErrorCodes::getHttpStatus($errorCode);
        $this->context = $context;
    }

    /**
     * Get the error code
     *
     * @return string Error code constant from ErrorCodes
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the HTTP status code
     *
     * @return int HTTP status code
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Get the error context
     *
     * @return array Context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the default exit code for CLI commands
     *
     * @return int Exit code (0 for success, non-zero for failure)
     */
    abstract public function getExitCode(): int;

    /**
     * Convert exception to array for JSON serialization
     *
     * @return array Exception data as array
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'title' => ErrorCodes::getTitle($this->errorCode),
                'httpStatus' => $this->httpStatus,
                'context' => $this->context
            ]
        ];
    }

    /**
     * Convert exception to JSON string
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
