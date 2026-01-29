<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\SecureLogger;
use Eiou\Contracts\ContactStatusServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
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
            // Contact doesn't exist or is pending
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
            // Both have transactions - compare
            $chainValid = ($localPrevTxid === $remotePrevTxid);

            // If chains don't match and sync was requested, trigger sync
            if (!$chainValid && ($request['requestSync'] ?? false)) {
                $this->triggerSync($request['senderAddress'], $senderPubkey);
            }
        }

        // Update the sender's online status since they pinged us
        $this->updateContactOnlineStatus($senderPubkey);

        // Send pong response
        echo $this->contactStatusPayload->buildResponse($request, $localPrevTxid, $chainValid);
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
            SecureLogger::error("Failed to update contact online status on ping receive", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger transaction chain sync with contact
     *
     * @param string $address Contact address
     * @param string $pubkey Contact public key
     */
    private function triggerSync(string $address, string $pubkey): void {
        try {
            // Use existing sync method
            $this->getSyncTrigger()->syncTransactionChain($address, $pubkey);

            SecureLogger::info("Chain sync triggered from incoming ping request", [
                'contact_address' => $address
            ]);
        } catch (\Exception $e) {
            SecureLogger::warning("Chain sync failed during incoming ping", [
                'contact_address' => $address,
                'error' => $e->getMessage()
            ]);
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
            SecureLogger::info("Manual ping initiated", [
                'contact_name' => $contact['name'],
                'contact_address' => $contactAddress
            ]);

            $rawResponse = $this->transportUtility->send($contactAddress, $payload);
            $response = json_decode($rawResponse, true);

            // Update contact based on response
            if ($response && isset($response['status'])) {
                SecureLogger::info("Manual ping response received", [
                    'contact_name' => $contact['name'],
                    'status' => $response['status'],
                    'chain_valid' => $response['chainValid'] ?? 'not provided'
                ]);

                if ($response['status'] === 'pong') {
                    // Contact is online
                    $this->updateContactStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_ONLINE);

                    // Check chain validity
                    $chainValid = $response['chainValid'] ?? true;
                    $remotePrevTxid = $response['prevTxid'] ?? null;

                    // If chains don't match, update status and optionally trigger sync
                    if (!$chainValid || ($prevTxid !== $remotePrevTxid && $prevTxid !== null && $remotePrevTxid !== null)) {
                        $this->updateChainStatus($contact['pubkey'], false);

                        // Trigger sync if enabled
                        if (Constants::CONTACT_STATUS_SYNC_ON_PING) {
                            $this->triggerSync($contactAddress, $contact['pubkey']);
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
            SecureLogger::info("Manual ping: contact offline (no valid response)", [
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
            SecureLogger::warning("Manual contact ping failed", [
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
            SecureLogger::error("Failed to update contact online status", [
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
            SecureLogger::error("Failed to update contact chain status", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
