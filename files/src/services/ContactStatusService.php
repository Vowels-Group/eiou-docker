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
use Eiou\Processors\AbstractMessageProcessor;
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
     * @var \Eiou\Database\ContactCurrencyRepository|null Contact currency repository for multi-currency support
     */
    private ?\Eiou\Database\ContactCurrencyRepository $contactCurrencyRepository = null;

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
     * Set the contact currency repository for multi-currency support
     *
     * @param \Eiou\Database\ContactCurrencyRepository $repo Contact currency repository
     */
    public function setContactCurrencyRepository(\Eiou\Database\ContactCurrencyRepository $repo): void {
        $this->contactCurrencyRepository = $repo;
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
        if (!$this->currentUser->getContactStatusEnabled()) {
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

                        // Create contact_currencies entries for all currencies the remote has
                        $remotePrevTxidsByCurrency = $request['prevTxidsByCurrency'] ?? [];
                        $remoteCurrencies = array_keys($remotePrevTxidsByCurrency);
                        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

                        if ($this->contactCurrencyRepository !== null && !empty($remoteCurrencies)) {
                            foreach ($remoteCurrencies as $cur) {
                                $this->contactCurrencyRepository->insertCurrencyConfig(
                                    $pubkeyHash, $cur, 0, 0, 'pending', 'incoming'
                                );
                            }
                        }

                        Logger::getInstance()->info("Auto-created pending contact from ping (possible wallet restore)", [
                            'sender_address' => $senderAddress,
                            'currencies' => $remoteCurrencies
                        ]);

                        // Trigger sync to restore transaction chain from the remote side
                        $this->triggerSync($senderAddress, $senderPubkey);

                        // After sync, check if transactions were restored — if so, auto-accept
                        // the contact since the transaction history proves the prior relationship
                        $localPrevTxidsByCurrency = $this->transactionRepository->getPreviousTxidsByCurrency(
                            $this->currentUser->getPublicKey(),
                            $senderPubkey
                        );

                        if (!empty($localPrevTxidsByCurrency)) {
                            // Transactions exist — auto-accept the contact
                            $defaultFee = (int) ($this->currentUser->getDefaultFee() * Constants::CONVERSION_FACTORS[Constants::TRANSACTION_DEFAULT_CURRENCY]);
                            $defaultCredit = (int) ($this->currentUser->getDefaultCreditLimit() * Constants::CONVERSION_FACTORS[Constants::TRANSACTION_DEFAULT_CURRENCY]);

                            $this->contactRepository->updateContactStatus($senderPubkey, 'accepted');

                            // Create balances, credit, and currency entries for each restored currency
                            $restoredCurrencies = array_unique(array_merge($remoteCurrencies, array_keys($localPrevTxidsByCurrency)));
                            foreach ($restoredCurrencies as $cur) {
                                if ($this->balanceRepository !== null) {
                                    $this->balanceRepository->insertInitialContactBalances($senderPubkey, $cur);
                                }
                                if ($this->contactCreditRepository !== null) {
                                    try {
                                        $this->contactCreditRepository->createInitialCredit($senderPubkey, $cur);
                                    } catch (\Exception $e) {
                                        // Ignore duplicate key errors
                                    }
                                }
                                if ($this->contactCurrencyRepository !== null) {
                                    // Upsert both directions as accepted with default fee/credit
                                    $this->contactCurrencyRepository->upsertCurrencyConfig(
                                        $pubkeyHash, $cur, $defaultFee, $defaultCredit, 'incoming'
                                    );
                                    $this->contactCurrencyRepository->updateCurrencyStatus($pubkeyHash, $cur, 'accepted', 'incoming');
                                    $this->contactCurrencyRepository->upsertCurrencyConfig(
                                        $pubkeyHash, $cur, $defaultFee, $defaultCredit, 'outgoing'
                                    );
                                    $this->contactCurrencyRepository->updateCurrencyStatus($pubkeyHash, $cur, 'accepted', 'outgoing');
                                }
                            }

                            Logger::getInstance()->info("Auto-accepted restored contact after sync", [
                                'sender_address' => $senderAddress,
                                'currencies' => $restoredCurrencies
                            ]);
                        }

                        // Re-evaluate chain validity after sync
                        $chainValid = true;
                        $chainStatusByCurrency = [];
                        foreach ($remotePrevTxidsByCurrency as $cur => $remoteTxid) {
                            if ($remoteTxid !== null && !$this->transactionRepository->transactionExistsTxid($remoteTxid)) {
                                $chainStatusByCurrency[$cur] = false;
                                $chainValid = false;
                            } else {
                                $chainStatusByCurrency[$cur] = true;
                            }
                        }

                        // Update online status for the newly created contact
                        $this->updateContactOnlineStatus($senderPubkey);

                        // Respond with pong including per-currency chain status
                        [$prRunning, $prTotal] = $this->checkProcessorHealth();
                        echo $this->contactStatusPayload->buildResponse($request, $chainValid, $chainStatusByCurrency, [], $prRunning, $prTotal);
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

        // Per-currency chain comparison: compare chain heads for each currency independently
        $remotePrevTxidsByCurrency = $request['prevTxidsByCurrency'] ?? [];
        $localPrevTxidsByCurrency = $this->transactionRepository->getPreviousTxidsByCurrency(
            $this->currentUser->getPublicKey(),
            $senderPubkey
        );

        $chainValid = true;
        $chainStatusByCurrency = [];

        // Compare per-currency: check all currencies known to either side
        $allCurrencies = array_unique(array_merge(
            array_keys($remotePrevTxidsByCurrency),
            array_keys($localPrevTxidsByCurrency)
        ));

        foreach ($allCurrencies as $cur) {
            $localTxid = $localPrevTxidsByCurrency[$cur] ?? null;
            $remoteTxid = $remotePrevTxidsByCurrency[$cur] ?? null;

            if ($localTxid !== null && $remoteTxid !== null && $localTxid !== $remoteTxid) {
                $chainStatusByCurrency[$cur] = false;
                $chainValid = false;
            } else {
                $chainStatusByCurrency[$cur] = true;
            }
        }

        // Also check for internal chain gaps per currency
        if ($chainValid && $this->transactionChainRepository !== null) {
            foreach ($allCurrencies as $cur) {
                $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                    $this->currentUser->getPublicKey(),
                    $senderPubkey,
                    $cur
                );
                if (!$chainStatus['valid']) {
                    $chainStatusByCurrency[$cur] = false;
                    $chainValid = false;
                }
            }
        }

        // If chains don't match and sync was requested, trigger sync
        if (!$chainValid && ($request['requestSync'] ?? false)) {
            $this->triggerSync($request['senderAddress'] ?? '', $senderPubkey);

            // Re-evaluate after sync: check if the remote's chain heads now exist locally.
            // The ping's remote prevTxids are stale (snapshot from before sync), so comparing
            // local heads vs remote heads can still mismatch if in-flight transactions arrived
            // during the sync window advancing our local chain past the remote's snapshot.
            // Instead, verify we have every txid the remote claimed — if so, we've caught up.
            $chainValid = true;
            $chainStatusByCurrency = [];
            foreach ($allCurrencies as $cur) {
                $remoteTxid = $remotePrevTxidsByCurrency[$cur] ?? null;
                if ($remoteTxid !== null && !$this->transactionRepository->transactionExistsTxid($remoteTxid)) {
                    // Remote claims a txid we don't have — real gap
                    $chainStatusByCurrency[$cur] = false;
                    $chainValid = false;
                } else {
                    $chainStatusByCurrency[$cur] = true;
                }
            }
        }

        // Calculate available credit per currency for the pinging contact
        $availableCreditByCurrency = [];
        if ($this->balanceRepository !== null) {
            try {
                $contactData = $this->contactRepository->getContactByPubkey($senderPubkey);
                $pubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

                // Get all distinct accepted currencies for this contact
                $contactCurrencies = [];
                if ($this->contactCurrencyRepository !== null) {
                    $currencyConfigs = $this->contactCurrencyRepository->getContactCurrencies($pubkeyHash);
                    foreach ($currencyConfigs as $cc) {
                        if (($cc['status'] ?? '') === 'accepted') {
                            $contactCurrencies[$cc['currency']] = true;
                        }
                    }
                    $contactCurrencies = array_keys($contactCurrencies);
                }
                // Fallback to default currency if no accepted currencies found
                if (empty($contactCurrencies)) {
                    $contactCurrencies[] = $contactData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                }

                foreach ($contactCurrencies as $cur) {
                    $sentBalance = $this->balanceRepository->getContactSentBalance($senderPubkey, $cur);
                    $receivedBalance = $this->balanceRepository->getContactReceivedBalance($senderPubkey, $cur);
                    $balance = $sentBalance - $receivedBalance;

                    // Get the credit limit we set for this contact in this currency
                    $creditLimit = 0;
                    if ($this->contactCurrencyRepository !== null) {
                        $creditLimit = $this->contactCurrencyRepository->getCreditLimit($pubkeyHash, $cur);
                    }
                    $availableCreditByCurrency[$cur] = $balance + $creditLimit;
                }
            } catch (\Exception $e) {
                Logger::getInstance()->warning("Failed to calculate available credit for ping response", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Check processor health for pong response
        [$processorsRunning, $processorsTotal] = $this->checkProcessorHealth();

        // Update the sender's online status since they pinged us
        $this->updateContactOnlineStatus($senderPubkey);

        // Send pong response with per-currency credit, processor health, and chain status
        echo $this->contactStatusPayload->buildResponse($request, $chainValid, $chainStatusByCurrency, $availableCreditByCurrency, $processorsRunning, $processorsTotal);
    }

    /**
     * Update contact's online status when they ping us and record last ping time
     *
     * We know the sender's Apache is up (they sent us a ping), but we don't know
     * their processor health — that info only comes in pong responses. So we check
     * our own processor health as a proxy: if the ping arrived through a non-processor
     * path, we can only confirm the node is reachable, not fully operational.
     *
     * @param string $pubkey Contact public key
     */
    private function updateContactOnlineStatus(string $pubkey): void {
        try {
            // We know the contact's Apache is up, but not their processor health.
            // Check our own processors to determine if we'd report partial to them —
            // but for the sender's status, just record that they're reachable.
            // The next ping/pong cycle will set the accurate online/partial status.
            $this->contactRepository->updateContactFields($pubkey, [
                'last_ping_at' => date('Y-m-d H:i:s.u')
            ]);
        } catch (\Exception $e) {
            Logger::getInstance()->error("Failed to update contact last ping time on ping receive", [
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
        if ($contact['status'] !== Constants::CONTACT_STATUS_ACCEPTED) {
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
            // Get per-currency chain heads
            $prevTxidsByCurrency = $this->transactionRepository->getPreviousTxidsByCurrency(
                $this->currentUser->getPublicKey(),
                $contact['pubkey']
            );

            // Build ping payload with per-currency chain heads
            $payload = $this->contactStatusPayload->build([
                'receiverAddress' => $contactAddress,
                'prevTxidsByCurrency' => $prevTxidsByCurrency,
                'requestSync' => $this->currentUser->getContactStatusSyncOnPing()
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
                    // Determine online status based on processor health
                    $onlineStatus = $this->determineOnlineStatusFromPong($response);
                    $this->updateContactStatus($contact['pubkey'], $onlineStatus);

                    // Save available credit from pong response
                    $this->saveAvailableCreditFromPong($contact['pubkey'], $response);

                    // Check chain validity using per-currency comparison from pong
                    $chainValid = $response['chainValid'] ?? true;
                    $remoteChainStatus = $response['chainStatusByCurrency'] ?? [];

                    if (!empty($remoteChainStatus)) {
                        foreach ($remoteChainStatus as $cur => $curValid) {
                            if (!$curValid) {
                                $chainValid = false;
                                break;
                            }
                        }
                    }

                    // Also verify local chain integrity per currency
                    if ($chainValid && $this->transactionChainRepository !== null) {
                        $allCurrencies = array_keys($prevTxidsByCurrency);
                        foreach ($allCurrencies as $cur) {
                            $localChainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                                $this->currentUser->getPublicKey(),
                                $contact['pubkey'],
                                $cur
                            );
                            if (!$localChainStatus['valid']) {
                                $chainValid = false;
                                break;
                            }
                        }
                    }

                    $statusLabel = $onlineStatus === Constants::CONTACT_ONLINE_STATUS_PARTIAL ? 'partial' : 'online';

                    // If chains don't match, update status and optionally trigger sync
                    if (!$chainValid) {
                        $this->updateChainStatus($contact['pubkey'], false);

                        // Trigger sync if enabled
                        $syncResult = null;
                        if ($this->currentUser->getContactStatusSyncOnPing()) {
                            $syncResult = $this->triggerSync($contactAddress, $contact['pubkey']);
                        }

                        // Auto-propose chain drop if sync completed but mutual gaps remain
                        if ($syncResult && !($syncResult['chain_valid'] ?? true) && !empty($syncResult['chain_gaps'] ?? []) && $this->chainDropService && $this->currentUser->getAutoChainDropPropose()) {
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
                            'online_status' => $statusLabel,
                            'chain_valid' => false,
                            'message' => "Contact is {$statusLabel} but chain needs sync"
                        ];
                    } else {
                        $this->updateChainStatus($contact['pubkey'], true);
                        return [
                            'success' => true,
                            'contact_name' => $contact['name'],
                            'online_status' => $statusLabel,
                            'chain_valid' => true,
                            'message' => "Contact is {$statusLabel} and chain is valid"
                        ];
                    }
                } elseif ($response['status'] === Constants::DELIVERY_REJECTED) {
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
        if ($this->contactCreditRepository === null) {
            return;
        }

        $creditByCurrency = $response['availableCreditByCurrency'] ?? [];

        if (empty($creditByCurrency)) {
            return;
        }

        try {
            $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);
            foreach ($creditByCurrency as $currency => $credit) {
                $this->contactCreditRepository->upsertAvailableCredit(
                    $pubkeyHash,
                    (int) $credit,
                    $currency
                );
            }
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

    /**
     * Check health of the 3 core message processors
     *
     * @return array [int running, int total] — counts of running vs expected processors
     */
    private function checkProcessorHealth(): array
    {
        $pidFiles = [
            '/tmp/p2pmessages_lock.pid',
            '/tmp/transactionmessages_lock.pid',
            '/tmp/cleanupmessages_lock.pid',
        ];

        $running = 0;
        foreach ($pidFiles as $pidFile) {
            if (AbstractMessageProcessor::isProcessorRunning($pidFile)) {
                $running++;
            }
        }

        return [$running, count($pidFiles)];
    }

    /**
     * Determine online status based on processor health from pong response
     *
     * @param array $response The pong response data
     * @return string Online status constant (online or partial)
     */
    private function determineOnlineStatusFromPong(array $response): string
    {
        if (!isset($response['processorsRunning']) || !isset($response['processorsTotal'])) {
            return Constants::CONTACT_ONLINE_STATUS_ONLINE;
        }

        $running = (int)$response['processorsRunning'];
        $total = (int)$response['processorsTotal'];

        if ($total > 0 && $running < $total) {
            return Constants::CONTACT_ONLINE_STATUS_PARTIAL;
        }

        return Constants::CONTACT_ONLINE_STATUS_ONLINE;
    }
}
