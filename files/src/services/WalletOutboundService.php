<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Contracts\TransactionServiceInterface;
use Eiou\Core\ErrorCodes;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * WalletOutboundService
 *
 * The sandbox bridge that lets a sandboxed plugin trigger an outbound
 * EIOU send. The underlying `TransactionService::sendEiou()` lives
 * behind `open_basedir`, so a plugin can't reach it from its own FPM
 * pool — this service exists solely to cross that boundary on behalf
 * of plugins whose manifest's `core_services` allow-list opts them in.
 *
 * Explicitly NOT in scope here:
 *
 *   - Spending caps. The plugin is responsible for enforcing its own
 *     per-call and per-day limits against its own state. The host
 *     doesn't ship a policy framework because the trust model doesn't
 *     hold up: if you trust the plugin to spend the wallet's funds
 *     at all, you trust it to honour its own caps; if you don't, the
 *     plugin shouldn't be allow-listed here in the first place. Use
 *     the event-publish + operator-approval flow for plugins that
 *     need operator-mediated authorisation.
 *   - Audit log in core. The plugin keeps its own audit in its own
 *     DB schema — that's the right home for operationally-meaningful
 *     send history (correlated with the plugin's own customer state).
 *     The wallet's `transactions` table is the canonical record of
 *     what actually went on chain.
 *
 * Auth chain:
 *
 *   1. Gateway resolves the bearer token → plugin id, checks the
 *      plugin's `core_services` manifest for `WalletOutboundService.send`.
 *   2. Gateway invokes `setCallingPluginId()` before the call. The
 *      plugin can't spoof its id by passing it as an argument.
 *   3. `sendEiou()` auto-picks direct-vs-P2P based on whether the
 *      recipient resolves to a known contact — the plugin doesn't
 *      need to know which route gets taken.
 */
class WalletOutboundService implements PluginCallerAware
{
    public const SUPPORTED_CURRENCY_PATTERN = '/^[A-Z]{3,8}$/';
    public const AMOUNT_PATTERN = '/^\d+(\.\d+)?$/';
    public const RECIPIENT_PATTERN = '/^[A-Za-z0-9._:@-]{1,128}$/';

    /**
     * Cap on the free-form description string (the operator-facing
     * "[description]" argument on `eiou send`, what eventually lands
     * in the on-chain `transactions.description` column). NOT to be
     * confused with the database's `memo` column, which is the
     * routing-hash discriminator (`standard` / `contact` / etc.) and
     * is set internally by `sendEiou` — never user-supplied.
     */
    public const DESCRIPTION_MAX_BYTES = 256;

    private TransactionServiceInterface $transactionService;
    private ?Logger $logger;
    private ?string $callingPluginId = null;

    public function __construct(
        TransactionServiceInterface $transactionService,
        ?Logger $logger = null
    ) {
        $this->transactionService = $transactionService;
        $this->logger = $logger;
    }

    public function setCallingPluginId(?string $pluginId): void
    {
        $this->callingPluginId = $pluginId;
    }

    /**
     * Send an outbound transaction on behalf of the calling plugin.
     *
     * Argument order mirrors the operator-facing CLI: `eiou send
     * <recipient> <amount> <currency> [description]`. A plugin call
     * with the historical (currency, amount, recipient, description)
     * order passes the recipient where the currency validator expects
     * 3-8 uppercase letters and the call fails with a confusing
     * message (e.g. `currency 'alice' must match /^[A-Z]{3,8}$/`).
     * Matching the CLI order at the host boundary eliminates that
     * footgun.
     *
     * `$description` is the free-form text that lands in the on-chain
     * `transactions.description` column — distinct from the internal
     * `transactions.memo` field, which is a routing-hash discriminator
     * set by `sendEiou` itself and is not a plugin-controllable input.
     *
     * @param string      $recipientAddress Recipient address or contact name.
     * @param string      $amount           Decimal string. Must be positive.
     * @param string      $currency         ISO-like currency code (^[A-Z]{3,8}$).
     * @param string|null $description      Optional free-form description. Capped at 256 bytes.
     *
     * @return array{ok:true, txid:?string} On success. txid may be null when
     *                                       the underlying sendEiou queued the
     *                                       work async without producing a txid.
     *
     * @throws RuntimeException         When no caller id is set (gateway bypass attempt).
     * @throws InvalidArgumentException On malformed arguments.
     */
    #[PluginCallable(
        description: 'Send EIOU on behalf of the wallet to a recipient address or contact. Argument order matches the `eiou send` CLI: recipient, amount, currency, optional description. The plugin is responsible for its own authorisation and audit; the host only crosses the sandbox boundary into the existing sendEiou path (which auto-picks direct or P2P routing based on whether the recipient resolves to a known contact). Returns {ok:true, txid} on success or throws on downstream refusal.',
        ratePerMinute: 60
    )]
    public function send(string $recipientAddress, string $amount, string $currency, ?string $description = null): array
    {
        if ($this->callingPluginId === null) {
            // Defence in depth — only the gateway should reach this
            // method, and it always sets the caller id first.
            throw new RuntimeException('WalletOutboundService::send requires gateway-injected caller id');
        }
        $pluginId = $this->callingPluginId;

        $this->validateArguments($currency, $amount, $recipientAddress, $description);

        $capture = new CapturingCliOutputManager();
        // sendEiou expects a positional CLI-style request:
        //   [_, _, recipient, amount, currency, description]
        $request = ['', '', $recipientAddress, $amount, $currency, $description ?? ''];
        try {
            $this->transactionService->sendEiou($request, $capture);
        } catch (Throwable $e) {
            $this->log('error', 'plugin_outbound_send_threw', [
                'plugin' => $pluginId, 'currency' => $currency, 'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('outbound send failed: ' . $e->getMessage(), 0, $e);
        }

        $err = $capture->getError();
        if ($err !== null) {
            $this->log('warning', 'plugin_outbound_send_refused', [
                'plugin' => $pluginId, 'currency' => $currency, 'amount' => $amount,
                'recipient' => $recipientAddress, 'error' => $err['message'],
            ]);
            throw new RuntimeException('outbound send refused: ' . $err['message'], 0);
        }

        $success = $capture->getSuccess();
        $txid = $success['data']['txid'] ?? null;

        $this->log('info', 'plugin_outbound_send_ok', [
            'plugin' => $pluginId, 'currency' => $currency,
            'amount' => $amount, 'recipient' => $recipientAddress, 'txid' => $txid,
        ]);

        return ['ok' => true, 'txid' => $txid];
    }

    private function validateArguments(string $currency, string $amount, string $recipient, ?string $description): void
    {
        if (preg_match(self::SUPPORTED_CURRENCY_PATTERN, $currency) !== 1) {
            throw new InvalidArgumentException("currency '{$currency}' must match " . self::SUPPORTED_CURRENCY_PATTERN);
        }
        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1 || $this->isPositive($amount) === false) {
            throw new InvalidArgumentException("amount '{$amount}' must be a positive decimal string");
        }
        if (preg_match(self::RECIPIENT_PATTERN, $recipient) !== 1) {
            throw new InvalidArgumentException("recipient '{$recipient}' must match " . self::RECIPIENT_PATTERN);
        }
        if ($description !== null && strlen($description) > self::DESCRIPTION_MAX_BYTES) {
            throw new InvalidArgumentException("description exceeds " . self::DESCRIPTION_MAX_BYTES . " bytes");
        }
    }

    private function isPositive(string $value): bool
    {
        if (function_exists('bccomp')) {
            return bccomp($value, '0', 8) > 0;
        }
        return (float) $value > 0;
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        try {
            $this->logger->{$level}($message, $context);
        } catch (Throwable $e) {
            // never let logging take down the send path
        }
    }
}

