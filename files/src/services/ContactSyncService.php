<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Events\DeliveryEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Database\RepositoryFactory;
use Eiou\Utils\Logger;
use Eiou\Core\UserContext;
use Eiou\Schemas\Payloads\ContactPayload;
use Eiou\Schemas\Payloads\MessagePayload;
use RuntimeException;

/**
 * Contact Sync Service
 *
 * Handles contact synchronization and P2P exchange operations.
 * Extracted from ContactService to separate sync/exchange concerns
 * from core contact management operations.
 *
 * Responsibilities:
 * - Contact request sending and receiving
 * - Message delivery for contact operations
 * - Contact transaction chain management
 * - Existing and new contact exchange handling
 *
 * @see ContactServiceInterface for contact management operations
 * @see SyncTriggerInterface for transaction chain sync operations
 */
class ContactSyncService implements ContactSyncServiceInterface {

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var ContactRepository Contact Repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var AddressRepository Address repository instance
     */
    private AddressRepository $addressRepository;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var TransactionRepository Transaction repository for contact transactions
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var TransactionContactRepository Transaction contact repository for contact transactions
     */
    private TransactionContactRepository $transactionContactRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var TransportUtilityService Transport utility service
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var ContactPayload payload builder for contacts
     */
    private ContactPayload $contactPayload;

    /**
     * @var MessagePayload payload builder for messages
     */
    private MessagePayload $messagePayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var SyncTriggerInterface|null Sync trigger for contact synchronization
     */
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * @var ContactCreditRepository|null Contact credit repository for initial credit creation
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * @var \Eiou\Database\ContactCurrencyRepository|null Contact currency repository
     */
    private ?\Eiou\Database\ContactCurrencyRepository $contactCurrencyRepository = null;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact Repository
     * @param AddressRepository $addressRepository Address Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param TransactionRepository $transactionRepository Transaction Repository
     * @param TransactionContactRepository $transactionContactRepository Transaction contact repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        TransactionRepository $transactionRepository,
        TransactionContactRepository $transactionContactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        RepositoryFactory $repositoryFactory,
        SyncTriggerInterface $syncTrigger
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->transactionRepository = $transactionRepository;
        $this->transactionContactRepository = $transactionContactRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currentUser = $currentUser;
        $this->transportUtility = $this->utilityContainer->getTransportUtility($this->currentUser);
        $this->timeUtility = $this->utilityContainer->getTimeUtility();

