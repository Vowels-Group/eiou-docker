<?php
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
     * @return string JSON encoded insufficient balance payload
     */
    public static function buildInsufficientBalance(
        float $availableFunds,
        float $requestedAmount,
        float $creditLimit,
        float $fundsOnHold
    ): string {
        return json_encode([
            'status' => 'rejected',
            'message' => 'Insufficient balance or credit',
            'credit_limit' => formatCurrency($creditLimit),
            'current_balance' => formatCurrency($availableFunds),
            'funds_on_hold' => formatCurrency($fundsOnHold),
            'requested_amount' => formatCurrency($requestedAmount),
        ]);
    }

    /**
     * Build invalid transaction ID rejection payload
     *
     * @param string $previousTxResult Previous transaction result
     * @param array $request Current request data
     * @return string JSON encoded invalid transaction ID payload
     */
    public static function buildInvalidTransactionId(string $previousTxResult, array $request): string
    {
        $expectedTxid = $previousTxResult['txid'] ?? 'unknown';
        $receivedTxid = $request['previousTxid'] ?? 'none';

        return json_encode([
            'status' => 'rejected',
            'message' => "Previous transaction ID does not match. Expecting: {$expectedTxid} Received: {$receivedTxid}",
            'expected' => $expectedTxid,
            'received' => $receivedTxid,
        ]);
    }

    /**
     * Build invalid request level rejection payload
     *
     * @param array $request Request data containing request levels
     * @return string JSON encoded invalid request level payload
     */
    public static function buildInvalidRequestLevel(array $request): string
    {
        return json_encode([
            'status' => 'rejected',
            'message' => 'Invalid request level',
            'request_level' => $request['requestLevel'] ?? null,
            'max_request_level' => $request['maxRequestLevel'] ?? null,
        ]);
    }

    /**
     * Build invalid source rejection payload
     *
     * @param array $message Message data
     * @return string JSON encoded  invalid source payload
     */
    public static function buildInvalidSource(array $message): string
    {
        $receiver = resolveUserAddressForTransport($message['senderAddress']);

        return json_encode([
            'status' => 'rejected',
            'message' => "Message rejected due to being from unknown source to receiver {$receiver}",
            'sender_address' => $message['senderAddress'] ?? null,
        ]);
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
}