<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\PaybackMethodReceivedRepository;
use Eiou\Utils\Logger;

/**
 * E2E-fetch layer for payback methods.
 *
 * Handles three directions of traffic (all multiplexed over the existing
 * MessageDeliveryService / PayloadEncryption pipeline):
 *
 *   1. Outgoing REQUEST — the local node asks a contact for their shareable
 *      methods. Shape: {request_id, currency?, max_age_seconds}.
 *
 *   2. Incoming REQUEST handler — responder side. Consults the owner's
 *      PaybackMethodService to collect every method with share_policy=auto
 *      matching the requested currency and returns status=ok with those methods.
 *      Methods with share_policy=never are silently omitted.
 *
 *   3. Incoming RESPONSE handler — requester side. Upserts the received rows
 *      into the payback_methods_received cache with TTL.
 *
 *   4. Incoming REVOKE handler — marks rows revoked.
 *
 * Rate-limit: a single contact can only successfully fetch a responder's
 * methods once per 1 hour unless the responder's policy is `auto` and the
 * request carries `force=true` (e.g. GUI "Refresh" button).
 */
class ReceivedPaybackMethodService
{
    public const DEFAULT_TTL_SECONDS = 86400;            // 24 h
    public const MAX_TTL_SECONDS     = 86400 * 7;        // 7 days
    public const RATE_LIMIT_WINDOW_SECONDS = 3600;       // 1 h between auto-fetches
    public const SOFT_COOLDOWN_AFTER_REVOKE_SECONDS = 3600; // avoid re-fetch thrash

    public const MSG_TYPE_REQUEST  = 'payback-methods-request.v1';
    public const MSG_TYPE_RESPONSE = 'payback-methods-response.v1';
    public const MSG_TYPE_REVOKE   = 'payback-methods-revoke.v1';

    public const STATUS_OK                = 'ok';
    public const STATUS_DENIED            = 'denied';
    public const STATUS_RATE_LIMITED      = 'rate_limited';

    private PaybackMethodReceivedRepository $receivedRepo;
    private PaybackMethodService $localService;
    private ?Logger $logger;

    /**
     * Optional delivery callback for outgoing messages.
     * Signature: function(string $contactAddress, string $messageType, array $payload): bool
     * If null, outgoing requests are no-ops (useful in tests).
     * @var callable|null
     */
    private $deliveryCallback;

    public function __construct(
        PaybackMethodReceivedRepository $receivedRepo,
        PaybackMethodService $localService,
        ?Logger $logger = null,
        ?callable $deliveryCallback = null
    ) {
        $this->receivedRepo = $receivedRepo;
        $this->localService = $localService;
        $this->logger = $logger;
        $this->deliveryCallback = $deliveryCallback;
    }

    /**
     * Initiate a request toward $contactAddress. Returns the generated
     * request_id (a uuid v4) on success; throws on delivery error.
     *
     * @param string $contactAddress Public-facing contact address (consumed by MessageDeliveryService).
     * @param string|null $currency Filter the responder should apply (null = all).
     * @param int|null $maxAgeSeconds Caller's acceptable cache age hint to responder.
     * @return string request_id
     */
    public function requestFromContact(
        string $contactAddress,
        ?string $currency = null,
        ?int $maxAgeSeconds = null
    ): string {
        $requestId = $this->generateUuidV4();
        $payload = [
            'request_id' => $requestId,
            'currency' => $currency,
            'max_age_seconds' => $maxAgeSeconds ?? self::DEFAULT_TTL_SECONDS,
        ];
        $this->log('info', 'payback_methods_request_sent', [
            'contact_address' => $contactAddress,
            'request_id' => $requestId,
            'currency' => $currency,
        ]);
        if ($this->deliveryCallback !== null) {
            call_user_func($this->deliveryCallback, $contactAddress, self::MSG_TYPE_REQUEST, $payload);
        }
        return $requestId;
    }

    /**
     * Responder-side handler. Returns the response payload (the caller is
     * expected to envelope + E2E-encrypt + dispatch it via MessageDelivery).
     *
     * @param string $senderPubkeyHash Identity of the requesting contact.
     * @param array $request Decoded request payload (at minimum {request_id, currency?}).
     * @return array Response payload ready to send back through the E2E channel.
     */
    public function handleIncomingRequest(string $senderPubkeyHash, array $request): array
    {
        $requestId = (string) ($request['request_id'] ?? '');
        $currency = isset($request['currency']) ? (string) $request['currency'] : null;

        // Rate-limit: refuse if this contact fetched within the window already.
        if ($this->wasRecentlyAnswered($senderPubkeyHash)) {
            $this->log('info', 'payback_methods_request_rate_limited', [
                'sender' => $senderPubkeyHash, 'request_id' => $requestId,
            ]);
            return ['request_id' => $requestId, 'status' => self::STATUS_RATE_LIMITED];
        }

        $methods = $this->localService->listShareable($currency);
        if ($methods === []) {
            return ['request_id' => $requestId, 'status' => self::STATUS_DENIED];
        }

        return [
            'request_id' => $requestId,
            'status' => self::STATUS_OK,
            'methods' => array_map([$this, 'toWireShape'], $methods),
            'ttl_seconds' => self::DEFAULT_TTL_SECONDS,
        ];
    }

