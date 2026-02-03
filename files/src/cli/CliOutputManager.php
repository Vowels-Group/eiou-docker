<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Cli;

use Eiou\Core\ErrorCodes;
use Eiou\Utils\SecureLogger;

/**
 * CLI Output Manager
 *
 * Manages CLI output format based on user preferences.
 * Supports both human-readable text and JSON output modes.
 *
 * @package Cli
 */
class CliOutputManager
{
    /** @var bool Whether JSON output mode is enabled */
    private bool $jsonMode = false;

    /** @var CliJsonResponse JSON response formatter */
    private CliJsonResponse $jsonResponse;

    /** @var string|null Current command being executed */
    private ?string $command = null;

    /** @var string|null Node identifier */
    private ?string $nodeId = null;

    /** @var bool Whether to include metadata in JSON output */
    private bool $includeMetadata = true;

    /** @var bool Whether help flag was requested */
    private bool $helpRequested = false;

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /**
     * Constructor
     *
     * @param array $argv CLI arguments
     * @param string|null $nodeId Optional node identifier
     */
    public function __construct(array $argv = [], ?string $nodeId = null)
    {
        $this->nodeId = $nodeId;
        $this->parseFlags($argv);
        $this->jsonResponse = new CliJsonResponse($this->command, $this->nodeId);
    }

