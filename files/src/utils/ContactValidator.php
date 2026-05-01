<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

/**
 * ContactValidator — bulk validation for the standard contact field
 * set (address, name, fee, credit, currency).
 *
 * `ContactController` (and the contact CLI / API call sites) used to
 * inline a 5-step validate-then-redirect ladder per handler — five
 * `InputValidator::validate<Field>()` calls each followed by a
 * MessageHelper::redirectMessage on failure. ~16 occurrences across
 * the controller alone.
 *
 * This helper collapses all five into one call. The handler then
 * issues a single redirect on the first failure (preserving the
 * legacy behavior — bail on first error, don't accumulate).
 *
 * Behavior contract preserved verbatim from the inlined ladder:
 *
 *   1. Validate fields in declared order (address, name, fee, credit,
 *      currency). The first failure short-circuits — we don't run
 *      remaining validators.
 *   2. Error message format: "Invalid {label}: {validator-error}"
 *      where {label} matches the human label the inline code used:
 *      "address", "contact name", "fee", "credit limit", "currency".
 *   3. Validators that aren't present in the input array are skipped
 *      (so a caller validating only address gets address-only output).
 *   4. Result `values` are the validators' returned `value` field
 *      (sanitized / coerced form).
 */
class ContactValidator
{
    /**
     * @param array<string, mixed> $fields  Subset of:
     *                                      address|name|fee|credit|currency
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     errorField: string|null,
     *     values: array<string, mixed>
     * }
     */
    public static function validateContactFields(array $fields): array
    {
        // Order matters — matches the legacy inlined ladder so error
        // messages surface in the same priority a user is used to.
        $rules = [
            'address'  => ['fn' => [InputValidator::class, 'validateAddress'],     'label' => 'address'],
            'name'     => ['fn' => [InputValidator::class, 'validateContactName'], 'label' => 'contact name'],
            'fee'      => ['fn' => [InputValidator::class, 'validateFeePercent'],  'label' => 'fee'],
            'credit'   => ['fn' => [InputValidator::class, 'validateCreditLimit'], 'label' => 'credit limit'],
            'currency' => ['fn' => [InputValidator::class, 'validateCurrency'],    'label' => 'currency'],
        ];

        $values = [];
        foreach ($rules as $key => $rule) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $result = ($rule['fn'])($fields[$key]);
            if (!($result['valid'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => 'Invalid ' . $rule['label'] . ': ' . ($result['error'] ?? 'unknown'),
                    'errorField' => $key,
                    'values' => [],
                ];
            }
            $values[$key] = $result['value'] ?? $fields[$key];
        }

        return [
            'ok' => true,
            'error' => null,
            'errorField' => null,
            'values' => $values,
        ];
    }

    /**
     * Convenience for handlers that only need to validate the
     * address (delete/block/unblock). Equivalent to
     * `validateContactFields(['address' => $address])`.
     */
    public static function validateAddressOnly(string $address): array
    {
        return self::validateContactFields(['address' => $address]);
    }
}
