<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Exceptions;

use Eiou\Core\ErrorCodes;
use Exception;

/**
 * Validation Service Exception
 *
 * For input validation errors with field-specific information.
 * Examples: invalid address format, invalid name, invalid parameters.
 *
 * Default exit code: 1
 *
 * @package Exceptions
 */
class ValidationServiceException extends ServiceException
{
    /**
     * @var string|null The field that failed validation
     */
    private ?string $field;

    /**
     * Constructor
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Error code from ErrorCodes class
     * @param string|null $field The field that failed validation
     * @param int|null $httpStatus HTTP status code (auto-detected from errorCode if null)
     * @param array $context Additional context data
     * @param Exception|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::VALIDATION_ERROR,
        ?string $field = null,
        ?int $httpStatus = null,
        array $context = [],
        ?Exception $previous = null
    ) {
        // Add field to context if provided
        if ($field !== null) {
            $context['field'] = $field;
        }

        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
        $this->field = $field;
    }

    /**
     * Get the field that failed validation
     *
     * @return string|null Field name or null
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * Get the default exit code for CLI commands
     *
     * Validation errors always return exit code 1.
     *
     * @return int Exit code 1
     */
    public function getExitCode(): int
    {
        return 1;
    }

    /**
     * Convert exception to array for JSON serialization
     *
     * Overrides parent to include field information.
     *
     * @return array Exception data as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        if ($this->field !== null) {
            $data['error']['field'] = $this->field;
        }

        return $data;
    }
}