/**
 * CapturingCliOutputManager
 *
 * Subclass of CliOutputManager that buffers success/error calls instead
 * of echoing to stdout. Used by WalletOutboundService to thread
 * sendEiou's existing CLI-style output API into a structured return
 * shape that the plugin gateway can marshal.
 *
 * Lives in this file (not under cli/) because it's a private adapter
 * for one specific service — no other caller needs a capturing output
 * manager, and surfacing it as a generally-available class would
 * invite drift between its capture semantics and CliOutputManager's
 * evolving public API.
 */
class CapturingCliOutputManager extends CliOutputManager
{
    /** @var array{message:string, data:mixed}|null */
    private ?array $capturedSuccess = null;

    /** @var array{message:string, code:string, status:?int, data:array}|null */
    private ?array $capturedError = null;

    public function __construct()
    {
        parent::__construct([], null);
    }

    public function success(string $textMessage, $data = null, ?string $jsonMessage = null): void
    {
        $this->capturedSuccess = ['message' => $textMessage, 'data' => $data];
    }

    public function error(
        string $message,
        string $code = ErrorCodes::GENERAL_ERROR,
        ?int $status = null,
        array $additionalData = []
    ): void {
        $this->capturedError = [
            'message' => $message,
            'code'    => $code,
            'status'  => $status,
            'data'    => $additionalData,
        ];
    }

    public function info(string $message, $data = null): void
    {
        // Discard — the plugin doesn't need info-level transit chatter.
    }

    /** @return array{message:string, data:mixed}|null */
    public function getSuccess(): ?array
    {
        return $this->capturedSuccess;
    }

    /** @return array{message:string, code:string, status:?int, data:array}|null */
    public function getError(): ?array
    {
        return $this->capturedError;
    }
}
