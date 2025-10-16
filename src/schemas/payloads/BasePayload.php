<?php

declare(strict_types=1);

namespace EIOU\Schemas\Payloads;

use EIOU\Context\UserContext;

/**
 * Abstract base class for all payload builders
 *
 * This class provides common functionality for building structured payloads
 * used throughout the EIOU protocol for different message types.
 */
abstract class BasePayload
{
    protected UserContext $userContext;

    /**
     * Constructor
     *
     * @param UserContext $userContext The user context for the current session
     */
    public function __construct(UserContext $userContext)
    {
        $this->userContext = $userContext;
    }

    /**
     * Build the main payload
     *
     * @param array $data Input data for building the payload
     * @return array The built payload
     */
    abstract public function build(array $data): array;

    /**
     * Validate a payload structure
     *
     * @param array $payload The payload to validate
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    protected function validate(array $payload): void
    {
        // Common validation logic that can be overridden by child classes
        if (empty($payload)) {
            throw new \InvalidArgumentException('Payload cannot be empty');
        }
    }

    /**
     * Add common metadata to payload
     *
     * @param array $payload The payload to enhance
     * @return array The payload with metadata
     */
    protected function addMetadata(array $payload): array
    {
        $user = $this->userContext->getUser();

        return array_merge($payload, [
            'timestamp' => time(),
            'user_address' => $user ? $user->getAddress() : null,
            'node_id' => $this->userContext->getNodeId() ?? null,
        ]);
    }

    /**
     * Get the current user's address
     *
     * @return string|null The user's address or null if not available
     */
    protected function getUserAddress(): ?string
    {
        $user = $this->userContext->getUser();
        return $user ? $user->getAddress() : null;
    }

    /**
     * Get the current user's name
     *
     * @return string|null The user's name or null if not available
     */
    protected function getUserName(): ?string
    {
        $user = $this->userContext->getUser();
        return $user ? $user->getName() : null;
    }

    /**
     * Ensure required fields are present in data
     *
     * @param array $data The data to check
     * @param array $requiredFields List of required field names
     * @return void
     * @throws \InvalidArgumentException If a required field is missing
     */
    protected function ensureRequiredFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Required field '{$field}' is missing from payload data");
            }
        }
    }

    /**
     * Sanitize string data
     *
     * @param mixed $value The value to sanitize
     * @return string The sanitized string
     */
    protected function sanitizeString($value): string
    {
        if (!is_string($value)) {
            $value = (string) $value;
        }
        return trim($value);
    }

    /**
     * Sanitize numeric data
     *
     * @param mixed $value The value to sanitize
     * @return float|int The sanitized number
     */
    protected function sanitizeNumber($value)
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Value must be numeric, got: " . gettype($value));
        }
        return is_int($value) ? (int) $value : (float) $value;
    }
}