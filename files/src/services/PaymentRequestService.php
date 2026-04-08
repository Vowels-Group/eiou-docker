<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\PaymentRequestRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Services\MessageDeliveryService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\SplitAmount;
use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Cli\CliOutputManager;
use Eiou\Gui\Helpers\MessageHelper;

/**
 * Payment Request Service
 *
 * Handles creation, delivery, approval, and decline of payment requests
 * between contacts. Payment requests allow a user to ask a contact to
 * pay them a specific amount; the contact can approve (triggering sendEiou)
 * or decline.
 *
 * Flow:
 *   Requester: create() → stores locally as outgoing/pending → sends message to recipient
 *   Recipient: handleIncomingRequest() → stores locally as incoming/pending → shows in UI
 *   Recipient: approve() → sendEiou() to requester → stores as approved → sends response
 *   Recipient: decline() → stores as declined → sends response
 *   Requester: handleIncomingResponse() → updates outgoing request status
 */
class PaymentRequestService
{
    private PaymentRequestRepository $paymentRequestRepository;
    private ContactRepository $contactRepository;
    private AddressRepository $addressRepository;
    private TransactionService $transactionService;
    private TransportUtilityService $transportUtility;
    private UserContext $currentUser;
    private Logger $logger;
    private ?MessageDeliveryService $messageDeliveryService = null;

    public function setMessageDeliveryService(MessageDeliveryService $service): void
    {
        $this->messageDeliveryService = $service;
    }

    public function __construct(
        PaymentRequestRepository $paymentRequestRepository,
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        TransactionService $transactionService,
        TransportUtilityService $transportUtility,
        UserContext $currentUser,
        Logger $logger
    ) {
        $this->paymentRequestRepository = $paymentRequestRepository;
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->transactionService = $transactionService;
        $this->transportUtility = $transportUtility;
        $this->currentUser = $currentUser;
        $this->logger = $logger;
    }

    /**
     * Create and send a payment request to a contact.
     *
     * @param string      $contactName  Recipient contact name
     * @param string      $amount       Amount string (e.g. "25.00")
     * @param string      $currency     Currency code (e.g. "USD")
     * @param string|null $description  Optional memo for the requester
     * @param string|null $addressType  Preferred transport (tor/https/http), null = auto
     * @return array ['success' => bool, 'error' => string, 'request_id' => string]
     */
    public function create(string $contactName, string $amount, string $currency, ?string $description, ?string $addressType = null): array
    {
        // Validate amount
        $amountValidation = InputValidator::validateAmount($amount, $currency);
        if (!$amountValidation['valid']) {
            return ['success' => false, 'error' => 'Invalid amount: ' . $amountValidation['error']];
        }

        // Validate currency
        $currencyValidation = InputValidator::validateCurrency($currency);
        if (!$currencyValidation['valid']) {
            return ['success' => false, 'error' => 'Invalid currency: ' . $currencyValidation['error']];
        }

        // Look up contact — check for duplicate names
        $allMatches = $this->contactRepository->lookupAllByName($contactName);
        if (empty($allMatches)) {
            return ['success' => false, 'error' => 'Contact not found: ' . htmlspecialchars($contactName)];
        }
        if (count($allMatches) > 1) {
            $addresses = array_map(function ($c) {
                return $c['name'] . ' (' . ($c['http'] ?? $c['https'] ?? $c['tor'] ?? 'unknown') . ')';
            }, $allMatches);
            return [
                'success' => false,
                'error' => 'Multiple contacts named "' . htmlspecialchars($contactName) . '". Use an address instead: ' . implode(', ', $addresses),
                'code' => 'multiple_matches'
            ];
        }
        $contact = $allMatches[0];

        if (($contact['status'] ?? '') !== 'accepted') {
            return ['success' => false, 'error' => 'Contact must be accepted before sending payment requests'];
        }

        // Resolve recipient address
        $recipientAddress = $this->resolveContactAddress($contact, $addressType);
        if (!$recipientAddress) {
            return ['success' => false, 'error' => 'No valid address found for contact'];
        }

        // Get my outgoing address (matched to recipient transport type)
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($recipientAddress);

        // Generate unique request_id
        $requestId = hash('sha256', ($this->currentUser->getPublicKey() ?? '') . $recipientAddress . $amount . $currency . microtime(true));

        // Parse amount
        $splitAmount = SplitAmount::fromString($amountValidation['value']);

        // Store locally as outgoing/pending
        $this->paymentRequestRepository->createRequest([
            'request_id'            => $requestId,
            'direction'             => 'outgoing',
            'status'                => 'pending',
            'requester_pubkey_hash' => $this->currentUser->getPublicKeyHash() ?? '',
            'requester_address'     => $myAddress,
            'contact_name'          => $contact['name'] ?? $contactName,
            'recipient_pubkey_hash' => $contact['pubkey_hash'] ?? '',
            'amount_whole'          => $splitAmount->whole,
            'amount_frac'           => $splitAmount->frac,
            'currency'              => $currencyValidation['value'],
            'description'           => (!empty($description)) ? $description : null,
            'created_at'            => date('Y-m-d H:i:s.u'),
        ]);

        // Build and send payment_request message to recipient
        $payload = [
            'type'            => 'message',
            'typeMessage'     => 'payment_request',
            'action'          => 'request',
            'requestId'       => $requestId,
            'senderAddress'   => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey() ?? '',
            'amount'          => [
                'whole' => $splitAmount->whole,
                'frac'  => $splitAmount->frac,
            ],
            'currency'        => $currencyValidation['value'],
            'description'     => $description,
        ];

        $this->logger->info('Sending payment request message', [
            'request_id'        => $requestId,
            'recipient_address' => $recipientAddress,
            'amount'            => (string)$splitAmount,
            'currency'          => $currencyValidation['value'],
        ]);

        $this->deliverMessage($recipientAddress, $payload, $requestId, 'create');

        return ['success' => true, 'request_id' => $requestId];
    }

