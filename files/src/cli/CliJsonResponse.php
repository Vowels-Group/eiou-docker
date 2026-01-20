<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * CLI JSON Response Formatter
 *
 * Provides standardized JSON response format for all CLI commands.
 * Based on industry best practices from kubectl, docker CLI, gh CLI, and RFC 9457.
 *
 * @package Cli
 */

require_once __DIR__ . '/../core/ErrorCodes.php';

class CliJsonResponse
{
    /** @var string Current CLI version */
    private const VERSION = '1.0.0';

    /** @var string|null The command that was executed */
    private ?string $command = null;

    /** @var float Start time for execution timing */
    private float $startTime;

    /** @var string|null Node identifier */
    private ?string $nodeId = null;

    /**
     * Constructor
     *
     * @param string|null $command The CLI command being executed
     * @param string|null $nodeId Optional node identifier
     */
    public function __construct(?string $command = null, ?string $nodeId = null)
    {
        $this->command = $command;
        $this->nodeId = $nodeId;
        $this->startTime = microtime(true);
    }

    /**
     * Set the command name
     *
     * @param string $command The command name
     * @return self
     */
    public function setCommand(string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * Set the node identifier
     *
     * @param string $nodeId The node ID
     * @return self
     */
    public function setNodeId(string $nodeId): self
    {
        $this->nodeId = $nodeId;
        return $this;
    }

    /**
     * Build success response
     *
     * @param mixed $data The response data
     * @param string|null $message Optional success message
     * @return string JSON encoded response
     */
    public function success($data = null, ?string $message = null): string
    {
        $response = [
            'success' => true,
            'data' => $data,
            'metadata' => $this->buildMetadata()
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return $this->encode($response);
    }

    /**
     * Build error response (RFC 9457 compliant)
     *
     * @param string $message Human-readable error message
     * @param string $code Machine-readable error code (use ErrorCodes constants)
     * @param int|null $status HTTP-style status code (auto-detected if null)
     * @param array $additionalData Additional error context
     * @return string JSON encoded response
     */
    public function error(
        string $message,
        string $code = ErrorCodes::GENERAL_ERROR,
        ?int $status = null,
        array $additionalData = []
    ): string {
        // Auto-detect HTTP status from error code if not provided
        $httpStatus = $status ?? ErrorCodes::getHttpStatus($code);

        $response = [
            'success' => false,
            'error' => array_merge([
                'type' => 'https://eiou.org/docs/errors#' . strtolower(str_replace('_', '-', $code)),
                'title' => ErrorCodes::getTitle($code),
                'status' => $httpStatus,
                'detail' => $message,
                'code' => $code,
                'timestamp' => $this->getTimestamp()
            ], $additionalData),
            'metadata' => $this->buildMetadata()
        ];

        return $this->encode($response);
    }

    /**
     * Build validation error response
     *
     * @param array $errors Array of validation errors
     * @param string|null $message Optional overall message
     * @return string JSON encoded response
     */
    public function validationError(array $errors, ?string $message = null): string
    {
        return $this->error(
            $message ?? 'One or more fields failed validation',
            ErrorCodes::VALIDATION_ERROR,
            null,
            ['validation_errors' => $errors]
        );
    }

    /**
     * Build list/array response with optional pagination
     *
     * @param array $items The list items
     * @param int|null $total Total number of items (for pagination)
     * @param int|null $page Current page number
     * @param int|null $perPage Items per page
     * @return string JSON encoded response
     */
    public function list(array $items, ?int $total = null, ?int $page = null, ?int $perPage = null): string
    {
        $response = [
            'success' => true,
            'data' => $items,
            'metadata' => $this->buildMetadata()
        ];

        // Add pagination if provided
        if ($total !== null) {
            $response['pagination'] = [
                'total' => $total,
                'count' => count($items),
                'page' => $page ?? 1,
                'per_page' => $perPage ?? count($items),
                'has_more' => $total > (($page ?? 1) * ($perPage ?? count($items)))
            ];
        }

        return $this->encode($response);
    }

    /**
     * Build a table response (for formatted display data)
     *
     * @param array $headers Column headers
     * @param array $rows Row data
     * @param string|null $title Optional table title
     * @return string JSON encoded response
     */
    public function table(array $headers, array $rows, ?string $title = null): string
    {
        $response = [
            'success' => true,
            'data' => [
                'headers' => $headers,
                'rows' => $rows,
                'row_count' => count($rows)
            ],
            'metadata' => $this->buildMetadata()
        ];

        if ($title !== null) {
            $response['data']['title'] = $title;
        }

        return $this->encode($response);
    }

    /**
     * Build transaction response
     *
     * @param string $status Transaction status (success, rejected, pending)
     * @param string $message Human-readable message
     * @param array $transactionData Transaction details
     * @return string JSON encoded response
     */
    public function transaction(string $status, string $message, array $transactionData = []): string
    {
        $response = [
            'success' => $status === 'success' || $status === 'accepted',
            'status' => $status,
            'message' => $message,
            'data' => $transactionData,
            'metadata' => $this->buildMetadata()
        ];

        return $this->encode($response);
    }

    /**
     * Build settings response
     *
     * @param array $settings Current settings
     * @param string|null $message Optional message
     * @return string JSON encoded response
     */
    public function settings(array $settings, ?string $message = null): string
    {
        $response = [
            'success' => true,
            'data' => [
                'settings' => $settings
            ],
            'metadata' => $this->buildMetadata()
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return $this->encode($response);
    }

    /**
     * Build user info response
     *
     * @param array $userInfo User information
     * @return string JSON encoded response
     */
    public function userInfo(array $userInfo): string
    {
        return $this->success($userInfo);
    }

    /**
     * Build contact response
     *
     * @param array|null $contact Contact data (null if not found)
     * @param string|null $message Optional message
     * @return string JSON encoded response
     */
    public function contact(?array $contact, ?string $message = null): string
    {
        if ($contact === null) {
            return $this->error(
                $message ?? 'Contact not found',
                ErrorCodes::CONTACT_NOT_FOUND
            );
        }

        return $this->success($contact, $message);
    }

    /**
     * Build help response
     *
     * @param array $commands Available commands
     * @param string|null $specificCommand Details for specific command
     * @return string JSON encoded response
     */
    public function help(array $commands, ?string $specificCommand = null): string
    {
        $data = [
            'commands' => $commands
        ];

        if ($specificCommand !== null) {
            $data['requested_command'] = $specificCommand;
        }

        return $this->success($data);
    }

    /**
     * Build rate limit exceeded response
     *
     * @param int $retryAfter Seconds until retry is allowed
     * @param string $command The rate-limited command
     * @return string JSON encoded response
     */
    public function rateLimitExceeded(int $retryAfter, string $command): string
    {
        return $this->error(
            "Rate limit exceeded for command '$command'. Please try again in $retryAfter seconds.",
            ErrorCodes::RATE_LIMIT_EXCEEDED,
            null,
            [
                'retry_after' => $retryAfter,
                'command' => $command
            ]
        );
    }

    /**
     * Build wallet exists response
     *
     * @return string JSON encoded response
     */
    public function walletExists(): string
    {
        return $this->error(
            'Wallet already exists',
            ErrorCodes::WALLET_EXISTS
        );
    }

    /**
     * Build wallet required response
     *
     * @return string JSON encoded response
     */
    public function walletRequired(): string
    {
        return $this->error(
            "Wallet does not exist. Please run the 'generate' command first.",
            ErrorCodes::WALLET_NOT_FOUND
        );
    }

    /**
     * Build balance response
     *
     * @param array $balances Balance data
     * @param string|null $address Optional address filter
     * @return string JSON encoded response
     */
    public function balances(array $balances, ?string $address = null): string
    {
        $data = [
            'balances' => $balances
        ];

        if ($address !== null) {
            $data['filter'] = ['address' => $address];
        }

        return $this->success($data);
    }

    /**
     * Build transaction history response
     *
     * @param array $transactions Transaction history
     * @param string $direction sent|received|all
     * @param int $total Total number of transactions
     * @param int $displayed Number displayed
     * @return string JSON encoded response
     */
    public function transactionHistory(
        array $transactions,
        string $direction,
        int $total,
        int $displayed
    ): string {
        return $this->success([
            'transactions' => $transactions,
            'direction' => $direction,
            'total' => $total,
            'displayed' => $displayed
        ]);
    }

    /**
     * Build metadata for response
     *
     * @return array Metadata array
     */
    private function buildMetadata(): array
    {
        $metadata = [
            'timestamp' => $this->getTimestamp(),
            'version' => self::VERSION,
            'execution_time_ms' => round((microtime(true) - $this->startTime) * 1000, 2)
        ];

        if ($this->command !== null) {
            $metadata['command'] = $this->command;
        }

        if ($this->nodeId !== null) {
            $metadata['node_id'] = $this->nodeId;
        }

        return $metadata;
    }

    /**
     * Get ISO 8601 timestamp
     *
     * @return string Formatted timestamp
     */
    private function getTimestamp(): string
    {
        return date('c'); // ISO 8601 format
    }

    /**
     * Encode response to JSON
     *
     * @param array $response Response array
     * @return string JSON encoded string
     */
    private function encode(array $response): string
    {
        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Create a new instance (factory method)
     *
     * @param string|null $command Command name
     * @param string|null $nodeId Node identifier
     * @return self
     */
    public static function create(?string $command = null, ?string $nodeId = null): self
    {
        return new self($command, $nodeId);
    }
}
