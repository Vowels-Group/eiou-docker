<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\ContactStatusServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\ChainDropServiceInterface;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Schemas\Payloads\ContactStatusPayload;
use RuntimeException;
use Exception;

/**
 * Contact Status Service
 *
 * Handles incoming ping requests from other nodes and outgoing manual pings.
 * Responds with pong containing local chain state for comparison.
 */
class ContactStatusService implements ContactStatusServiceInterface {
    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var TransportUtilityService Transport utility for sending pings
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var ContactStatusPayload Payload builder
     */
    private ContactStatusPayload $contactStatusPayload;

    /**
     * @var SyncTriggerInterface|null Sync trigger for chain synchronization
     */
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * @var RateLimiterService|null Rate limiter service for manual ping rate limiting
     */
    private ?RateLimiterService $rateLimiterService = null;

    /**
     * @var TransactionChainRepository|null Chain repository for internal gap detection
     */
    private ?TransactionChainRepository $transactionChainRepository = null;

    /**
     * @var ChainDropServiceInterface|null Chain drop service for auto-proposing drops on mutual gaps
     */
    private ?ChainDropServiceInterface $chainDropService = null;

    /**
     * @var AddressRepository|null Address repository for auto-creating contacts on ping
     */
    private ?AddressRepository $addressRepository = null;

    /**
     * @var BalanceRepository|null Balance repository for calculating available credit
     */
    private ?BalanceRepository $balanceRepository = null;

    /**
     * @var ContactCreditRepository|null Contact credit repository for storing available credit from pong
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void {
        $this->syncTrigger = $sync;
    }

    /**
     * Get the sync trigger (must be injected via setSyncTrigger)
     *
     * @return SyncTriggerInterface
     * @throws RuntimeException If sync trigger was not injected
     */
    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    /**
     * Set the rate limiter service (setter injection for circular dependency)
     *
     * @param RateLimiterService $service Rate limiter service
     */
    public function setRateLimiterService(RateLimiterService $service): void {
        $this->rateLimiterService = $service;
    }

    /**
     * Set the transaction chain repository (setter injection)
     *
     * @param TransactionChainRepository $repo Chain repository
     */
    public function setTransactionChainRepository(TransactionChainRepository $repo): void {
        $this->transactionChainRepository = $repo;
    }

    /**
     * Set the chain drop service for auto-proposing drops on mutual gaps
     *
     * @param ChainDropServiceInterface $service Chain drop service
     */
    public function setChainDropService(ChainDropServiceInterface $service): void {
        $this->chainDropService = $service;
    }

    /**
     * Set the address repository for auto-creating contacts from pings
     *
     * @param AddressRepository $repo Address repository
     */
    public function setAddressRepository(AddressRepository $repo): void {
        $this->addressRepository = $repo;
    }

    /**
     * Set the balance repository for calculating available credit in pong responses
     *
     * @param BalanceRepository $repo Balance repository
     */
    public function setBalanceRepository(BalanceRepository $repo): void {
        $this->balanceRepository = $repo;
    }

    /**
     * Set the contact credit repository for storing available credit from pong responses
     *
     * @param ContactCreditRepository $repo Contact credit repository
     */
    public function setContactCreditRepository(ContactCreditRepository $repo): void {
        $this->contactCreditRepository = $repo;
    }

    /**
     * Get the rate limiter service (must be injected via setRateLimiterService)
     *
     * @return RateLimiterService
     * @throws RuntimeException If rate limiter service was not injected
     */
    private function getRateLimiterService(): RateLimiterService {
        if ($this->rateLimiterService === null) {
            throw new RuntimeException('RateLimiterService not injected. Call setRateLimiterService() or ensure ServiceContainer::wireCircularDependencies() is called.');
        }
        return $this->rateLimiterService;
    }

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility container
     * @param UserContext $currentUser Current user context
     */
    public function __construct(
        ContactRepository $contactRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;

        // Initialize payload builder
        $this->contactStatusPayload = new ContactStatusPayload($this->currentUser, $this->utilityContainer);
    }