    /**
     * Get singleton instance
     *
     * @param array $argv CLI arguments (only used on first call)
     * @param string|null $nodeId Node identifier (only used on first call)
     * @return self
     */
    public static function getInstance(array $argv = [], ?string $nodeId = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($argv, $nodeId);
        }
        return self::$instance;
    }

    /**
     * Reset singleton instance (for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Parse CLI flags for output format options
     *
     * @param array $argv CLI arguments
     */
    private function parseFlags(array $argv): void
    {
        // Extract command (first argument after script name)
        if (isset($argv[1]) && strpos($argv[1], '-') !== 0) {
            $this->command = strtolower($argv[1]);
        }

        foreach ($argv as $arg) {
            if ($arg === '--json' || $arg === '-j') {
                $this->jsonMode = true;
            }
            if ($arg === '--no-metadata') {
                $this->includeMetadata = false;
            }
        }
    }

    /**
     * Check if JSON mode is enabled
     *
     * @return bool
     */
    public function isJsonMode(): bool
    {
        return $this->jsonMode;
    }

    /**
     * Enable or disable JSON mode
     *
     * @param bool $enabled Whether to enable JSON mode
     * @return self
     */
    public function setJsonMode(bool $enabled): self
    {
        $this->jsonMode = $enabled;
        return $this;
    }

    /**
     * Set the current command
     *
     * @param string $command Command name
     * @return self
     */
    public function setCommand(string $command): self
    {
        $this->command = $command;
        $this->jsonResponse->setCommand($command);
        return $this;
    }

    /**
     * Get the JSON response formatter
     *
     * @return CliJsonResponse
     */
    public function getJsonResponse(): CliJsonResponse
    {
        return $this->jsonResponse;
    }

    /**
     * Output success message/data
     *
     * @param string $textMessage Human-readable message
     * @param mixed $data Data for JSON output
     * @param string|null $jsonMessage Optional different message for JSON
     */
    public function success(string $textMessage, $data = null, ?string $jsonMessage = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->success($data, $jsonMessage ?? $textMessage) . "\n";
        } else {
            echo $textMessage . "\n";
        }
    }

    /**
     * Output error message
     *
     * @param string $message Error message
     * @param string $code Error code for JSON
     * @param int $status HTTP-style status code
     * @param array $additionalData Additional error context
     */
    public function error(
        string $message,
        string $code = ErrorCodes::GENERAL_ERROR,
        ?int $status = null,
        array $additionalData = []
    ): void {
        // Auto-detect HTTP status if not provided
        $httpStatus = $status ?? ErrorCodes::getHttpStatus($code);

        // Log the error to the log file
        SecureLogger::error($message, [
            'code' => $code,
            'status' => $httpStatus,
            'additional_data' => $additionalData,
            'command' => $this->command
        ]);

        if ($this->jsonMode) {
            echo $this->jsonResponse->error($message, $code, $httpStatus, $additionalData) . "\n";
        } else {
            echo "Error: " . $message . "\n";
        }
    }

    /**
     * Output validation error
     *
     * @param string $field Field that failed validation
     * @param string $message Validation error message
     */
    public function validationError(string $field, string $message): void
    {
        // Log the validation error to the log file
        SecureLogger::error("Validation error: $message", [
            'code' => ErrorCodes::VALIDATION_ERROR,
            'field' => $field,
            'command' => $this->command
        ]);

        if ($this->jsonMode) {
            echo $this->jsonResponse->validationError([
                ['field' => $field, 'message' => $message]
            ]) . "\n";
        } else {
            echo "Error: " . $message . "\n";
        }
    }

    /**
     * Output info message (non-error, non-success)
     *
     * @param string $message Info message
     * @param mixed $data Optional data for JSON
     */
    public function info(string $message, $data = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->success($data ?? ['info' => $message]) . "\n";
        } else {
            echo $message . "\n";
        }
    }

    /**
     * Output user information
     *
     * @param array $userInfo User information array
     * @param callable|null $textFormatter Optional custom text formatter
     */
    public function userInfo(array $userInfo, ?callable $textFormatter = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->userInfo($userInfo) . "\n";
        } else {
            if ($textFormatter !== null) {
                echo $textFormatter($userInfo);
            } else {
                echo "User Information:\n";
                foreach ($userInfo as $key => $value) {
                    if (is_array($value)) {
                        echo "\t$key:\n";
                        foreach ($value as $k => $v) {
                            echo "\t\t$k: $v\n";
                        }
                    } else {
                        echo "\t$key: $value\n";
                    }
                }
            }
        }
    }

    /**
     * Output settings
     *
     * @param array $settings Settings array
     * @param string|null $message Optional message
     */
    public function settings(array $settings, ?string $message = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->settings($settings, $message) . "\n";
        } else {
            if ($message !== null) {
                echo $message . "\n";
            }
            echo "Current Settings:\n";
            foreach ($settings as $key => $value) {
                echo "\t$key: $value\n";
            }
        }
    }

    /**
     * Output contact information
     *
     * @param array|null $contact Contact data
     * @param string $notFoundMessage Message if contact not found
     */
    public function contact(?array $contact, string $notFoundMessage = 'Contact not found'): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->contact($contact, $notFoundMessage) . "\n";
        } else {
            if ($contact === null) {
                echo $notFoundMessage . "\n";
            } else {
                echo "Contact Information:\n";
                foreach ($contact as $key => $value) {
                    echo "\t$key: $value\n";
                }
            }
        }
    }

    /**
     * Output balance information
     *
     * @param array $balances Balance data
     * @param callable|null $textFormatter Optional custom text formatter
     */
    public function balances(array $balances, ?callable $textFormatter = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->balances($balances) . "\n";
        } else {
            if ($textFormatter !== null) {
                echo $textFormatter($balances);
            } else {
                foreach ($balances as $balance) {
                    echo sprintf("Currency: %s, Balance: %.2f\n",
                        $balance['currency'] ?? 'N/A',
                        $balance['total_balance'] ?? 0
                    );
                }
            }
        }
    }

    /**
     * Output transaction history
     *
     * @param array $transactions Transaction data
     * @param string $direction sent|received|all
     * @param int $total Total count
     * @param int $displayed Displayed count
     * @param callable|null $textFormatter Optional custom text formatter
     */
    public function transactionHistory(
        array $transactions,
        string $direction,
        int $total,
        int $displayed,
        ?callable $textFormatter = null
    ): void {
        if ($this->jsonMode) {
            echo $this->jsonResponse->transactionHistory($transactions, $direction, $total, $displayed) . "\n";
        } else {
            if ($textFormatter !== null) {
                echo $textFormatter($transactions, $direction, $total, $displayed);
            } else {
                echo "Transaction History ($direction):\n";
                echo "-------------------------------------------\n";
                foreach ($transactions as $tx) {
                    echo sprintf("%s | %s | %s | %.2f %s\n",
                        $tx['date'] ?? 'N/A',
                        $tx['type'] ?? 'N/A',
                        $tx['counterparty'] ?? 'N/A',
                        $tx['amount'] ?? 0,
                        $tx['currency'] ?? 'N/A'
                    );
                }
                echo "-------------------------------------------\n";
                echo "Displaying $displayed out of $total total transactions.\n";
            }
        }
    }

    /**
     * Output list of items
     *
     * @param array $items Items to list
     * @param string $title List title
     * @param callable|null $textFormatter Optional custom text formatter
     */
    public function list(array $items, string $title = '', ?callable $textFormatter = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->list($items, count($items)) . "\n";
        } else {
            if ($title !== '') {
                echo $title . "\n";
            }
            if ($textFormatter !== null) {
                echo $textFormatter($items);
            } else {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        echo "\t" . json_encode($item) . "\n";
                    } else {
                        echo "\t$item\n";
                    }
                }
            }
        }
    }

    /**
     * Output help information
     *
     * @param array $commands Available commands
     * @param string|null $specificCommand Specific command requested
     */
    public function help(array $commands, ?string $specificCommand = null): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->help($commands, $specificCommand) . "\n";
        } else {
            if ($specificCommand !== null) {
                echo "Command:\n";
                if (isset($commands[$specificCommand])) {
                    echo "\t$specificCommand - " . $commands[$specificCommand]['description'] . "\n";
                    if (isset($commands[$specificCommand]['usage'])) {
                        echo "\tUsage: " . $commands[$specificCommand]['usage'] . "\n";
                    }
                } else {
                    echo "\tCommand does not exist.\n";
                }
            } else {
                echo "Available commands:\n";
                foreach ($commands as $name => $details) {
                    if (is_array($details)) {
                        echo "\t$name - " . ($details['description'] ?? '') . "\n";
                    } else {
                        echo "\t$name - $details\n";
                    }
                }
            }
        }
    }

    /**
     * Output rate limit exceeded message
     *
     * @param int $retryAfter Seconds until retry allowed
     * @param string $command The rate-limited command
     */
    public function rateLimitExceeded(int $retryAfter, string $command): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->rateLimitExceeded($retryAfter, $command) . "\n";
        } else {
            echo "Rate limit exceeded. Please try again in $retryAfter seconds.\n";
        }
    }

    /**
     * Output wallet exists message
     */
    public function walletExists(): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->walletExists() . "\n";
        } else {
            echo "Wallet already exists";
        }
    }

    /**
     * Output wallet required message
     */
    public function walletRequired(): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->walletRequired() . "\n";
        } else {
            echo "Wallet does not exist, Please run the 'generate' command from the terminal.\n";
        }
    }

    /**
     * Output a table
     *
     * @param array $headers Table headers
     * @param array $rows Table rows
     * @param string $title Optional table title
     */
    public function table(array $headers, array $rows, string $title = ''): void
    {
        if ($this->jsonMode) {
            echo $this->jsonResponse->table($headers, $rows, $title !== '' ? $title : null) . "\n";
        } else {
            if ($title !== '') {
                echo $title . "\n";
            }
            echo "-------------------------------------------\n";

            // Calculate column widths
            $widths = [];
            foreach ($headers as $i => $header) {
                $widths[$i] = strlen($header);
            }
            foreach ($rows as $row) {
                foreach ($row as $i => $cell) {
                    $widths[$i] = max($widths[$i] ?? 0, strlen((string)$cell));
                }
            }

            // Print header row
            $headerLine = '';
            foreach ($headers as $i => $header) {
                $headerLine .= str_pad($header, $widths[$i] + 2);
            }
            echo $headerLine . "\n";
            echo "-------------------------------------------\n";

            // Print data rows
            foreach ($rows as $row) {
                $line = '';
                foreach ($row as $i => $cell) {
                    $line .= str_pad((string)$cell, ($widths[$i] ?? 10) + 2);
                }
                echo $line . "\n";
            }
            echo "-------------------------------------------\n";
        }
    }

    /**
     * Output raw text (bypasses JSON mode)
     *
     * @param string $text Text to output
     */
    public function raw(string $text): void
    {
        echo $text;
    }

    /**
     * Remove CLI flags from argv for processing
     *
     * @param array $argv Original CLI arguments
     * @return array Cleaned arguments without output flags
     */
    public static function cleanArgv(array $argv): array
    {
        return array_values(array_filter($argv, function ($arg) {
            return !in_array($arg, ['--json', '-j', '--no-metadata']);
        }));
    }
}