        $this->contactPayload = new ContactPayload($this->currentUser, $this->utilityContainer);
        $this->messagePayload = new MessagePayload($this->currentUser, $this->utilityContainer);
        $this->contactCreditRepository = $repositoryFactory->get(\Eiou\Database\ContactCreditRepository::class);
        $this->contactCurrencyRepository = $repositoryFactory->get(\Eiou\Database\ContactCurrencyRepository::class);
        $this->syncTrigger = $syncTrigger;
    }

    // =========================================================================
    // DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Get the sync trigger
     *
     * @return SyncTriggerInterface|null The sync trigger or null if not set
     */
    public function getSyncTrigger(): ?SyncTriggerInterface {
        return $this->syncTrigger;
    }

    /**
     * Get the sync trigger with null check (internal use)
     *
     * @return SyncTriggerInterface
     * @throws RuntimeException If sync trigger was not injected
     */
    private function requireSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void {
        $this->messageDeliveryService = $service;

        // Subscribe to delivery retry completion events so we can run
        // post-delivery logic when a queued contact create succeeds
        EventDispatcher::getInstance()->subscribe(
            DeliveryEvents::RETRY_DELIVERY_COMPLETED,
            [$this, 'handleRetryDeliveryCompleted']
        );
    }

    // =========================================================================
    // RETRY DELIVERY COMPLETION HANDLER
    // =========================================================================

    /**
     * Handle successful delivery of a retried message
     *
     * Called via EventDispatcher when processRetryQueue() successfully delivers
     * a message that was previously queued for retry. For contact creates, this
     * runs the post-delivery logic (contact insertion, balance init, etc.) that
     * couldn't run when the original send was deferred due to maintenance mode.
     *
     * @param array $eventData Event data from DeliveryEvents::RETRY_DELIVERY_COMPLETED
     */
    public function handleRetryDeliveryCompleted(array $eventData): void {
        // Only handle contact message types
        if (($eventData['message_type'] ?? '') !== 'contact') {
            return;
        }

        $messageId = $eventData['message_id'] ?? '';
        $address = $eventData['recipient_address'] ?? '';
        $responseData = $eventData['response'] ?? [];
        $signingData = $eventData['signing_data'] ?? null;
        $storedPayload = $eventData['stored_payload'] ?? [];

        // Extract contact creation params stored in the payload metadata
        $contactParams = $storedPayload['_contact_params'] ?? null;
        if ($contactParams === null) {
            Logger::getInstance()->info("Retry delivery completed for contact but no _contact_params in payload", [
                'message_id' => $messageId,
                'recipient_address' => $address
            ]);
            return;
        }

        $name = $contactParams['name'];
        $fee = (float) $contactParams['fee'];
        $credit = (float) $contactParams['credit'];
        $currency = $contactParams['currency'];
        $minFeeAmount = isset($contactParams['min_fee_amount']) ? (int) $contactParams['min_fee_amount'] : null;

        $status = $responseData['status'] ?? null;
        $senderPublicKey = $responseData['senderPublicKey'] ?? null;

        if ($senderPublicKey === null || $status === null) {
            Logger::getInstance()->warning("Retry delivery completed for contact but response missing senderPublicKey or status", [
                'message_id' => $messageId,
                'status' => $status,
                'has_pubkey' => $senderPublicKey !== null
            ]);
            return;
        }

        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($address);

        // Check if contact was already created (e.g., by the remote sending us a request
        // while our retry was pending, or by a duplicate retry)
        $existingContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
        if ($existingContact) {
            // Update address with potentially new transport info
            $this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative);

            // If contact is pending without a name, it was created by their incoming request
            // (addPendingContact sets name=NULL). Our retry response has the actual name and
            // may indicate acceptance — process it like handleNewContact() does.
            if ($existingContact['status'] === Constants::CONTACT_STATUS_PENDING && $existingContact['name'] === null) {
                Logger::getInstance()->info("Existing pending contact (name=NULL) found after retry delivery, completing setup", [
                    'message_id' => $messageId,
                    'pubkey_hash' => $senderPublicKeyHash,
                    'response_status' => $status
                ]);

                if ($status === Constants::STATUS_ACCEPTED) {
                    // Remote auto-accepted (mutual request) — accept on our end too
                    // Check currency match with pending incoming currencies
                    $pendingIncomingCurrencies = [];
                    if ($this->contactCurrencyRepository !== null) {
                        $pendingIncomingCurrencies = array_column(
                            $this->contactCurrencyRepository->getPendingCurrencies($senderPublicKeyHash, 'incoming'),
                            'currency'
                        );
                    }

                    $currencyMatches = in_array($currency, $pendingIncomingCurrencies);

                    if ($currencyMatches) {
                        // Currency matches — accept the contact
                        if ($this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                            if ($this->contactCurrencyRepository !== null) {
                                $updateFields = [
                                    'fee_percent' => (int) $fee,
                                    'credit_limit' => (int) $credit,
                                    'status' => 'accepted',
                                ];
                                if ($minFeeAmount !== null) {
                                    $updateFields['min_fee_amount'] = $minFeeAmount;
                                }
                                $this->contactCurrencyRepository->updateCurrencyConfig($senderPublicKeyHash, $currency, $updateFields);
                            }

                            $this->storeRecipientSignatureFromResponse($senderPublicKey, $responseData);
                            $this->completeReceivedContactTransaction($senderPublicKey);

                            Logger::getInstance()->info("Contact accepted (mutual) after retry delivery", [
                                'pubkey_hash' => $senderPublicKeyHash,
                                'name' => $name
                            ]);
                        }
                    } else {
                        // Currency mismatch — update name, keep pending, store outgoing currency
                        $this->contactRepository->updateContactFields($senderPublicKey, ['name' => $name]);

                        if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                            if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing')) {
                                $this->contactCurrencyRepository->insertCurrencyConfig(
                                    $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                                );
                            }
                        }

                        Logger::getInstance()->info("Contact name updated after retry delivery (currency mismatch, still pending)", [
                            'pubkey_hash' => $senderPublicKeyHash,
                            'name' => $name,
                            'currency' => $currency,
                            'pending_incoming' => $pendingIncomingCurrencies
                        ]);
                    }
                } elseif ($status === Constants::DELIVERY_RECEIVED) {
                    // Remote received our request — update name, store outgoing currency
                    $this->contactRepository->updateContactFields($senderPublicKey, ['name' => $name]);

                    if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                        if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing')) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                            );
                        }
                    }

                    // Initialize balance for this currency if not already done
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    Logger::getInstance()->info("Contact name updated after retry delivery (received)", [
                        'pubkey_hash' => $senderPublicKeyHash,
                        'name' => $name
                    ]);
                } else {
                    // Other status — just update the name
                    $this->contactRepository->updateContactFields($senderPublicKey, ['name' => $name]);
                }
            } else {
                Logger::getInstance()->info("Contact already exists after retry delivery, skipping", [
                    'message_id' => $messageId,
                    'pubkey_hash' => $senderPublicKeyHash,
                    'existing_status' => $existingContact['status']
                ]);
            }

            if ($this->messageDeliveryService !== null) {
                $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
            }
            return;
        }

        Logger::getInstance()->info("Processing contact creation after retry delivery succeeded", [
            'message_id' => $messageId,
            'status' => $status,
            'recipient_address' => $address,
            'contact_name' => $name
        ]);

        // Handle response based on status — same logic as handleNewContact() inline processing
        if ($status === Constants::DELIVERY_RECEIVED) {
            // Standard case: contact request was received, pending acceptance
            if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                // Store additional addresses if present
                if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                }

                $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                // Track outgoing currency request
                if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                    $this->contactCurrencyRepository->insertCurrencyConfig(
                        $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                    );
                }

                // Insert contact transaction
                $txid = $responseData['txid'] ?? null;
                $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                // Store signature data
                if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                    $this->transactionRepository->updateSignatureData(
                        $txid,
                        $signingData['signature'],
                        $signingData['nonce']
                    );
                }

                // Update delivery stage to completed
                if ($this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                }

                Logger::getInstance()->info("Contact created successfully after retry delivery", [
                    'pubkey_hash' => $senderPublicKeyHash,
                    'name' => $name,
                    'currency' => $currency
                ]);
            } else {
                Logger::getInstance()->error("Failed to insert contact after retry delivery", [
                    'message_id' => $messageId,
                    'pubkey_hash' => $senderPublicKeyHash
                ]);
            }
        } elseif ($status === Constants::STATUS_ACCEPTED) {
            // Mutual request: remote auto-accepted because they already had us as pending
            if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                }

                $this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency);

                if (!$this->contactTransactionExists($senderPublicKey)) {
                    $txid = $responseData['txid'] ?? null;
                    $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                    if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                        $this->transactionRepository->updateSignatureData(
                            $txid,
                            $signingData['signature'],
                            $signingData['nonce']
                        );
                    }
                }

                $this->storeRecipientSignatureFromResponse($senderPublicKey, $responseData);

                if ($this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                }

                Logger::getInstance()->info("Contact mutually accepted after retry delivery", [
                    'pubkey_hash' => $senderPublicKeyHash,
                    'name' => $name
                ]);
            }
        } else {
            // Other statuses (updated, warning, rejected) — log for investigation
            Logger::getInstance()->warning("Unhandled contact response status after retry delivery", [
                'message_id' => $messageId,
                'status' => $status,
                'recipient_address' => $address
            ]);
        }
    }

    // =========================================================================
    // CONTACT TRANSACTION ID
    // =========================================================================

    /**
     * Create unique transaction ID for contact requests
     *
     * For contact transactions, amount is always 0, so txid is generated from:
     * senderPublicKey + receiverPublicKey + 0 + currency + time
     *
     * @param string $receiverPublicKey The receiver's public key
     * @param string $currency The currency for the contact transaction
     * @return string The generated transaction ID (SHA-256 hash)
     */
    public function createContactTxid(string $receiverPublicKey, string $currency = 'USD'): string {
        $time = $this->timeUtility->getCurrentMicrotime();
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $receiverPublicKey . '0' . $currency . $time);
    }

    // =========================================================================
    // CONTACT TRANSACTION CHECKS
    // =========================================================================

    /**
     * Check if a contact transaction already exists for the given receiver
     *
     * @param string $receiverPublicKey The public key of the contact
     * @return bool True if contact transaction exists
     */
    public function contactTransactionExists(string $receiverPublicKey): bool {
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);
        return $this->transactionContactRepository->contactTransactionExistsForReceiver($receiverPublicKeyHash);
    }

    // =========================================================================
    // CONTACT TRANSACTION OPERATIONS
    // =========================================================================

    /**
     * Insert a contact transaction after receiving the public key from a contact
     *
     * Creates a contact transaction with amount=0 to record the contact request
     * as the first transaction between users. Used by the sender of the contact request.
     *
     * The txid should come from the receiver's response to ensure both parties
     * have matching txids for the contact transaction.
     *
     * @param string $receiverPublicKey The public key of the contact
     * @param string $receiverAddress The address of the contact
     * @param string $currency The currency for the transaction
     * @param string|null $txid The txid from the receiver's response
     * @return string|null The txid on success, null on failure
     */
    public function insertContactTransaction(string $receiverPublicKey, string $receiverAddress, string $currency, ?string $txid = null): ?string {
        // Use provided txid from receiver, or generate locally as fallback
        // Currency is included in fallback hash to ensure unique txids per currency
        $time = $this->timeUtility->getCurrentMicrotime();
        $txid = $txid ?? hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $receiverPublicKey . '0' . $currency . $time);

        // Build transaction data with status 'sent' (will move to 'completed' upon acceptance)
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($receiverAddress);
        $transactionData = [
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'receiverAddress' => $receiverAddress,
            'receiverPublicKey' => $receiverPublicKey,
            'amount' => 0,
            'currency' => $currency,
            'status' => Constants::STATUS_SENT,
            'txid' => $txid,
            'time' => $time,
            'memo' => 'contact',
            'description' => 'Contact request transaction'
            // NOTE: endRecipientAddress and initialSenderAddress are NOT included here
            // They are added via updateTrackingFields() after insert
        ];

        // Insert the contact transaction as 'sent' type
        $result = $this->transactionRepository->insertTransaction($transactionData, Constants::TX_TYPE_SENT);

        if ($result !== false) {
            // Update tracking fields after insert (these are NOT part of signed payload)
            // Contact transactions are direct - both parties know sender and recipient
            $this->transactionRepository->updateTrackingFields(
                $txid,
                $receiverAddress,  // endRecipientAddress
                $myAddress  // initialSenderAddress
            );
            return $txid;
        }

        return null;
    }

    /**
     * Insert a received contact transaction when we receive a contact request
     *
     * Creates a contact transaction with amount=0 from the perspective of the receiver.
     * The transaction is created with status 'accepted' (pending user acceptance) and
     * moves to 'completed' when the user explicitly accepts the contact request.
     *
     * The receiver generates the txid and returns it so it can be included in the
     * response for the sender to use, ensuring both parties have matching txids.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @param string $senderAddress The address of the contact who sent the request
     * @param string $currency The currency for the transaction
     * @param string|null $signature The sender's signature from the incoming request
     * @param string|null $nonce The signature nonce from the incoming request
     * @return string|null The txid on success, null on failure
     */
    public function insertReceivedContactTransaction(string $senderPublicKey, string $senderAddress, string $currency = 'USD', ?string $signature = null, ?string $nonce = null): ?string {
        // Generate time and txid on receiver side
        $time = $this->timeUtility->getCurrentMicrotime();

        // Generate txid using sender's public key + receiver's public key + 0 + currency + time
        // Currency is included to ensure unique txids when multiple currencies are added between the same parties
        $txid = hash(Constants::HASH_ALGORITHM, $senderPublicKey . $this->currentUser->getPublicKey() . '0' . $currency . $time);

        // Generate a signature nonce for the dual-signature protocol.
        // If the sender didn't provide one, generate it on the receiver side.
        // This nonce is used by both parties to sign/verify the contact message:
        // {'type':'create','currency':'USD','nonce':N}
        if (empty($nonce)) {
            $nonce = bin2hex(random_bytes(16));
        }

        // Build transaction data with status 'accepted' (pending user acceptance, will move to 'completed')
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($senderAddress);
        $transactionData = [
            'senderAddress' => $senderAddress,
            'senderPublicKey' => $senderPublicKey,
            'receiverAddress' => $myAddress,
            'receiverPublicKey' => $this->currentUser->getPublicKey(),
            'amount' => 0,
            'currency' => $currency,
            'status' => Constants::STATUS_ACCEPTED,
            'txid' => $txid,
            'time' => $time,
            'memo' => 'contact',
            'description' => 'Contact request transaction',
            // Sender's signature data for future sync verification
            'signature' => $signature,
            'nonce' => $nonce
            // NOTE: endRecipientAddress and initialSenderAddress are NOT included here
            // They are added via updateTrackingFields() after insert
        ];

        // Insert the contact transaction with 'accepted' status
        // Second parameter is transaction type: 'received' (we are receiving a contact request)
        $result = $this->transactionRepository->insertTransaction($transactionData, Constants::TX_TYPE_RECEIVED);

        if ($result !== false) {
            // Update tracking fields after insert (these are NOT part of signed payload)
            // Contact transactions are direct - both parties know sender and recipient
            $this->transactionRepository->updateTrackingFields(
                $txid,
                $myAddress,  // endRecipientAddress
                $senderAddress  // initialSenderAddress
            );
        }

        // Return the txid so caller can include it in the response
        return $result !== false ? $txid : null;
    }

    /**
     * Complete a received contact transaction when user accepts the contact request
     *
     * Updates the contact transaction status from 'accepted' to 'completed'.
     * This is called from the receiver's perspective when they accept an incoming request.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @return bool True if transaction was updated successfully
     */
    public function completeReceivedContactTransaction(string $senderPublicKey): bool {
        return $this->transactionContactRepository->completeReceivedContactTransaction($senderPublicKey);
    }

    // =========================================================================
    // MESSAGE SENDING
    // =========================================================================

    /**
     * Send a message to a contact.
     *
     * Delivers a message payload to the specified contact address.
     * Used for contact request messages, acceptance messages, and
     * other P2P communication.
     *
     * @param string $contactAddress The recipient's address
     * @param string $payload The message payload (JSON-encoded string)
     * @param string $description A description of the message for logging
     * @return array Result array containing:
     *   - success: bool - Whether the message was sent successfully
     *   - error: string|null - Error message if failed
     *   - response: mixed - Response from the recipient (if any)
     */
    public function sendContactMessage(string $contactAddress, string $payload, string $description): array {
        // Decode payload if it's a JSON string
        $payloadArray = json_decode($payload, true);
        if ($payloadArray === null) {
            return [
                'success' => false,
                'error' => 'Invalid JSON payload',
                'response' => null
            ];
        }

        // Generate message ID from description
        $messageId = hash('sha256', $description . $this->timeUtility->getCurrentMicrotime());

        // Call internal send method
        return $this->sendContactMessageInternal($contactAddress, $payloadArray, $messageId, true);
    }

    /**
     * Send a contact message with optional delivery tracking (internal method)
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID for tracking
     * @param bool $async Whether to send asynchronously
     * @param bool $allowTransportFallback If true, TOR failures attempt HTTP/HTTPS fallback (contact requests only)
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendContactMessageInternal(string $address, array $payload, ?string $messageId = null, bool $async = true, bool $allowTransportFallback = false): array {
        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // async=true: Non-blocking, queues for retry if first attempt fails
            // async=false: Blocking, waits for response (required for acceptance messages
            //              to ensure the sender's status is updated before returning)
            return $this->messageDeliveryService->sendMessage(
                'contact',
                $address,
                $payload,
                $messageId,
                $async
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        if ($messageId === null) {
            $messageId = hash('sha256', json_encode($payload) . $this->timeUtility->getCurrentMicrotime());
        }

        $rawResponse = $this->transportUtility->send($address, $payload, false, $allowTransportFallback);
        $response = json_decode($rawResponse, true);

        return [
            'success' => $response !== null && in_array($response['status'] ?? null, Constants::DELIVERY_SUCCESS_STATUSES, true),
            'response' => $response,
            'raw' => $rawResponse,
            'messageId' => $messageId
        ];
    }

    // =========================================================================
    // CONTACT EXCHANGE HANDLERS
    // =========================================================================

    /**
     * Handle existing contact addition
     *
     * @param array $contact Existing contact data
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function handleExistingContact(array $contact, string $address, string $name, float $fee, float $credit, string $currency, ?CliOutputManager $output = null, ?int $minFeeAmount = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Build contact data for JSON response
        $contactData = [
            'address' => $address,
            'name' => $name,
            'fee' => $fee / Constants::FEE_CONVERSION_FACTOR,
            'credit' => $credit / Constants::CONVERSION_FACTORS[$currency],
            'currency' => $currency,
            'status' => $contact['status']
        ];

        // Check if contact is already an accepted contact
        if($contact['status'] === Constants::CONTACT_STATUS_ACCEPTED){
            // Check for pending incoming currencies from the remote side
            $pendingCurrencies = [];
            if ($this->contactCurrencyRepository !== null) {
                $pendingCurrencies = array_column(
                    $this->contactCurrencyRepository->getPendingCurrencies($contact['pubkey_hash'], 'incoming'),
                    'currency'
                );
            }

            if (in_array($currency, $pendingCurrencies)) {
                // Accepting a pending incoming currency request — update the single row
                $updateFields = [
                    'fee_percent' => (int) $fee,
                    'credit_limit' => (int) $credit,
                    'status' => 'accepted',
                ];
                if ($minFeeAmount !== null) {
                    $updateFields['min_fee_amount'] = $minFeeAmount;
                }
                $this->contactCurrencyRepository->updateCurrencyConfig($contact['pubkey_hash'], $currency, $updateFields);

                // Insert initial balance and credit entries for the new currency
                $this->balanceRepository->insertInitialContactBalances($contact['pubkey'], $currency);
                if ($this->contactCreditRepository !== null) {
                    try {
                        $this->contactCreditRepository->createInitialCredit($contact['pubkey'], $currency);
                    } catch (\Exception $e) {
                        Logger::getInstance()->warning("Failed to create initial credit for new currency", [
                            'currency' => $currency,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Send acceptance notification so remote side marks their outgoing currency as accepted
                // Use buildContactIsAccepted (not buildCreateRequest) to avoid triggering a new contact tx on the remote
                $acceptPayload = $this->messagePayload->buildContactIsAccepted($address, false, null, $currency);
                $messageId = 'currency-accept-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());
                $this->sendContactMessageInternal($address, $acceptPayload, $messageId, false);

                $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                $contactData['currency'] = $currency;
                $output->success("Accepted currency " . $currency . " for contact " . $address, $contactData, "Currency accepted");
                return;
            }

            $output->error("Contact " . $address . " already exists ", ErrorCodes::CONTACT_EXISTS, 409, ['contact' => $contactData]);
        }
        // Check if contact was blocked
        elseif($contact['status'] === Constants::CONTACT_STATUS_BLOCKED){
            // Contact was blocked after user sent contact request
            if($contact['name']){
                // Unblock contact and add values
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    $output->success("Contact" . $address . "unblocked and updated", $contactData, "Contact unblocked and updated successfully");
                } else{
                    $output->error("Failed to unblock and update contact " . $address, ErrorCodes::UNBLOCK_FAILED, 500, ['contact' => $contactData]);
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    // Generate recipient signature for dual-signature protocol
                    $recipientSig = $this->generateAndStoreContactRecipientSignature($contact['pubkey']);

                    // Send message of successful contact acceptance back to original contact requester with tracking
                    // Message ID format: unblock-accept-{hash} (message_type 'contact' provides context)
                    $acceptPayload = $this->messagePayload->buildContactIsAccepted($address, false, $recipientSig);
                    $messageId = 'unblock-accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessageInternal($address, $acceptPayload, $messageId);

                    // For acceptance messages, we update stages based on our local operations (using MessageDeliveryService directly)
                    // Stage progression: pending -> sent -> received (from transport) -> inserted (local) -> completed
                    if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Complete the received contact transaction (update status from 'accepted' to 'completed')
                    $this->completeReceivedContactTransaction($contact['pubkey']);

                    $output->success("Contact " . $address . " unblocked and added", $contactData, "Contact unblocked and added successfully");
                } else{
                    $output->error("Failed to unblock and add contact " . $address, ErrorCodes::UNBLOCK_ADD_FAILED, 500, ['contact' => $contactData]);
                }
            }
        }
        elseif($contact['status'] === Constants::CONTACT_STATUS_PENDING){
            // if pending with name (contact was inserted by user for contact request)
            if($contact['name']){
                // Check if user is adding a new currency to their pending request
                $currencyAlreadyPending = $this->contactCurrencyRepository !== null
                    && $this->contactCurrencyRepository->hasCurrency($contact['pubkey_hash'], $currency, 'outgoing');

                if (!$currencyAlreadyPending) {
                    // Track the new outgoing currency request in contact_currencies
                    if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                        if (!$this->contactCurrencyRepository->hasCurrency($contact['pubkey_hash'], $currency, 'outgoing')) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $contact['pubkey_hash'], $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                            );
                        }
                    }

                    // Re-send the contact request with updated currency
                    $payload = $this->contactPayload->buildCreateRequest($address, $currency);
                    $messageId = 'create-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessageInternal($address, $payload, $messageId, true, true);
                    $responseData = $sendResult['response'];

                    if (isset($responseData['status']) && $responseData['status'] === Constants::STATUS_ACCEPTED) {
                        // Currencies now match on the remote side — mutual accept
                        $senderPublicKey = $responseData['senderPublicKey'];
                        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);

                        // Store any additional addresses from response
                        if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                            $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                        }

                        $this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency);

                        // Ensure contact transaction exists
                        if (!$this->contactTransactionExists($senderPublicKey)) {
                            $txid = $responseData['txid'] ?? null;
                            $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);
                        }
                        $this->completeReceivedContactTransaction($senderPublicKey);

                        // Store recipient signature from remote's response on our sent contact TX
                        $this->storeRecipientSignatureFromResponse($senderPublicKey, $responseData);

                        if ($this->messageDeliveryService !== null) {
                            $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                        }

                        // Mark currency as accepted (remote auto-accepted)
                        if ($this->contactCurrencyRepository !== null) {
                            $this->contactCurrencyRepository->updateCurrencyStatus($contact['pubkey_hash'], $currency, 'accepted');
                        }

                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['currency'] = $currency;
                        $output->success("Contact mutually accepted with " . $address, $contactData, "Contact accepted (currency updated to " . $currency . ")");
                    } else {
                        // Response is "received" — create the sender-side contact transaction
                        // using the txid from the receiver to ensure both sides match
                        if (isset($responseData['txid'])) {
                            $senderPublicKey = $responseData['senderPublicKey'] ?? $contact['pubkey'];
                            $this->insertContactTransaction($senderPublicKey, $address, $currency, $responseData['txid']);
                        }

                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $contactData['currency'] = $currency;
                        $output->success("Contact request updated to " . $currency . ", awaiting response from " . $address, $contactData, "Currency updated");
                    }
                    return;
                }

                // Same currency — try resyncing
                // Use full sync chain for wallet restoration scenarios: Contact -> Transactions -> Balances
                $syncService = $this->requireSyncTrigger();
                $syncResult = $syncService->syncReaddedContact($address, $contact['pubkey']);

                if ($syncResult['success'] && $syncResult['contact_synced']) {
                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $contactData['sync'] = [
                        'transactions_synced' => $syncResult['transactions_synced'],
                        'balances_synced' => $syncResult['balances_synced'],
                        'currencies' => $syncResult['currencies']
                    ];
                    $output->success("Contact request already sent, synced successfully with " . $address, $contactData, "Contact synced");
                } elseif ($syncResult['contact_synced']) {
                    // Contact status synced but transactions/balances may have failed
                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $output->success("Contact synced, transaction sync may be incomplete", $contactData, "Partial sync");
                } else {
                    $output->info("Contact request already sent, awaiting response from " . $address, $contactData);
                }
            } else{
                // Accepting an incoming contact request (they sent us a request first, name=null)
                // Look up pending incoming currencies from contact_currencies for this contact
                $pendingIncomingCurrencies = [];
                if ($this->contactCurrencyRepository !== null) {
                    $pendingIncomingCurrencies = array_column(
                        $this->contactCurrencyRepository->getPendingCurrencies($contact['pubkey_hash'], 'incoming'),
                        'currency'
                    );
                }

                // Check if user's currency matches a pending incoming currency request
                $currencyMatches = in_array($currency, $pendingIncomingCurrencies);

                if ($currencyMatches) {
                    // Currency matches! Accept the contact and the currency
                    if ($this->acceptContact($contact['pubkey'], $name, $fee, $credit, $currency)) {
                        // Update the single currency row to accepted
                        if ($this->contactCurrencyRepository !== null) {
                            $this->contactCurrencyRepository->updateCurrencyConfig($contact['pubkey_hash'], $currency, [
                                'fee_percent' => (int) $fee,
                                'credit_limit' => (int) $credit,
                                'status' => 'accepted',
                            ]);
                        }
                        // Generate recipient signature for dual-signature protocol
                        $recipientSig = $this->generateAndStoreContactRecipientSignature($contact['pubkey']);

                        // Send message of successful contact acceptance back to original contact requester with tracking
                        // Include the accepted currency so the remote can mark it as accepted
                        $acceptPayload = $this->messagePayload->buildContactIsAccepted($address, false, $recipientSig, $currency);
                        $messageId = 'accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                        $sendResult = $this->sendContactMessageInternal($address, $acceptPayload, $messageId, false); // sync delivery

                        // Log if acceptance message delivery failed
                        if (!$sendResult['success']) {
                            Logger::getInstance()->warning("Contact acceptance message delivery failed", [
                                'recipient_address' => $address,
                                'message_id' => $messageId,
                                'error' => $sendResult['tracking']['error'] ?? 'unknown'
                            ]);
                        }

                        if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                            $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                        }

                        // Complete the received contact transaction (update status from 'accepted' to 'completed')
                        $this->completeReceivedContactTransaction($contact['pubkey']);

                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $output->success("Contact request accepted from " . $address, $contactData, "Contact accepted successfully");
                    }
                    else {
                        $output->error("Failed to accept contact request from " . $address, ErrorCodes::ACCEPT_FAILED, 500, ['contact' => $contactData]);
                        return;
                    }
                } else {
                    // Currency mismatch — don't accept contact, send new contact request with user's currency
                    // Set the name so the contact appears in the contacts grid as "Pending Response"
                    // It will also appear in "Pending Contact Requests" section due to pending_currencies
                    $this->contactRepository->updateContactFields($contact['pubkey'], [
                        'name' => $name,
                    ]);

                    // Store outgoing currency as pending
                    if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                        if (!$this->contactCurrencyRepository->hasCurrency($contact['pubkey_hash'], $currency, 'outgoing')) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $contact['pubkey_hash'], $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                            );
                        }
                    }

                    // Send a contact creation request to remote with user's currency
                    $payload = $this->contactPayload->buildCreateRequest($address, $currency);
                    $messageId = 'create-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessageInternal($address, $payload, $messageId, true, true);
                    $responseData = $sendResult['response'];

                    // Check if remote auto-accepted (they already have matching outgoing currency)
                    if (isset($responseData['status']) && $responseData['status'] === Constants::STATUS_ACCEPTED) {
                        $senderPublicKey = $responseData['senderPublicKey'];
                        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);

                        if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                            $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                        }

                        $this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency);

                        if (!$this->contactTransactionExists($senderPublicKey)) {
                            $txid = $responseData['txid'] ?? null;
                            $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);
                        }
                        $this->completeReceivedContactTransaction($senderPublicKey);

                        // Store recipient signature from remote's response on our sent contact TX
                        $this->storeRecipientSignatureFromResponse($senderPublicKey, $responseData);

                        if ($this->messageDeliveryService !== null) {
                            $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                        }

                        // Mark currency as accepted
                        if ($this->contactCurrencyRepository !== null) {
                            $this->contactCurrencyRepository->updateCurrencyStatus($contact['pubkey_hash'], $currency, 'accepted');
                        }

                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $output->success("Contact mutually accepted with " . $address, $contactData, "Contact accepted (currency " . $currency . ")");
                    } else {
                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $contactData['currency'] = $currency;
                        $pendingInfo = !empty($pendingIncomingCurrencies) ? ' (pending incoming: ' . implode(', ', $pendingIncomingCurrencies) . ')' : '';
                        $output->success("Contact request sent with currency " . $currency . ", awaiting acceptance" . $pendingInfo, $contactData, "Contact request sent");
                    }
                }
            }
        }
    }

    /**
     * Handle new contact creation
     *
     * Uses MessageDeliveryService for reliable message delivery when available.
     *
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function handleNewContact(string $address, string $name, float $fee, float $credit, string $currency, ?CliOutputManager $output = null, ?int $minFeeAmount = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Build contact data for JSON response
        $contactData = [
            'address' => $address,
            'name' => $name,
            'fee' => $fee / Constants::FEE_CONVERSION_FACTOR,
            'credit' => $credit / Constants::CONVERSION_FACTORS[$currency],
            'currency' => $currency
        ];

        // Build the payload array (include currency so receiver knows sender's preference)
        $payload = $this->contactPayload->buildCreateRequest($address, $currency);
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($address);  // Address already passed validation before

        // Store contact creation params as metadata in the payload.
        // If the first delivery attempt fails and the message is queued for retry,
        // these params are needed when the retry succeeds to complete contact insertion.
        // The remote node ignores unknown keys in the payload.
        $payload['_contact_params'] = [
            'name' => $name,
            'fee' => $fee,
            'credit' => $credit,
            'currency' => $currency,
            'min_fee_amount' => $minFeeAmount
        ];

        // Generate unique message ID for contact creation tracking
        // Message ID format: create-{hash} (message_type 'contact' provides context)
        $messageId = 'create-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());

        // Send contact creation request with delivery tracking
        // Allow transport fallback for contact requests — if TOR fails, try HTTP/HTTPS
        // so the initial handshake can succeed even when the hidden service is unreachable
        $sendResult = $this->sendContactMessageInternal($address, $payload, $messageId, true, true);
        $responseData = $sendResult['response'];

        if (isset($responseData['status'])){
            $senderPublicKey = $responseData['senderPublicKey'];
            $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);

            // Check if we already have this contact stored locally (under a different address)
            // This handles the case where user adds a known contact via new address type
            // OR the case where they sent us a request while we were sending ours
            $existingLocalContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
            if ($existingLocalContact) {
                // Update the address with new transport type
                $this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative);

                if ($this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                }

                // If contact is pending without name (we received their request), check currency match
                if ($existingLocalContact['status'] === Constants::CONTACT_STATUS_PENDING && $existingLocalContact['name'] === null) {
                    // Look up pending incoming currencies for this contact
                    $pendingIncomingCurrencies = [];
                    if ($this->contactCurrencyRepository !== null) {
                        $pendingIncomingCurrencies = array_column(
                            $this->contactCurrencyRepository->getPendingCurrencies($senderPublicKeyHash, 'incoming'),
                            'currency'
                        );
                    }

                    $currencyMatches = in_array($currency, $pendingIncomingCurrencies);

                    if ($currencyMatches) {
                        // Currency matches — accept the contact
                        if ($this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                            // Update the single currency row to accepted
                            if ($this->contactCurrencyRepository !== null) {
                                $this->contactCurrencyRepository->updateCurrencyConfig($senderPublicKeyHash, $currency, [
                                    'fee_percent' => (int) $fee,
                                    'credit_limit' => (int) $credit,
                                    'status' => 'accepted',
                                ]);
                            }

                            // Generate recipient signature for dual-signature protocol
                            $recipientSig = $this->generateAndStoreContactRecipientSignature($senderPublicKey);

                            // Send acceptance message back with currency info
                            $acceptPayload = $this->messagePayload->buildContactIsAccepted($address, false, $recipientSig, $currency);
                            $acceptMessageId = 'accept-' . hash('sha256', $address . $senderPublicKey . $this->timeUtility->getCurrentMicrotime());
                            $sendResult = $this->sendContactMessageInternal($address, $acceptPayload, $acceptMessageId, false);

                            if (!$sendResult['success']) {
                                Logger::getInstance()->warning("Contact acceptance message delivery failed", [
                                    'recipient_address' => $address,
                                    'message_id' => $acceptMessageId,
                                    'error' => $sendResult['tracking']['error'] ?? 'unknown'
                                ]);
                            }

                            // Complete the received contact transaction
                            $this->completeReceivedContactTransaction($senderPublicKey);

                            $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                            $contactData['pubkey'] = $senderPublicKey;
                            $output->success("Contact request accepted from " . $address, $contactData, "Contact accepted successfully");
                            return;
                        }
                    } else {
                        // Currency mismatch — update contact name but keep pending
                        $this->contactRepository->updateContactFields($senderPublicKey, [
                            'name' => $name,
                        ]);

                        // Store outgoing currency as pending
                        if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                            if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing')) {
                                $this->contactCurrencyRepository->insertCurrencyConfig(
                                    $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                                );
                            }
                        }

                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $contactData['pubkey'] = $senderPublicKey;
                        $pendingInfo = !empty($pendingIncomingCurrencies) ? ' (pending incoming: ' . implode(', ', $pendingIncomingCurrencies) . ')' : '';
                        $output->success("Contact request sent with currency " . $currency . ", awaiting acceptance" . $pendingInfo, $contactData, "Contact request sent");
                        return;
                    }
                }

                // Contact exists with name or non-pending status
                // If response is DELIVERY_RECEIVED, the receiver stored our new currency request
                // We need to create the outgoing currency entry and contact transaction for this currency
                if ($responseData['status'] === Constants::DELIVERY_RECEIVED && !empty($currency)) {
                    // Store outgoing currency as pending (or accepted if contact is already accepted)
                    $currencyStatus = ($existingLocalContact['status'] === Constants::CONTACT_STATUS_ACCEPTED) ? 'accepted' : 'pending';
                    if ($this->contactCurrencyRepository !== null) {
                        if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing')) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, $currencyStatus, 'outgoing', $minFeeAmount
                            );
                        }
                    }

                    // Initialize balance and credit for the new currency
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);
                    if ($this->contactCreditRepository !== null) {
                        try {
                            $this->contactCreditRepository->createInitialCredit($senderPublicKey, $currency);
                        } catch (\Exception $e) {
                            Logger::getInstance()->log('Credit may already exist for currency ' . $currency . ': ' . $e->getMessage(), 'DEBUG');
                        }
                    }

                    // Create contact transaction for this currency
                    $txid = $responseData['txid'] ?? null;
                    $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                    // Store signature data
                    $signingData = $sendResult['signing_data'] ?? null;
                    if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                        $this->transactionRepository->updateSignatureData(
                            $txid,
                            $signingData['signature'],
                            $signingData['nonce']
                        );
                    }

                    $contactData['status'] = $existingLocalContact['status'];
                    $contactData['pubkey'] = $senderPublicKey;
                    $output->success("Currency " . $currency . " added to contact " . $name, $contactData, "New currency added to existing contact");
                    return;
                }

                $contactData['status'] = $existingLocalContact['status'];
                $contactData['pubkey'] = $senderPublicKey;
                $output->success("Contact address updated for " . $name, $contactData, "New address type added to existing contact");
                return;
            }

            // Contact request was received (initial insert on their end as pending, awaiting acceptance)
            if($responseData['status'] === Constants::DELIVERY_RECEIVED){
                // Insert contact on our end with returned pubkey as pending (awaiting acceptance)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Track outgoing currency request in contact_currencies
                    // This records that WE requested this currency from them (direction=outgoing)
                    if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                        $this->contactCurrencyRepository->insertCurrencyConfig(
                            $senderPublicKeyHash, $currency, (int) $fee, (int) $credit, 'pending', 'outgoing', $minFeeAmount
                        );
                    }

                    // Insert contact transaction (first transaction between users, amount=0)
                    // Use the txid from the response to ensure both parties have matching txids
                    $txid = $responseData['txid'] ?? null;
                    $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                    // Store signature data for future sync verification
                    $signingData = $sendResult['signing_data'] ?? null;
                    if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                        $this->transactionRepository->updateSignatureData(
                            $txid,
                            $signingData['signature'],
                            $signingData['nonce']
                        );
                    }

                    // Update delivery stage: received -> inserted -> completed (using MessageDeliveryService directly)
                    // Contact request phase is complete (awaiting acceptance is a separate phase)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                    $contactData['pubkey'] = $senderPublicKey;
                    $output->success("Contact request sent successfully to " . $address, $contactData, "Contact request sent, awaiting acceptance");
                } else{
                    $output->error("Failed to create contact with " . $address, ErrorCodes::CONTACT_CREATE_FAILED, 500, ['contact' => $contactData]);
                    return;
                }
            }
            // Remote auto-accepted because they already had us as a pending contact (mutual request)
            elseif($responseData['status'] === Constants::STATUS_ACCEPTED){
                // Insert contact on our end with returned pubkey
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    // Accept the contact (sets status to 'accepted', inserts balances, creates credit, syncs balance)
                    $this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency);

                    // Insert contact transaction if one doesn't already exist
                    if (!$this->contactTransactionExists($senderPublicKey)) {
                        $txid = $responseData['txid'] ?? null;
                        $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }
                    }

                    // Store recipient signature from remote's response on our sent contact TX
                    // The remote generates the recipient signature and includes it in the acceptance response
                    $this->storeRecipientSignatureFromResponse($senderPublicKey, $responseData);

                    // Update delivery stage
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $contactData['pubkey'] = $senderPublicKey;
                    $output->success("Contact mutually accepted with " . $address, $contactData, "Contact accepted (mutual request)");
                } else {
                    $output->error("Failed to create contact with " . $address, ErrorCodes::CONTACT_CREATE_FAILED, 500, ['contact' => $contactData]);
                    return;
                }
            }
            // Our contact pubkey exists on their end, but not provided address
            // we are known under a different address or transport type
            // Note: If contact existed locally, we would have returned early above
            // So reaching here means contact was deleted locally - need to re-insert and sync
            elseif($responseData['status'] === Constants::DELIVERY_UPDATED){
                $senderAddress = $responseData['senderAddress'];
                // Contact was deleted locally - re-insert and sync
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    // Insert initial balances - will be updated by full sync below
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Only create a new contact TX if the remote doesn't have one (no txid in response)
                    // AND we don't have one locally. When the remote provides a txid, syncReaddedContact()
                    // will sync the original contact TX with correct signatures.
                    $remoteTxid = $responseData['txid'] ?? null;
                    if ($remoteTxid === null && !$this->contactTransactionExists($senderPublicKey)) {
                        $this->insertContactTransaction($senderPublicKey, $address, $currency);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        $txid = $this->transactionContactRepository->getContactTransactionByParties(
                            $this->currentUser->getPublicKey(), $senderPublicKey
                        )['txid'] ?? null;
                        if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }
                    }

                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Full sync for re-added contact: sync contact status, transaction chain, and balances
                    $syncService = $this->requireSyncTrigger();
                    $syncResult = $syncService->syncReaddedContact($address, $senderPublicKey);

                    // Safety net: if sync didn't bring in the recipient_signature, store it from the response
                    $recipientSignature = $responseData['recipientSignature'] ?? null;
                    if ($recipientSignature !== null) {
                        $contactTx = $this->transactionContactRepository->getContactTransactionByParties(
                            $this->currentUser->getPublicKey(), $senderPublicKey
                        );
                        if ($contactTx && isset($contactTx['txid'])) {
                            $this->transactionRepository->updateRecipientSignature($contactTx['txid'], $recipientSignature);
                        }
                    }

                    if ($syncResult['success']) {
                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['pubkey'] = $senderPublicKey;
                        $contactData['sync'] = [
                            'transactions_synced' => $syncResult['transactions_synced'],
                            'balances_synced' => $syncResult['balances_synced'],
                            'currencies' => $syncResult['currencies']
                        ];
                        $output->success("Contact re-added and fully synced with " . $address, $contactData, "Contact created with transaction and balance sync");
                    } else {
                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $output->success("Contact re-added, awaiting sync with " . $address, $contactData, "Contact created, sync pending");
                    }
                } else {
                    $output->error("Failed to re-add contact with " . $address, ErrorCodes::CONTACT_CREATE_FAILED, 500, ['contact' => $contactData]);
                }
            }
            // Our contact pubkey and address both exist on their end (Case when we delete the contact and try re-adding it)
            elseif($responseData['status'] === Constants::DELIVERY_WARNING){
                // Insert contact and perform full sync (transactions + balances)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    // Insert initial balances - will be updated by full sync below
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Only create a new contact TX if the remote doesn't have one (no txid in response)
                    // AND we don't have one locally. When the remote provides a txid, it means the
                    // original contact TX exists on their end — syncReaddedContact() will sync it
                    // with the correct txid, nonce, and signatures from the original establishment.
                    $remoteTxid = $responseData['txid'] ?? null;
                    if ($remoteTxid === null && !$this->contactTransactionExists($senderPublicKey)) {
                        $this->insertContactTransaction($senderPublicKey, $address, $currency);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        $txid = $this->transactionContactRepository->getContactTransactionByParties(
                            $this->currentUser->getPublicKey(), $senderPublicKey
                        )['txid'] ?? null;
                        if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }
                    }

                    // Update delivery stage: warning -> inserted -> completed (using MessageDeliveryService directly)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Full sync for re-added contact: sync contact status, transaction chain, and balances
                    // If contact still has transaction chain on their end, resync from original contact transaction
                    // through all known transactions (verifying signatures) and finally sync balances
                    // This will bring in the original contact TX with its recipient_signature
                    $syncService = $this->requireSyncTrigger();
                    $syncResult = $syncService->syncReaddedContact($address, $senderPublicKey);

                    // Safety net: if sync didn't bring in the recipient_signature, store it from the response
                    $recipientSignature = $responseData['recipientSignature'] ?? null;
                    if ($recipientSignature !== null) {
                        $contactTx = $this->transactionContactRepository->getContactTransactionByParties(
                            $this->currentUser->getPublicKey(), $senderPublicKey
                        );
                        if ($contactTx && isset($contactTx['txid'])) {
                            $this->transactionRepository->updateRecipientSignature($contactTx['txid'], $recipientSignature);
                        }
                    }

                    if ($syncResult['success']) {
                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['pubkey'] = $senderPublicKey;
                        $contactData['sync'] = [
                            'transactions_synced' => $syncResult['transactions_synced'],
                            'balances_synced' => $syncResult['balances_synced'],
                            'currencies' => $syncResult['currencies']
                        ];
                        $output->success("Contact re-added and fully synced with " . $address, $contactData, "Contact created with transaction and balance sync");
                    } else {
                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $output->success("Contact re-added, awaiting sync with " . $address, $contactData, "Contact created, sync pending");
                    }
                }
            }
            // Our contact request could not be processed on their end
            elseif($responseData['status'] === Constants::STATUS_REJECTED){
                $output->error("Contact request rejected by " . $address . " : " . ($responseData['message'] ?? 'Unknown reason'), ErrorCodes::CONTACT_REJECTED, 403, [
                    'contact' => $contactData,
                    'response' => $responseData
                ]);
                return;
            }
        } else{
            // No immediate response - check if message was queued for background retry
            // This is expected behavior for async mode over slow Tor connections
            if ($sendResult['queued_for_retry'] ?? false) {
                // Message is being retried in the background by the message processor
                // Insert contact locally as pending so user can see it in their contact list
                $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                $contactData['delivery_status'] = 'queued_for_retry';
                $output->success(
                    "Contact request sent to " . $address . ". Awaiting response (message being delivered in background).",
                    $contactData,
                    "Contact request sent, delivery in progress"
                );
                return;
            }

            // Message delivery failed completely (not queued for retry)
            // Tracking results are nested inside 'tracking' key from sendContactMessageInternal
            $trackingResult = $sendResult['tracking'] ?? [];
            $attempts = $trackingResult['attempts'] ?? 'unknown';
            $lastError = $trackingResult['error'] ?? 'No response received';

            $output->error(
                "Failed to reach contact address after " . $attempts . " attempts. " .
                "Address " . $address . " may not exist or is offline.",
                ErrorCodes::CONTACT_UNREACHABLE,
                null,
                [
                    'contact' => $contactData,
                    'attempts' => $attempts,
                    'last_error' => $lastError,
                    'moved_to_dlq' => $trackingResult['dlq'] ?? false
                ]
            );
            return;
        }
    }

    // =========================================================================
    // CONTACT CREATION HANDLER
    // =========================================================================

    /**
     * Handle contact creation request (incoming)
     *
     * @param array $request Request data
     * @return string Response payload
     */
    public function handleContactCreation(array $request): string {
        $senderAddress = $request['senderAddress'];
        $senderPublicKey = $request['senderPublicKey'];
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($senderAddress);

        // Extract sender's signature data for storing with the contact transaction
        $signature = $request['signature'] ?? null;
        $nonce = $request['nonce'] ?? null;

        // Extract sender's additional addresses for storage (allows fallback transport)
        $senderAddresses = $request['senderAddresses'] ?? [];

        // Extract sender's preferred currency (falls back to receiver's default)
        $currency = $request['currency'] ?? $this->currentUser->getDefaultCurrency();

        // Validate currency against allowed currencies — auto-reject if not allowed
        $allowedCurrencies = $this->currentUser->getAllowedCurrencies();
        if (!in_array($currency, $allowedCurrencies)) {
            return $this->contactPayload->buildRejection(
                $senderAddress,
                'Currency ' . $currency . ' is not accepted by this node'
            );
        }

        // Get our own (the responder's) addresses to include in response
        // This allows the requester to store all our known addresses
        $myAddresses = $this->currentUser->getUserLocaters();

        // Check if contact already exists
        if ($this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            $contactAddresses = $this->addressRepository->lookupByPubkeyHash($senderPublicKeyHash);
            $transportIndex = $this->transportUtility->determineTransportType($senderAddress);
            // Check if we have a valid transport type and the address matches
            if($transportIndex !== null && isset($contactAddresses[$transportIndex]) && $contactAddresses[$transportIndex] === $senderAddress){
                // Address already exists - check contact status for re-add scenario
                // When a deleted contact re-adds us, we may have them as 'pending'
                // (they added us before but we never accepted, then they deleted and re-added)
                $existingContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
                if ($existingContact && $existingContact['status'] === Constants::CONTACT_STATUS_PENDING) {
                    // Check if WE initiated a request to them (name is set when we sent the request)
                    // AND currencies must match — check contact_currencies for outgoing currency match
                    $currencyMatchesOutgoing = false;
                    if ($this->contactCurrencyRepository !== null && $existingContact['name'] !== null) {
                        $currencyMatchesOutgoing = $this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing');
                    }

                    if ($currencyMatchesOutgoing) {
                        // Mutual request with matching currency: auto-accept
                        if (!empty($senderAddresses) && is_array($senderAddresses)) {
                            $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                        }

                        // Get fee/credit from our outgoing currency config
                        $outgoingConfig = $this->contactCurrencyRepository->getCurrencyConfig($senderPublicKeyHash, $currency, 'outgoing');
                        $fee = (float) ($outgoingConfig['fee_percent'] ?? 0);
                        $credit = (float) ($outgoingConfig['credit_limit'] ?? 0);

                        $this->acceptContact(
                            $senderPublicKey,
                            $existingContact['name'],
                            $fee,
                            $credit,
                            $currency
                        );

                        // Mark currency as accepted (single row per pubkey_hash+currency)
                        $this->contactCurrencyRepository->updateCurrencyStatus($senderPublicKeyHash, $currency, 'accepted');

                        // Ensure we have a received contact transaction for this specific currency
                        // Must be created BEFORE generating recipient signature (needs signature_nonce)
                        $hasContactTx = $this->transactionContactRepository->contactTransactionExistsForReceiver(
                            $senderPublicKeyHash, $currency
                        );
                        $txid = null;
                        if (!$hasContactTx) {
                            $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);
                        }

                        // Generate recipient signature after TX exists (needs signature_nonce from TX)
                        $recipientSig = $this->generateAndStoreContactRecipientSignature($senderPublicKey);

                        $this->completeReceivedContactTransaction($senderPublicKey);

                        return $this->contactPayload->buildMutuallyAccepted($senderAddress, $myAddresses, $txid, $recipientSig);
                    }

                    // Contact exists as pending with name=null (they sent us a request first)
                    // OR: name is set but currencies differ (mutual request with mismatched terms)

                    // Store the remote's currency request so the GUI can inform the user
                    if ($this->contactCurrencyRepository !== null) {
                        if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency)) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $senderPublicKeyHash, $currency, 0, null, 'pending', 'incoming'
                            );
                        }
                    }

                    // Check if we have a contact transaction for this specific currency
                    $hasContactTxForCurrency = $this->transactionContactRepository->contactTransactionExistsForReceiver(
                        $senderPublicKeyHash, $currency
                    );

                    if (!$hasContactTxForCurrency) {
                        // Store any additional addresses from senderAddresses if present
                        if (!empty($senderAddresses) && is_array($senderAddresses)) {
                            $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                        }
                        // Create the contact transaction on our side for this currency
                        $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);
                        // Return 'received' with txid so sender can sync
                        return $this->contactPayload->buildReceived($senderAddress, $myAddresses, $txid);
                    }

                    // Contact exists as pending with contact transaction for this currency - treat as re-confirmation
                    // Return 'received' so sender handles it like a new contact (no sync attempt)
                    // Don't include other addresses for pending contacts (privacy)
                    return $this->contactPayload->buildReceived($senderAddress);
                }
                // Contact is accepted or other status
                // Store any additional addresses from senderAddresses if present (re-add scenario)
                if (!empty($senderAddresses) && is_array($senderAddresses)) {
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                }

                // Check if this is a new currency request for an existing accepted contact
                $existingContactForCurrency = $this->contactRepository->getContactByPubkey($senderPublicKey);
                if ($existingContactForCurrency
                    && $existingContactForCurrency['status'] === Constants::CONTACT_STATUS_ACCEPTED
                    && $this->contactCurrencyRepository !== null
                ) {
                    $existingCurrencyConfig = $this->contactCurrencyRepository->getCurrencyConfig($senderPublicKeyHash, $currency);

                    if ($existingCurrencyConfig && $existingCurrencyConfig['direction'] === 'outgoing') {
                        // We already sent a request for this currency — mutual match, auto-accept
                        $this->contactCurrencyRepository->updateCurrencyStatus($senderPublicKeyHash, $currency, 'accepted');

                        // Ensure balance and credit entries exist for the new currency
                        $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);
                        if ($this->contactCreditRepository !== null) {
                            try {
                                $this->contactCreditRepository->createInitialCredit($senderPublicKey, $currency);
                            } catch (\Exception $e) {
                                Logger::getInstance()->log('Credit may already exist during acceptance for currency ' . $currency . ': ' . $e->getMessage(), 'DEBUG');
                            }
                        }

                        // Create contact transaction for this currency so both sides have matching per-currency chains
                        $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);

                        return $this->contactPayload->buildReceived($senderAddress, $myAddresses, $txid);
                    } elseif (!$existingCurrencyConfig) {
                        // New currency from existing contact — insert as pending incoming (requires user acceptance)
                        $this->contactCurrencyRepository->insertCurrencyConfig($senderPublicKeyHash, $currency, 0, null, 'pending', 'incoming');

                        // Create contact transaction for this currency
                        $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);

                        return $this->contactPayload->buildReceived($senderAddress, $myAddresses, $txid);
                    }
                }

                // Generate recipient signature for dual-signature protocol (re-add scenario)
                // The sender may have lost their database; include txid + signature so they can restore dual-sig
                $recipientSig = $this->generateAndStoreContactRecipientSignature($senderPublicKey);
                $txData = $this->transactionContactRepository->getContactTransactionByParties(
                    $senderPublicKey, $this->currentUser->getPublicKey()
                );
                $txid = $txData['txid'] ?? null;

                // Include all our known addresses so sender can store them (re-add scenario)
                return $this->contactPayload->buildAlreadyExists($senderAddress, $myAddresses, $txid, $recipientSig);
            } else{
                // Address unknown prior but pubkey exists (known contact, unknown address)
                // Check contact status - if pending, treat as re-confirmation with new address
                $existingContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
                if ($existingContact && $existingContact['status'] === Constants::CONTACT_STATUS_PENDING) {
                    // Update their address first
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (!empty($senderAddresses) && is_array($senderAddresses)) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                    }

                    // Check if WE initiated a request to them (name is set when we sent the request)
                    // AND currencies must match — check contact_currencies for outgoing currency match
                    $currencyMatchesOutgoing = false;
                    if ($this->contactCurrencyRepository !== null && $existingContact['name'] !== null) {
                        $currencyMatchesOutgoing = $this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency, 'outgoing');
                    }

                    if ($currencyMatchesOutgoing) {
                        // Mutual request with matching currency: auto-accept
                        // Get fee/credit from our outgoing currency config
                        $outgoingConfig = $this->contactCurrencyRepository->getCurrencyConfig($senderPublicKeyHash, $currency, 'outgoing');
                        $fee = (float) ($outgoingConfig['fee_percent'] ?? 0);
                        $credit = (float) ($outgoingConfig['credit_limit'] ?? 0);

                        $this->acceptContact(
                            $senderPublicKey,
                            $existingContact['name'],
                            $fee,
                            $credit,
                            $currency
                        );

                        // Mark currency as accepted (single row per pubkey_hash+currency)
                        $this->contactCurrencyRepository->updateCurrencyStatus($senderPublicKeyHash, $currency, 'accepted');

                        // Ensure we have a received contact transaction for this specific currency
                        // Must be created BEFORE generating recipient signature (needs signature_nonce)
                        $hasContactTx = $this->transactionContactRepository->contactTransactionExistsForReceiver(
                            $senderPublicKeyHash, $currency
                        );
                        $txid = null;
                        if (!$hasContactTx) {
                            $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);
                        }

                        // Generate recipient signature after TX exists (needs signature_nonce from TX)
                        $recipientSig = $this->generateAndStoreContactRecipientSignature($senderPublicKey);

                        $this->completeReceivedContactTransaction($senderPublicKey);

                        return $this->contactPayload->buildMutuallyAccepted($senderAddress, $myAddresses, $txid, $recipientSig);
                    }

                    // Contact is pending with name=null (they sent us a request first)
                    // OR: name is set but currencies differ (mutual request with mismatched terms)

                    // Store the remote's currency request so the GUI can inform the user
                    if ($this->contactCurrencyRepository !== null) {
                        if (!$this->contactCurrencyRepository->hasCurrency($senderPublicKeyHash, $currency)) {
                            $this->contactCurrencyRepository->insertCurrencyConfig(
                                $senderPublicKeyHash, $currency, 0, null, 'pending', 'incoming'
                            );
                        }
                    }

                    // Check if we have a contact transaction for this specific currency
                    $hasContactTx = $this->transactionContactRepository->contactTransactionExistsForReceiver(
                        $senderPublicKeyHash, $currency
                    );

                    if (!$hasContactTx) {
                        // Create the contact transaction on our side for this currency
                        $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);
                        return $this->contactPayload->buildReceived($senderAddress, $myAddresses, $txid);
                    }

                    // Return 'received' so sender handles it like a new contact request
                    return $this->contactPayload->buildReceived($senderAddress);
                }
                // Contact is accepted - update address and return 'updated'
                // Store any additional addresses from senderAddresses if present
                if (!empty($senderAddresses) && is_array($senderAddresses)) {
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                }

                // Generate recipient signature for dual-signature protocol (re-add scenario)
                $recipientSig = $this->generateAndStoreContactRecipientSignature($senderPublicKey);
                $txData = $this->transactionContactRepository->getContactTransactionByParties(
                    $senderPublicKey, $this->currentUser->getPublicKey()
                );
                $txid = $txData['txid'] ?? null;

                // Include all our known addresses so sender can store them (re-add scenario)
                if($this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative)){
                    return $this->contactPayload->buildUpdated($senderAddress, $myAddresses, $txid, $recipientSig);
                } else{
                    // Unable to update contact
                    return $this->contactPayload->buildRejection($senderAddress);
                }
            }
        } else{
            // Contact request is brand new, no prior users exist in any form
            if($this->contactRepository->addPendingContact($senderPublicKey) && $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative)){
                // Store any additional addresses from senderAddresses if present
                if (!empty($senderAddresses) && is_array($senderAddresses)) {
                    $this->addressRepository->updateContactFields($senderPublicKeyHash, $senderAddresses);
                }

                // Store the sender's requested currency as a pending incoming request
                // This preserves which currency they want so the receiver can accept/reject per-currency
                if ($this->contactCurrencyRepository !== null && !empty($currency)) {
                    $this->contactCurrencyRepository->insertCurrencyConfig(
                        $senderPublicKeyHash, $currency, 0, null, 'pending', 'incoming'
                    );
                }

                // Insert received contact transaction with status 'accepted' (pending user acceptance)
                // This creates the contact transaction on the receiver's side
                // Receiver generates txid and includes it in response for sender to use
                $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, $currency, $signature, $nonce);
                return $this->contactPayload->buildReceived($senderAddress, $myAddresses, $txid);
            } else{
                // Unable to insert contact
                return $this->contactPayload->buildRejection($senderAddress);
            }
        }
    }

    // =========================================================================
    // PUBLIC HELPERS
    // =========================================================================

    /**
     * Send a currency acceptance notification to a remote contact
     *
     * Called from the GUI when a user accepts a pending incoming currency.
     * Notifies the remote side so they can mark their outgoing currency as accepted.
     * Also upgrades the contact status to 'accepted' if it was still 'pending'.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency The currency that was accepted
     * @return bool True if notification was sent successfully
     */
    public function sendCurrencyAcceptanceNotification(string $pubkeyHash, string $currency): bool {
        // Look up the contact's address
        $addresses = $this->addressRepository->lookupByPubkeyHash($pubkeyHash);
        $address = $addresses['http'] ?? $addresses['https'] ?? $addresses['tor'] ?? null;
        if ($address === null) {
            Logger::getInstance()->warning("Cannot send currency acceptance: no address found", [
                'pubkey_hash' => substr($pubkeyHash, 0, 16),
                'currency' => $currency,
            ]);
            return false;
        }

        // Get the contact's pubkey for status update
        $pubkey = $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);
        if ($pubkey === null) {
            return false;
        }
        $contact = $this->contactRepository->getContactByPubkey($pubkey);
        if ($contact === null) {
            return false;
        }

        // Build and send the acceptance message with currency info
        $acceptPayload = $this->messagePayload->buildContactIsAccepted($address, false, null, $currency);
        $messageId = 'currency-accept-' . hash('sha256', $address . $pubkeyHash . $currency . $this->timeUtility->getCurrentMicrotime());
        $sendResult = $this->sendContactMessageInternal($address, $acceptPayload, $messageId, false);

        if (!$sendResult['success']) {
            Logger::getInstance()->warning("Currency acceptance notification delivery failed", [
                'recipient_address' => $address,
                'currency' => $currency,
                'message_id' => $messageId,
                'error' => $sendResult['tracking']['error'] ?? 'unknown'
            ]);
        }

        // If contact is still pending, upgrade to accepted (we now have a mutually accepted currency)
        if ($contact['status'] === Constants::CONTACT_STATUS_PENDING) {
            $this->contactRepository->updateStatus($contact['pubkey'], Constants::STATUS_ACCEPTED);

            // Complete the received contact transaction if it exists
            $this->completeReceivedContactTransaction($contact['pubkey']);
        }

        return $sendResult['success'];
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Generate and store recipient signature for a contact transaction
     *
     * Looks up the contact transaction between the sender and current user,
     * generates a recipient signature (signing the same message the sender signed),
     * and stores it on the transaction. Returns the signature for inclusion in
     * the acceptance message sent back to the sender.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @return string|null The recipient signature, or null if generation failed
     */
    private function generateAndStoreContactRecipientSignature(string $senderPublicKey): ?string
    {
        $txData = $this->transactionContactRepository->getContactTransactionByParties(
            $senderPublicKey, $this->currentUser->getPublicKey()
        );

        if ($txData === null || empty($txData['signature_nonce'])) {
            return null;
        }

        $recipientSig = $this->contactPayload->generateRecipientSignature($txData['signature_nonce'], $txData['currency'] ?? null);

        if ($recipientSig === null) {
            return null;
        }

        $this->transactionRepository->updateRecipientSignature($txData['txid'], $recipientSig);

        return $recipientSig;
    }

    /**
     * Store the recipient signature from a remote's acceptance response on our sent contact TX.
     *
     * When we initiate a contact request (sender=us, receiver=them), the remote generates
     * the recipient signature and includes it in the STATUS_ACCEPTED response. This method
     * extracts that signature and stores it on our local sent contact TX.
     *
     * @param string $contactPublicKey The public key of the contact (the remote/receiver)
     * @param array $responseData The decoded response from the remote
     */
    private function storeRecipientSignatureFromResponse(string $contactPublicKey, array $responseData): void
    {
        $recipientSignature = $responseData['recipientSignature'] ?? null;
        if ($recipientSignature === null) {
            return;
        }

        // Our sent contact TX: sender=us, receiver=them
        $contactTx = $this->transactionContactRepository->getContactTransactionByParties(
            $this->currentUser->getPublicKey(), $contactPublicKey
        );

        if ($contactTx && isset($contactTx['txid'])) {
            $this->transactionRepository->updateRecipientSignature($contactTx['txid'], $recipientSignature);
        }
    }

    /**
     * Accept a contact request
     *
     * @param string $pubkey Contact pubkey
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    private function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency): bool {
        $success = $this->contactRepository->acceptContact($pubkey, $name, $fee, $credit, $currency);
        if($success){
            // Addresses already saved, just need to add initial contact balances
            $this->balanceRepository->insertInitialContactBalances($pubkey, $currency);

            // Create initial contact credit entry (available_credit = 0 until first ping)
            if ($this->contactCreditRepository !== null) {
                try {
                    $this->contactCreditRepository->createInitialCredit($pubkey, $currency);
                } catch (\Exception $e) {
                    Logger::getInstance()->warning("Failed to create initial contact credit entry", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Ensure the accepted currency is tracked in contact_currencies (single row per pubkey+currency)
            if ($this->contactCurrencyRepository !== null) {
                $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);
                if (!$this->contactCurrencyRepository->hasCurrency($pubkeyHash, $currency)) {
                    $this->contactCurrencyRepository->insertCurrencyConfig(
                        $pubkeyHash, $currency, (int) $fee, (int) $credit, 'accepted', 'incoming'
                    );
                } else {
                    $this->contactCurrencyRepository->updateCurrencyConfig($pubkeyHash, $currency, [
                        'fee_percent' => (int) $fee,
                        'credit_limit' => (int) $credit,
                        'status' => 'accepted',
                    ]);
                }
            }

            // Recalculate balances from existing transactions (wallet restore scenario:
            // transactions were synced during ping but balances are still zero)
            $syncTrigger = $this->getSyncTrigger();
            if ($syncTrigger !== null) {
                try {
                    $syncTrigger->syncContactBalance($pubkey);
                } catch (\Exception $e) {
                    Logger::getInstance()->warning("Failed to sync contact balance after acceptance", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        return $success;
    }
}
