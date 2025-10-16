<?php
/**
 * Abstract base class for all payload builders
 *
 * This class provides common functionality for building structured payloads
 * used throughout the EIOU protocol for different message types.
 *
 * IMPORTANT: This codebase does NOT use namespaces. All classes are loaded via require_once.
 */

require_once __DIR__ . '/../../core/UserContext.php';

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
     * Get the user's public key from context
     *
     * @return string|null
     */
    protected function getUserPublicKey(): ?string
    {
        return $this->userContext->getPublicKey();
    }

    /**
     * Get the user's private key from context
     *
     * @return string|null
     */
    protected function getUserPrivateKey(): ?string
    {
        return $this->userContext->getPrivateKey();
    }

    /**
     * Get the user's Tor address from context
     *
     * @return string|null
     */
    protected function getUserTorAddress(): ?string
    {
        return $this->userContext->getTorAddress();
    }

    /**
     * Get a user configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    protected function getUserConfig(string $key, $default = null)
    {
        return $this->userContext->get($key, $default);
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