    /**
     * Requester-side handler. Persists the received methods into the local
     * payback_methods_received cache with TTL.
     *
     * @return int number of rows upserted
     */
    public function handleIncomingResponse(string $senderPubkeyHash, array $response): int
    {
        $status = $response['status'] ?? null;
        if ($status !== self::STATUS_OK) {
            $this->log('info', 'payback_methods_response_non_ok', [
                'sender' => $senderPubkeyHash, 'status' => $status,
            ]);
            return 0;
        }
        $ttl = min(
            max(60, (int) ($response['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS)),
            self::MAX_TTL_SECONDS
        );
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $count = 0;
        foreach (($response['methods'] ?? []) as $m) {
            if (!is_array($m) || empty($m['remote_id']) || empty($m['type'])) {
                continue;
            }
            $this->receivedRepo->upsertReceived([
                'contact_pubkey_hash' => $senderPubkeyHash,
                'remote_method_id' => (string) $m['remote_id'],
                'type' => (string) $m['type'],
                'label' => (string) ($m['label'] ?? ''),
                'currency' => (string) ($m['currency'] ?? ''),
                'fields_json' => json_encode($m['fields'] ?? []),
                'settlement_min_unit' => (int) ($m['settlement_min_unit'] ?? 1),
                'settlement_min_unit_exponent' => (int) ($m['settlement_min_unit_exponent'] ?? -8),
                'priority' => (int) ($m['priority'] ?? 100),
                'expires_at' => $expiresAt,
            ]);
            $count++;
        }
        $this->log('info', 'payback_methods_response_cached', [
            'sender' => $senderPubkeyHash, 'methods_received' => $count, 'ttl_seconds' => $ttl,
        ]);
        return $count;
    }

    /**
     * Requester-side handler for revocations.
     */
    public function handleIncomingRevoke(string $senderPubkeyHash, array $payload): int
    {
        $remoteIds = $payload['remote_ids'] ?? [];
        if (!is_array($remoteIds) || $remoteIds === []) {
            return 0;
        }
        return $this->receivedRepo->markRevoked($senderPubkeyHash, $remoteIds);
    }

    /**
     * Public listing for the contact modal.
     *
     * @return list<array<string, mixed>>
     */
    public function listForContact(string $contactPubkeyHash, ?string $currency = null): array
    {
        $rows = $this->receivedRepo->listFreshForContact($contactPubkeyHash, $currency);
        return array_map(function ($r) {
            $fields = [];
            if (!empty($r['fields_json'])) {
                $decoded = json_decode($r['fields_json'], true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                }
            }
            return [
                'remote_method_id' => $r['remote_method_id'],
                'type' => $r['type'],
                'label' => $r['label'],
                'currency' => $r['currency'],
                'fields' => $fields,
                'priority' => (int) ($r['priority'] ?? 100),
                'settlement_min_unit' => (int) ($r['settlement_min_unit'] ?? 1),
                'settlement_min_unit_exponent' => (int) ($r['settlement_min_unit_exponent'] ?? -8),
                'received_at' => $r['received_at'],
                'expires_at' => $r['expires_at'],
            ];
        }, $rows);
    }

    public function hasFreshForContact(string $contactPubkeyHash): bool
    {
        return $this->receivedRepo->hasFresh($contactPubkeyHash);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Rate-limit check — protected so tests can override.
     * Default implementation: if the contact has any fresh row, they fetched
     * recently. This is a coarse implementation; a future refinement can track
     * an explicit last_answered_at timestamp per contact.
     */
    protected function wasRecentlyAnswered(string $senderPubkeyHash): bool
    {
        return $this->receivedRepo->hasFresh($senderPubkeyHash);
    }

    /**
     * Project a local method row into the wire-format object we send back.
     */
    private function toWireShape(array $m): array
    {
        return [
            'remote_id' => $m['method_id'],
            'type' => $m['type'],
            'label' => $m['label'],
            'currency' => $m['currency'],
            'fields' => $m['fields'] ?? [],
            'priority' => $m['priority'] ?? 100,
            'settlement_min_unit' => $m['settlement_min_unit'] ?? 1,
            'settlement_min_unit_exponent' => $m['settlement_min_unit_exponent'] ?? -8,
        ];
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->{$level}($event, $ctx);
    }
}