    /**
     * Approve an incoming payment request.
     * Triggers a full sendEiou() to the requester, then marks request approved.
     *
     * @param string $requestId The request_id to approve
     * @return array ['success' => bool, 'error' => string, 'message' => string, 'txid' => string|null]
     */
    public function approve(string $requestId): array
    {
        $request = $this->paymentRequestRepository->getByRequestId($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Payment request not found'];
        }
        if ($request['direction'] !== 'incoming') {
            return ['success' => false, 'error' => 'Can only approve incoming requests'];
        }
        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Request is no longer pending (status: ' . $request['status'] . ')'];
        }

        $requesterAddress = $request['requester_address'] ?? '';
        if (empty($requesterAddress)) {
            return ['success' => false, 'error' => 'No return address on request — cannot send payment'];
        }

        // Build the amount string for CLI send
        $splitAmount = ($request['amount'] instanceof SplitAmount)
            ? $request['amount']
            : SplitAmount::fromDb((int)$request['amount_whole'], (int)$request['amount_frac']);
        $amountStr = (string)$splitAmount;
        $currency = $request['currency'];
        $rawDescription = $request['description'] ?? null;

        // Build transaction description: "payment: {description}", but drop the prefix
        // if the combined string would exceed the 255-char description limit
        $description = null;
        if (!empty($rawDescription)) {
            $prefixed = 'payment: ' . $rawDescription;
            $description = strlen($prefixed) <= 255 ? $prefixed : $rawDescription;
        }

        // Trigger sendEiou via the existing transaction pipeline
        CliOutputManager::resetInstance();
        $argv = ['eiou', 'send', $requesterAddress, $amountStr, $currency, $description, '--json'];
        $outputManager = new CliOutputManager($argv);

        $output = '';
        ob_start();
        try {
            $this->transactionService->sendEiou($argv, $outputManager);
            $output = ob_get_clean() ?? '';
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->logger->error('PaymentRequestService::approve sendEiou exception', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'Send failed: ' . $e->getMessage()];
        }

        $messageInfo = MessageHelper::parseCliJsonOutput($output);
        if ($messageInfo['type'] !== 'success') {
            return ['success' => false, 'error' => $messageInfo['message']];
        }

        // Extract txid from response data if available
        $txid = $messageInfo['data']['txid'] ?? null;

