<?php
/**
 * Abstract base class for all payload builders
 *
 * This class provides common functionality for building structured payloads
 * used throughout the EIOU protocol for different message types.
 *
 */

require_once dirname(__DIR__,3) . '/src/core/UserContext.php';

abstract class BasePayload
{
    protected UserContext $currentUser;

    /**
     * Constructor
     *
     * @param UserContext $currentUser The user context for the current session
     */
    public function __construct(UserContext $currentUser)
    {
        $this->currentUser = $currentUser;
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
