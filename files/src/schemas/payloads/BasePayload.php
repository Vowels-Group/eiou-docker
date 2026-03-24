<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Schemas\Payloads;

use Eiou\Core\SplitAmount;
use Eiou\Core\UserContext;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Contracts\CurrencyUtilityServiceInterface;
use Eiou\Contracts\TimeUtilityServiceInterface;
use Eiou\Contracts\ValidationUtilityServiceInterface;
use Eiou\Contracts\TransportServiceInterface;

/**
 * Abstract base class for all payload builders
 *
 * This class provides common functionality for building structured payloads
 * used throughout the eIOU protocol for different message types.
 *
 */

abstract class BasePayload
{
     /**
     * @var UserContext Current user data
     */
    protected UserContext $currentUser;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    protected UtilityServiceContainer $utilityContainer;

    /**
     * @var CurrencyUtilityServiceInterface Currency utility service
     */
    protected CurrencyUtilityServiceInterface $currencyUtility;

     /**
     * @var TimeUtilityServiceInterface Time utility service
     */
    protected TimeUtilityServiceInterface $timeUtility;

    /**
     * @var ValidationUtilityServiceInterface Validation utility service
     */
    protected ValidationUtilityServiceInterface $validationUtility;

    /**
     * @var TransportServiceInterface Transport utility service
     */
    protected TransportServiceInterface $transportUtility;

    /**
     * Constructor
     *
     * @param UserContext $currentUser The user context for the current session
     * @param UtilityServiceContainer $utilityContainer Utility Container
     */
    public function __construct(
        UserContext $currentUser,
        UtilityServiceContainer $utilityContainer
        )
    {
        $this->currentUser = $currentUser;
        $this->utilityContainer = $utilityContainer;
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
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
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
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

    /**
     * Serialize an amount for payload transmission.
     * Accepts SplitAmount or array with whole/frac keys.
     *
     * @param SplitAmount|array $amount The amount to serialize
     * @return array {whole: int, frac: int}
     */
    protected function serializeAmount($amount): array
    {
        if ($amount instanceof SplitAmount) {
            return $amount->toArray();
        }
        if (is_array($amount) && isset($amount['whole'])) {
            return ['whole' => (int) $amount['whole'], 'frac' => (int) ($amount['frac'] ?? 0)];
        }
        return SplitAmount::zero()->toArray();
    }

    /**
     * Deserialize an amount from payload data.
     * Accepts SplitAmount or {whole, frac} array.
     *
     * @param SplitAmount|array $amount The amount data from payload
     * @return SplitAmount
     */
    protected function deserializeAmount($amount): SplitAmount
    {
        if ($amount instanceof SplitAmount) {
            return $amount;
        }
        if (is_array($amount) && isset($amount['whole'])) {
            return SplitAmount::fromArray($amount);
        }
        return SplitAmount::zero();
    }
}