    /**
     * Handle incoming ping request
     *
     * @param array $request The ping request data
     * @return void Echoes JSON response
     */
    public function handlePingRequest(array $request): void {
        // Validate sender public key exists
        if (!isset($request['senderPublicKey'])) {
            echo json_encode([
                'status' => 'rejected',
                'reason' => 'missing_public_key',
                'message' => 'Sender public key is required'
            ]);
            return;
        }

        // Check if contact status feature is enabled
        if (!Constants::CONTACT_STATUS_ENABLED) {
            echo $this->contactStatusPayload->buildRejection($request, 'disabled');
            return;
        }

        $senderPubkey = $request['senderPublicKey'];

        // Check if sender is an accepted contact
        if (!$this->contactRepository->isAcceptedContactPubkey($senderPubkey)) {
            // Check if contact exists but is blocked
            if (!$this->contactRepository->isNotBlocked($senderPubkey)) {
                echo $this->contactStatusPayload->buildRejection($request, 'blocked');
                return;
            }

            // Check if contact exists at all (pending or otherwise)
            if ($this->contactRepository->contactExistsPubkey($senderPubkey)) {
                // Contact exists but is not accepted (pending) — reject normally
                echo $this->contactStatusPayload->buildRejection($request, 'unknown_contact');
                return;
            }

            // Contact is completely unknown — possible wallet restore scenario
            // Auto-create as pending contact and trigger sync to restore transaction history
            if ($this->addressRepository !== null) {
                $senderAddress = $request['senderAddress'] ?? '';
                $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($senderAddress);

                if ($transportIndexAssociative !== null) {
                    try {
                        $this->contactRepository->addPendingContact($senderPubkey);
                        $this->addressRepository->insertAddress($senderPubkey, $transportIndexAssociative);

                        Logger::getInstance()->info("Auto-created pending contact from ping (possible wallet restore)", [
                            'sender_address' => $senderAddress
                        ]);

                        // Trigger sync to restore transaction chain from the remote side
                        $this->triggerSync($senderAddress, $senderPubkey);

                        // After sync, get updated chain state
                        $localPrevTxid = $this->transactionRepository->getPreviousTxid(
                            $this->currentUser->getPublicKey(),
                            $senderPubkey
                        );

                        // Update online status for the newly created contact
                        $this->updateContactOnlineStatus($senderPubkey);

                        // Respond with pong so remote knows we're alive
                        // Chain is marked invalid since we just restored from scratch
                        echo $this->contactStatusPayload->buildResponse($request, $localPrevTxid, false);
                        return;
                    } catch (\Exception $e) {
                        Logger::getInstance()->warning("Failed to auto-create pending contact from ping", [
                            'sender_address' => $senderAddress,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Fallback: reject if auto-creation failed or dependencies not available
            echo $this->contactStatusPayload->buildRejection($request, 'unknown_contact');
            return;
        }

        // Get our local prev_txid for this contact's chain
        $localPrevTxid = $this->transactionRepository->getPreviousTxid(
            $this->currentUser->getPublicKey(),
            $senderPubkey
        );

        // Compare with sender's prev_txid
        $remotePrevTxid = $request['prevTxid'] ?? null;
        $chainValid = true;

        if ($remotePrevTxid !== null && $localPrevTxid !== null) {
            // Both have transactions - compare chain heads
            $chainValid = ($localPrevTxid === $remotePrevTxid);
        }

        // Also check for internal chain gaps (e.g., deleted transactions in the middle)
        // Chain heads can match even when internal transactions are missing
        if ($chainValid && $this->transactionChainRepository !== null) {
            $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $senderPubkey
            );
            if (!$chainStatus['valid']) {
                $chainValid = false;
            }
        }

        // If chains don't match and sync was requested, trigger sync
        if (!$chainValid && ($request['requestSync'] ?? false)) {
            $this->triggerSync($request['senderAddress'] ?? '', $senderPubkey);
        }

        // Calculate available credit for the pinging contact
        $availableCredit = null;
        $contactCurrency = null;
        if ($this->balanceRepository !== null) {
            try {
                $contactData = $this->contactRepository->getContactByPubkey($senderPubkey);
                $contactCurrency = $contactData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $sentBalance = $this->balanceRepository->getContactSentBalance($senderPubkey, $contactCurrency);
                $receivedBalance = $this->balanceRepository->getContactReceivedBalance($senderPubkey, $contactCurrency);
                $balance = $sentBalance - $receivedBalance;
                $creditLimit = (int) ($contactData['credit_limit'] ?? 0);
                $availableCredit = $balance + $creditLimit;
            } catch (\Exception $e) {
                Logger::getInstance()->warning("Failed to calculate available credit for ping response", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Update the sender's online status since they pinged us
        $this->updateContactOnlineStatus($senderPubkey);

        // Send pong response with available credit
        echo $this->contactStatusPayload->buildResponse($request, $localPrevTxid, $chainValid, $availableCredit, $contactCurrency);
    }

    /**
     * Update contact's online status to online and record last ping time
     *
     * @param string $pubkey Contact public key
     */
    private function updateContactOnlineStatus(string $pubkey): void {
        try {
            $this->contactRepository->updateContactFields($pubkey, [
                'online_status' => Constants::CONTACT_ONLINE_STATUS_ONLINE,
                'last_ping_at' => date('Y-m-d H:i:s.u')
            ]);
        } catch (\Exception $e) {
            Logger::getInstance()->error("Failed to update contact online status on ping receive", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger transaction chain sync with contact
     *
     * @param string $address Contact address
     * @param string $pubkey Contact public key
     * @return array|null Sync result or null on failure
     */
    private function triggerSync(string $address, string $pubkey): ?array {
        try {
            // Use existing sync method
            $result = $this->getSyncTrigger()->syncTransactionChain($address, $pubkey);

            Logger::getInstance()->info("Chain sync triggered from incoming ping request", [
                'contact_address' => $address
            ]);

            return $result;
        } catch (\Exception $e) {
            Logger::getInstance()->warning("Chain sync failed during incoming ping", [
                'contact_address' => $address,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get the contact repository (for processor access)
     *
     * @return ContactRepository
     */
    public function getRepository(): ContactRepository {
        return $this->contactRepository;
    }

    /**
     * Manually ping a specific contact and update their status
     *
     * Rate limited to 3 pings per minute per user to prevent abuse.
     *
     * @param string $identifier Contact name or address to ping
     * @return array Result with status, online_status, chain_valid, and message
     */
    public function pingContact(string $identifier): array {
        // Rate limit manual pings: 3 per 5 minutes, block for 300 seconds if exceeded
        // Aligns with the automatic ping processor's minimum interval of 5 minutes
        $rateLimiter = $this->getRateLimiterService();
        $userIdentifier = $this->currentUser->getPublicKeyHash() ?? 'anonymous';
        $rateCheck = $rateLimiter->checkLimit($userIdentifier, 'manual_ping', 3, 300, 300);

        if (!$rateCheck['allowed']) {
            $retryAfter = $rateCheck['retry_after'] ?? 300;
            return [
                'success' => false,
                'error' => 'rate_limited',
                'message' => "Too many ping requests. Please wait {$retryAfter} seconds.",
                'retry_after' => $retryAfter
            ];
        }

        // Find the contact by name or address
        $contact = $this->contactRepository->getContactByNameOrAddress($identifier);

        if (!$contact) {
            return [
                'success' => false,
                'error' => 'contact_not_found',
                'message' => "Contact not found: $identifier"
            ];
        }

        // Check if contact is accepted
        if ($contact['status'] !== 'accepted') {
            return [
                'success' => false,
                'error' => 'contact_not_accepted',
                'message' => "Contact is not accepted (status: {$contact['status']})"
            ];
        }

        // Get contact address (security priority: Tor > HTTPS > HTTP)
        $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;

        if (!$contactAddress) {
            return [
                'success' => false,
                'error' => 'no_address',
                'message' => 'Contact has no address configured'
            ];
        }

        try {
            // Get the latest transaction ID in the chain with this contact
            $prevTxid = $this->transactionRepository->getPreviousTxid(
                $this->currentUser->getPublicKey(),
                $contact['pubkey']
            );

            // Build ping payload
            $payload = $this->contactStatusPayload->build([
                'receiverAddress' => $contactAddress,
                'prevTxid' => $prevTxid,
                'requestSync' => Constants::CONTACT_STATUS_SYNC_ON_PING
            ]);

            // Send ping
            Logger::getInstance()->info("Manual ping initiated", [
                'contact_name' => $contact['name'],
                'contact_address' => $contactAddress
            ]);

            $rawResponse = $this->transportUtility->send($contactAddress, $payload);
            $response = json_decode($rawResponse, true);

            // Update contact based on response
            if ($response && isset($response['status'])) {
                Logger::getInstance()->info("Manual ping response received", [
                    'contact_name' => $contact['name'],
                    'status' => $response['status'],
                    'chain_valid' => $response['chainValid'] ?? 'not provided'
                ]);

                if ($response['status'] === 'pong') {
                    // Contact is online
                    $this->updateContactStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_ONLINE);

                    // Save available credit from pong response
                    $this->saveAvailableCreditFromPong($contact['pubkey'], $response);

                    // Check chain validity from remote response
                    $chainValid = $response['chainValid'] ?? true;
                    $remotePrevTxid = $response['prevTxid'] ?? null;

                    // Also verify local chain integrity for internal gaps
                    // Chain heads can match even when transactions in the middle are deleted
                    if ($chainValid && $this->transactionChainRepository !== null) {
                        $localChainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                            $this->currentUser->getPublicKey(),
                            $contact['pubkey']
                        );
                        if (!$localChainStatus['valid']) {
                            $chainValid = false;
                        }
                    }

                    // If chains don't match, update status and optionally trigger sync
                    if (!$chainValid || ($prevTxid !== $remotePrevTxid && $prevTxid !== null && $remotePrevTxid !== null)) {
                        $this->updateChainStatus($contact['pubkey'], false);

                        // Trigger sync if enabled
                        $syncResult = null;
                        if (Constants::CONTACT_STATUS_SYNC_ON_PING) {
                            $syncResult = $this->triggerSync($contactAddress, $contact['pubkey']);
                        }

                        // Auto-propose chain drop if sync completed but mutual gaps remain
                        if ($syncResult && !($syncResult['chain_valid'] ?? true) && !empty($syncResult['chain_gaps'] ?? []) && $this->chainDropService) {
                            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contact['pubkey']);
                            try {
                                $proposeResult = $this->chainDropService->proposeChainDrop($contactPubkeyHash);
                                if ($proposeResult['success']) {
                                    Logger::getInstance()->info("Auto-proposed chain drop after sync detected mutual gap", [
                                        'contact_name' => $contact['name'],
                                        'proposal_id' => $proposeResult['proposal_id']
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Logger::getInstance()->warning("Auto-propose chain drop failed", [
                                    'contact_name' => $contact['name'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }

                        return [
                            'success' => true,
                            'contact_name' => $contact['name'],
                            'online_status' => 'online',
                            'chain_valid' => false,
                            'message' => 'Contact is online but chain needs sync'
                        ];
                    } else {
                        $this->updateChainStatus($contact['pubkey'], true);
                        return [
                            'success' => true,
                            'contact_name' => $contact['name'],
                            'online_status' => 'online',
                            'chain_valid' => true,
                            'message' => 'Contact is online and chain is valid'
                        ];
                    }
                } elseif ($response['status'] === 'rejected') {
                    // Contact rejected ping but responded - still online
                    $this->updateContactStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_ONLINE);
                    return [
                        'success' => true,
                        'contact_name' => $contact['name'],
                        'online_status' => 'online',
                        'chain_valid' => null,
                        'message' => 'Contact is online (ping rejected: ' . ($response['reason'] ?? 'unknown') . ')'
                    ];
                }
            }

            // No valid response - contact is offline
            Logger::getInstance()->info("Manual ping: contact offline (no valid response)", [
                'contact_name' => $contact['name'],
                'contact_address' => $contactAddress
            ]);

            $this->updateContactStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_OFFLINE);
            return [
                'success' => true,
                'contact_name' => $contact['name'],
                'online_status' => 'offline',
                'chain_valid' => null,
                'message' => 'Contact is offline (no valid response)'
            ];

        } catch (\Exception $e) {
            // Connection error - contact is offline
            $this->updateContactStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_OFFLINE);
            Logger::getInstance()->warning("Manual contact ping failed", [
                'contact_address' => $contactAddress,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => true,
                'contact_name' => $contact['name'],
                'online_status' => 'offline',
                'chain_valid' => null,
                'message' => 'Contact is offline (connection failed)'
            ];
        }
    }

    /**
     * Save available credit received from a pong response
     *
     * @param string $contactPubkey Contact's public key
     * @param array $response The pong response data
     */
    private function saveAvailableCreditFromPong(string $contactPubkey, array $response): void {
        if (!isset($response['availableCredit']) || $this->contactCreditRepository === null) {
            return;
        }

        try {
            $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);
            $this->contactCreditRepository->upsertAvailableCredit(
                $pubkeyHash,
                (int) $response['availableCredit'],
                $response['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY
            );
        } catch (\Exception $e) {
            Logger::getInstance()->warning("Failed to save available credit from pong", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update contact online status
     *
     * @param string $pubkey Contact public key
     * @param string $status New online status
     */
    private function updateContactStatus(string $pubkey, string $status): void {
        try {
            $this->contactRepository->updateContactFields($pubkey, [
                'online_status' => $status,
                'last_ping_at' => date('Y-m-d H:i:s.u')
            ]);
        } catch (\Exception $e) {
            Logger::getInstance()->error("Failed to update contact online status", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update contact chain validation status
     *
     * @param string $pubkey Contact public key
     * @param bool $valid Whether chain is valid
     */
    private function updateChainStatus(string $pubkey, bool $valid): void {
        try {
            $this->contactRepository->updateContactFields($pubkey, [
                'valid_chain' => $valid ? 1 : 0
            ]);
        } catch (\Exception $e) {
            Logger::getInstance()->error("Failed to update contact chain status", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
