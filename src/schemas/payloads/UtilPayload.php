<?php

declare(strict_types=1);

namespace EIOU\Schemas\Payloads;

use EIOU\Context\UserContext;

/**
 * Utility payload builder
 *
 * Handles building utility payloads for error responses, validation failures,
 * and other system messages. This class uses static methods as these utilities
 * don't always require user context.
 */
class UtilPayload
{
    /**
     * Build insufficient balance rejection payload
     *
     * @param float $availableFunds Available funds in cents
     * @param float $requestedAmount Requested amount in cents
     * @param float $creditLimit Credit limit in cents
     * @param float $fundsOnHold Funds on hold in cents
     * @return array The insufficient balance payload
     */
    public static function buildInsufficientBalance(
        float $availableFunds,
        float $requestedAmount,
        float $creditLimit,
        float $fundsOnHold
    ): array {
        return [
            'status' => 'rejected',
            'message' => 'Insufficient balance or credit',
            'credit_limit' => self::formatCurrency($creditLimit),
            'current_balance' => self::formatCurrency($availableFunds),
            'funds_on_hold' => self::formatCurrency($fundsOnHold),
            'requested_amount' => self::formatCurrency($requestedAmount),
        ];
    }

    /**
     * Build invalid transaction ID rejection payload
     *
     * @param array $previousTxResult Previous transaction result
     * @param array $request Current request data
     * @return array The invalid transaction ID payload
     */
    public static function buildInvalidTransactionId(array $previousTxResult, array $request): array
    {
        $expectedTxid = $previousTxResult['txid'] ?? 'unknown';
        $receivedTxid = $request['previousTxid'] ?? 'none';

        return [
            'status' => 'rejected',
            'message' => "Previous transaction ID does not match. Expecting: {$expectedTxid} Received: {$receivedTxid}",
            'expected' => $expectedTxid,
            'received' => $receivedTxid,
        ];
    }

    /**
     * Build invalid request level rejection payload
     *
     * @param array $request Request data containing request levels
     * @return array The invalid request level payload
     */
    public static function buildInvalidRequestLevel(array $request): array
    {
        return [
            'status' => 'rejected',
            'message' => 'Invalid request level',
            'request_level' => $request['requestLevel'] ?? null,
            'max_request_level' => $request['maxRequestLevel'] ?? null,
        ];
    }

    /**
     * Build invalid source rejection payload
     *
     * @param array $message Message data
     * @param UserContext|null $userContext Optional user context for resolving addresses
     * @return array The invalid source payload
     */
    public static function buildInvalidSource(array $message, ?UserContext $userContext = null): array
    {
        $receiver = self::resolveUserAddress($message['senderAddress'] ?? 'unknown', $userContext);

        return [
            'status' => 'rejected',
            'message' => "Message rejected due to being from unknown source to receiver {$receiver}",
            'sender_address' => $message['senderAddress'] ?? null,
        ];
    }

    /**
     * Build generic error payload
     *
     * @param string $errorMessage The error message
     * @param string $errorCode Optional error code
     * @param array $additionalData Optional additional error data
     * @return array The error payload
     */
    public static function buildError(string $errorMessage, string $errorCode = 'GENERAL_ERROR', array $additionalData = []): array
    {
        return array_merge([
            'status' => 'error',
            'error_code' => $errorCode,
            'message' => $errorMessage,
            'timestamp' => time(),
        ], $additionalData);
    }

    /**
     * Build validation error payload
     *
     * @param array $validationErrors Array of validation errors
     * @return array The validation error payload
     */
    public static function buildValidationError(array $validationErrors): array
    {
        return [
            'status' => 'error',
            'error_code' => 'VALIDATION_ERROR',
            'message' => 'Validation failed',
            'errors' => $validationErrors,
            'timestamp' => time(),
        ];
    }

    /**
     * Build timeout error payload
     *
     * @param string $operation The operation that timed out
     * @param int $timeoutSeconds The timeout duration in seconds
     * @return array The timeout error payload
     */
    public static function buildTimeout(string $operation, int $timeoutSeconds): array
    {
        return [
            'status' => 'error',
            'error_code' => 'TIMEOUT',
            'message' => "Operation '{$operation}' timed out after {$timeoutSeconds} seconds",
            'operation' => $operation,
            'timeout' => $timeoutSeconds,
            'timestamp' => time(),
        ];
    }

    /**
     * Build rate limit exceeded payload
     *
     * @param int $limit The rate limit
     * @param int $window The time window in seconds
     * @param int|null $retryAfter Seconds until retry is allowed
     * @return array The rate limit payload
     */
    public static function buildRateLimitExceeded(int $limit, int $window, ?int $retryAfter = null): array
    {
        return [
            'status' => 'error',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'message' => "Rate limit of {$limit} requests per {$window} seconds exceeded",
            'limit' => $limit,
            'window' => $window,
            'retry_after' => $retryAfter,
            'timestamp' => time(),
        ];
    }

    /**
     * Build maintenance mode payload
     *
     * @param string|null $estimatedEndTime Optional estimated end time
     * @param string|null $message Optional maintenance message
     * @return array The maintenance mode payload
     */
    public static function buildMaintenanceMode(?string $estimatedEndTime = null, ?string $message = null): array
    {
        return [
            'status' => 'maintenance',
            'message' => $message ?? 'System is currently under maintenance',
            'estimated_end_time' => $estimatedEndTime,
            'timestamp' => time(),
        ];
    }

    /**
     * Format currency from cents to dollars with USD suffix
     *
     * @param float $amountInCents Amount in cents
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    private static function formatCurrency(float $amountInCents, string $currency = 'USD'): string
    {
        $amountInDollars = $amountInCents / 100;
        return number_format($amountInDollars, 2) . ' ' . $currency;
    }

    /**
     * Resolve user address for display
     *
     * @param string $address The address to resolve
     * @param UserContext|null $userContext Optional user context
     * @return string The resolved address
     */
    private static function resolveUserAddress(string $address, ?UserContext $userContext = null): string
    {
        if ($userContext) {
            $user = $userContext->getUser();
            if ($user && method_exists($user, 'getAddress')) {
                return $user->getAddress();
            }
        }
        return $address;
    }
}