        // Update status
        $this->paymentRequestRepository->updateStatus($requestId, 'approved', [
            'responded_at'  => date('Y-m-d H:i:s.u'),
            'resulting_txid' => $txid,
        ]);

        // Send response message back to requester (best-effort)
        $this->sendResponseMessage($request, 'approved', $txid);

        return [
            'success' => true,
            'message' => $messageInfo['message'],
            'txid'    => $txid,
        ];
    }

    /**
     * Decline an incoming payment request.
     *
     * @param string $requestId The request_id to decline
     * @return array ['success' => bool, 'error' => string]
     */
    public function decline(string $requestId): array
    {
        $request = $this->paymentRequestRepository->getByRequestId($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Payment request not found'];
        }
        if ($request['direction'] !== 'incoming') {
            return ['success' => false, 'error' => 'Can only decline incoming requests'];
        }
        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Request is no longer pending'];
        }

        $this->paymentRequestRepository->updateStatus($requestId, 'declined', [
            'responded_at' => date('Y-m-d H:i:s.u'),
        ]);

        $this->sendResponseMessage($request, 'declined', null);

        return ['success' => true];
    }

    /**
     * Cancel an outgoing payment request (before it is approved or declined).
     *
     * @param string $requestId The request_id to cancel
     * @return array ['success' => bool, 'error' => string]
     */
    public function cancel(string $requestId): array
    {
        $request = $this->paymentRequestRepository->getByRequestId($requestId);
        if (!$request) {
            return ['success' => false, 'error' => 'Payment request not found'];
        }
        if ($request['direction'] !== 'outgoing') {
            return ['success' => false, 'error' => 'Can only cancel outgoing requests'];
        }
        if ($request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Request cannot be cancelled (status: ' . $request['status'] . ')'];
        }

        $this->paymentRequestRepository->updateStatus($requestId, 'cancelled');

        // Notify the receiver so their incoming request is also cancelled
        $this->sendCancellationMessage($request);

        return ['success' => true];
    }

    /**
     * Handle an incoming payment_request message (action=request) from a remote node.
     * Called by MessageService::handleMessage() when typeMessage=payment_request and action=request.
     *
     * @param array $message Decoded message payload
     * @return void
     */
    public function handleIncomingRequest(array $message): void
    {
        $requestId = $message['requestId'] ?? '';
        if (empty($requestId)) {
            $this->logger->warning('Incoming payment_request missing requestId');
            return;
        }

        // Idempotent — skip if already stored
        if ($this->paymentRequestRepository->getByRequestId($requestId) !== null) {
            return;
        }

        $amountData = $message['amount'] ?? [];
        $whole = (int)($amountData['whole'] ?? 0);
        $frac  = (int)($amountData['frac']  ?? 0);
        $currency = trim($message['currency'] ?? '');

        if (empty($currency) || $whole < 0) {
            $this->logger->warning('Incoming payment_request has invalid amount/currency', [
                'request_id' => $requestId,
                'currency'   => $currency,
                'whole'      => $whole,
            ]);
            return;
        }

        $senderPubkey = $message['senderPublicKey'] ?? '';
        $requesterHash = !empty($senderPubkey) ? hash('sha256', $senderPubkey) : '';

        // Try to look up the requester's display name
        $contactName = null;
        if ($requesterHash) {
            $contact = $this->contactRepository->lookupByPubkeyHash($requesterHash);
            $contactName = $contact['name'] ?? null;
        }

        $this->paymentRequestRepository->createRequest([
            'request_id'            => $requestId,
            'direction'             => 'incoming',
            'status'                => 'pending',
            'requester_pubkey_hash' => $requesterHash,
            'requester_address'     => $message['senderAddress'] ?? '',
            'contact_name'          => $contactName,
            'recipient_pubkey_hash' => $this->currentUser->getPublicKeyHash() ?? '',
            'amount_whole'          => $whole,
            'amount_frac'           => $frac,
            'currency'              => $currency,
            'description'           => (!empty($message['description'])) ? $message['description'] : null,
            'created_at'            => date('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Handle an incoming payment_request message (action=response) from a remote node.
     * Updates the outgoing request with the recipient's approval or decline decision.
     *
     * @param array $message Decoded message payload
     * @return void
     */
    public function handleIncomingResponse(array $message): void
    {
        $requestId = $message['requestId'] ?? '';
        $outcome   = $message['outcome']   ?? '';

        if (empty($requestId) || !in_array($outcome, ['approved', 'declined'], true)) {
            $this->logger->warning('Incoming payment_request response has missing/invalid fields', [
                'request_id' => $requestId,
                'outcome'    => $outcome,
            ]);
            return;
        }

        $request = $this->paymentRequestRepository->getByRequestId($requestId);
        if (!$request || $request['direction'] !== 'outgoing') {
            return;
        }

        // Accept responses for pending requests, and also for cancelled requests
        // when the receiver already approved (payment wins — money is in flight).
        $status = $request['status'];
        if ($status !== 'pending' && !($status === 'cancelled' && $outcome === 'approved')) {
            return;
        }

        $extra = ['responded_at' => date('Y-m-d H:i:s.u')];
        if ($outcome === 'approved' && !empty($message['txid'])) {
            $extra['resulting_txid'] = $message['txid'];
        }

        $this->paymentRequestRepository->updateStatus($requestId, $outcome, $extra);
    }

    /**
     * Handle an incoming cancellation from the requester (action=cancel).
     * Updates our incoming request to cancelled, but only if still pending.
     * If we already approved/paid, the cancellation is ignored — payment wins.
     *
     * @param array $message Decoded message payload
     * @return void
     */
    public function handleIncomingCancel(array $message): void
    {
        $requestId = $message['requestId'] ?? '';
        if (empty($requestId)) {
            $this->logger->warning('Incoming payment_request cancel missing requestId');
            return;
        }

        $request = $this->paymentRequestRepository->getByRequestId($requestId);
        if (!$request || $request['direction'] !== 'incoming') {
            return;
        }

        // Only cancel if still pending — if already approved/declined, ignore
        if ($request['status'] !== 'pending') {
            $this->logger->info('Ignoring cancellation for non-pending request', [
                'request_id' => $requestId,
                'status'     => $request['status'],
            ]);
            return;
        }

        $this->paymentRequestRepository->updateStatus($requestId, 'cancelled', [
            'responded_at' => date('Y-m-d H:i:s.u'),
        ]);

        $this->logger->info('Incoming payment request cancelled by requester', [
            'request_id' => $requestId,
        ]);
    }

    /**
     * Get all requests for display in the UI (incoming + outgoing).
     *
     * @param int $limit Max records per direction
     * @return array ['incoming' => [...], 'outgoing' => [...]]
     */
    public function getAllForDisplay(int $limit = 50): array
    {
        return [
            'incoming' => $this->paymentRequestRepository->getAllIncoming($limit),
            'outgoing' => $this->paymentRequestRepository->getAllOutgoing($limit),
        ];
    }

    /**
     * Count pending incoming requests (for notification badge).
     */
    public function countPendingIncoming(): int
    {
        return $this->paymentRequestRepository->countPendingIncoming();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the best transport address for a contact.
     * Priority: tor > https > http (or preferred type if specified)
     */
    private function resolveContactAddress(array $contact, ?string $preferredType = null): ?string
    {
        $pubkeyHash = $contact['pubkey_hash'] ?? '';
        if (empty($pubkeyHash)) {
            return null;
        }

        $addresses = $this->addressRepository->lookupByPubkeyHash($pubkeyHash);
        if (empty($addresses)) {
            return null;
        }

        $order = ['tor', 'https', 'http'];
        if ($preferredType && in_array($preferredType, $order, true)) {
            array_unshift($order, $preferredType);
            $order = array_unique($order);
        }

        foreach ($order as $type) {
            if (!empty($addresses[$type])) {
                return $addresses[$type];
            }
        }

        return null;
    }

    /**
     * Send a cancellation message to the receiver of an outgoing payment request.
     * Looks up the receiver's address from their pubkey hash.
     */
    private function sendCancellationMessage(array $request): void
    {
        $recipientPubkeyHash = $request['recipient_pubkey_hash'] ?? '';
        if (empty($recipientPubkeyHash)) {
            return;
        }

        $addresses = $this->addressRepository->lookupByPubkeyHash($recipientPubkeyHash);
        if (empty($addresses)) {
            $this->logger->warning('Cannot send cancellation: no addresses for recipient', [
                'request_id' => $request['request_id'],
            ]);
            return;
        }

        // Pick best address (tor > https > http)
        $recipientAddress = null;
        foreach (['tor', 'https', 'http'] as $type) {
            if (!empty($addresses[$type])) {
                $recipientAddress = $addresses[$type];
                break;
            }
        }
        if ($recipientAddress === null) {
            return;
        }

        $myAddress = $this->transportUtility->resolveUserAddressForTransport($recipientAddress);
        $payload = [
            'type'            => 'message',
            'typeMessage'     => 'payment_request',
            'action'          => 'cancel',
            'requestId'       => $request['request_id'],
            'senderAddress'   => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey() ?? '',
        ];

        $this->logger->info('Sending payment request cancellation', [
            'request_id'        => $request['request_id'],
            'recipient_address' => $recipientAddress,
        ]);

        $this->deliverMessage($recipientAddress, $payload, $request['request_id'], 'cancel');
    }

    /**
     * Send a payment_request response message back to the requester (best-effort).
     */
    private function sendResponseMessage(array $request, string $outcome, ?string $txid): void
    {
        $requesterAddress = $request['requester_address'] ?? '';
        if (empty($requesterAddress)) {
            return;
        }

        $myAddress = $this->transportUtility->resolveUserAddressForTransport($requesterAddress);
        $payload = [
            'type'            => 'message',
            'typeMessage'     => 'payment_request',
            'action'          => 'response',
            'requestId'       => $request['request_id'],
            'outcome'         => $outcome,
            'senderAddress'   => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey() ?? '',
        ];

        if ($outcome === 'approved' && !empty($txid)) {
            $payload['txid'] = $txid;
        }

        $this->logger->info('Sending payment request response message', [
            'request_id'        => $request['request_id'],
            'outcome'           => $outcome,
            'requester_address' => $requesterAddress,
        ]);

        $this->deliverMessage($requesterAddress, $payload, $request['request_id'], 'response-' . $outcome);
    }

    /**
     * Deliver a payment_request message using MessageDeliveryService when available
     * (with retry and DLQ), falling back to direct transport otherwise.
     *
     * Delivery failures are non-fatal: the request is always stored locally first,
     * so the recipient will still receive it on their next check if delivery was queued.
     */
    private function deliverMessage(string $address, array $payload, string $requestId, string $context): void
    {
        if ($this->messageDeliveryService !== null) {
            $messageId = 'payment_request-' . $context . '-' . $requestId;
            $result = $this->messageDeliveryService->sendMessage(
                'payment_request',
                $address,
                $payload,
                $messageId,
                false // sync — wait for first attempt, retry handled by cleanup processor
            );
            if (!($result['success'] ?? false) && !($result['queued_for_retry'] ?? false)) {
                $this->logger->warning('Payment request message delivery did not succeed on first attempt', [
                    'request_id' => $requestId,
                    'context'    => $context,
                    'address'    => $address,
                    'stage'      => $result['tracking']['stage'] ?? 'unknown',
                ]);
            } else {
                $this->logger->info('Payment request message delivered (or queued for retry)', [
                    'request_id' => $requestId,
                    'context'    => $context,
                    'stage'      => $result['tracking']['stage'] ?? 'unknown',
                ]);
            }
            return;
        }

        // Fallback: direct transport (no retry, no DLQ)
        $this->logger->warning('MessageDeliveryService not available — using direct transport for payment request (no retry)', [
            'request_id' => $requestId,
            'context'    => $context,
        ]);
        try {
            $response = $this->transportUtility->send($address, $payload);
            $decoded  = json_decode($response, true);
            $status   = $decoded['status'] ?? '';
            $this->logger->info('Payment request direct transport response', [
                'request_id' => $requestId,
                'context'    => $context,
                'status'     => $status,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Payment request direct transport failed', [
                'request_id' => $requestId,
                'context'    => $context,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
