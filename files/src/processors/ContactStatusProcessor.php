<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Processors;

use Eiou\Core\Application;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Schemas\Payloads\ContactStatusPayload;
use Eiou\Utils\Logger;
use Exception;

/**
 * Contact Status Processor
 *
 * Periodically pings accepted contacts to:
 * - Check if they are online (update online_status)
 * - Compare transaction chain prev_txid for chain validation
 * - Trigger sync if chains don't match and requestSync is enabled
 *
 * This processor respects the CONTACT_STATUS_ENABLED constant.
 * When disabled, it will reset all contacts to 'unknown' status and exit.
 */
class ContactStatusProcessor extends AbstractMessageProcessor {

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
     * @var TransportUtilityService Transport utility service
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
     * @var SyncService|null Sync service for chain validation
     */
    private ?object $syncService = null;

    /**
     * @var ContactCreditRepository|null Contact credit repository for storing available credit
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * @var int Index of current contact being pinged in the cycle
     */
    private int $currentContactIndex = 0;

    /**
     * @var array Cached list of accepted contacts for current cycle
     */
    private array $acceptedContacts = [];

    /**
     * Constructor
     */
    public function __construct() {
        // Get Application instance
        $app = Application::getInstance();

        // Get current user
        $this->currentUser = UserContext::getInstance();

        // Get utility container
        $this->utilityContainer = $app->utilityServices;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();

        // Get repositories from ServiceContainer directly
        $this->contactRepository = $app->services->getContactRepository();
        $this->transactionRepository = $app->services->getTransactionRepository();
        $this->contactCreditRepository = $app->services->getContactCreditRepository();

        // Initialize payload builder
        $this->contactStatusPayload = new ContactStatusPayload($this->currentUser, $this->utilityContainer);

        // Configure adaptive polling (keys must end with _ms to match AdaptivePoller)
        $pollerConfig = [
            'min_interval_ms' => Constants::CONTACT_STATUS_MIN_INTERVAL_MS,
            'max_interval_ms' => Constants::CONTACT_STATUS_MAX_INTERVAL_MS,
            'idle_interval_ms' => Constants::CONTACT_STATUS_IDLE_INTERVAL_MS,
            'adaptive' => Constants::CONTACT_STATUS_ADAPTIVE_POLLING
        ];

        // Call parent constructor
        parent::__construct(
            $pollerConfig,
            '/tmp/contact_status.pid',
            60,  // Log interval in seconds
            30   // Shutdown timeout
        );
    }

    /**
     * Get the processor name for logging
     *
     * @return string
     */
    protected function getProcessorName(): string {
        return 'ContactStatus';
    }

    /**
     * Main processing method - called by the run loop
     *
     * @return int Number of contacts processed
     */
    protected function processMessages(): int {
        // Check if feature is enabled (supports env var override for testing)
        if (!$this->currentUser->getContactStatusEnabled()) {
            // Reset all contacts to unknown status and exit processor
            $this->resetAllContactsToUnknown();
            $this->shouldStop = true;
            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] Contact status polling disabled, stopping processor\n";
            return 0;
        }

        // Refresh contact list if we've completed a cycle or it's empty
        if (empty($this->acceptedContacts) || $this->currentContactIndex >= count($this->acceptedContacts)) {
            $this->acceptedContacts = $this->contactRepository->getAcceptedContacts();
            $this->currentContactIndex = 0;

            if (empty($this->acceptedContacts)) {
                return 0; // No contacts to ping
            }
        }

        // Ping one contact per iteration (to spread load over time)
        $contact = $this->acceptedContacts[$this->currentContactIndex];
        $processed = $this->pingContact($contact);
        $this->currentContactIndex++;

        return $processed ? 1 : 0;
    }

    /**
     * Ping a single contact and update their status
     *
     * @param array $contact Contact data
     * @return bool True if ping was processed
     */
    private function pingContact(array $contact): bool {
        // Security priority: Tor > HTTPS > HTTP
        $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;

        if (!$contactAddress) {
            Logger::getInstance()->warning("Contact has no address for ping", [
                'contact_id' => $contact['contact_id'] ?? 'unknown'
            ]);
            return false;
        }

        try {
            // Get per-currency chain heads for this contact
            $prevTxidsByCurrency = $this->transactionRepository->getPreviousTxidsByCurrency(
                $this->currentUser->getPublicKey(),
                $contact['pubkey']
            );

            // Build ping payload
            $payload = $this->contactStatusPayload->build([
                'receiverAddress' => $contactAddress,
                'prevTxidsByCurrency' => $prevTxidsByCurrency,
                'requestSync' => $this->currentUser->getContactStatusSyncOnPing()
            ]);

            // Send ping
            $rawResponse = $this->transportUtility->send($contactAddress, $payload);
            $response = json_decode($rawResponse, true);

            // Update contact based on response
            if ($response && isset($response['status'])) {
                if ($response['status'] === 'pong') {
                    // Determine online status based on processor health
                    $onlineStatus = $this->determineOnlineStatusFromPong($response);
                    $this->updateContactOnlineStatus($contact['pubkey'], $onlineStatus);

                    // Save available credit from pong response
                    $this->saveAvailableCreditFromPong($contact['pubkey'], $response);

                    // Check chain validity using per-currency comparison
                    $chainValid = $response['chainValid'] ?? true;
                    $remoteChainStatus = $response['chainStatusByCurrency'] ?? [];

                    foreach ($remoteChainStatus as $cur => $curValid) {
                        if (!$curValid) {
                            $chainValid = false;
                            break;
                        }
                    }

                    if (!$chainValid) {
                        $this->updateContactChainStatus($contact['pubkey'], false);

                        // Trigger sync if enabled
                        if ($this->currentUser->getContactStatusSyncOnPing()) {
                            $this->triggerSync($contactAddress, $contact['pubkey']);
                        }
                    } else {
                        $this->updateContactChainStatus($contact['pubkey'], true);
                    }

                    return true;
                } elseif ($response['status'] === 'rejected') {
                    // Contact rejected ping but responded - still online
                    $this->updateContactOnlineStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_ONLINE);
                    return true;
                }
            }

            // No valid response - contact is offline
            $this->updateContactOnlineStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_OFFLINE);
            return true;

        } catch (Exception $e) {
            // Connection error - contact is offline
            $this->updateContactOnlineStatus($contact['pubkey'], Constants::CONTACT_ONLINE_STATUS_OFFLINE);
            Logger::getInstance()->warning("Contact ping failed", [
                'contact_address' => $contactAddress,
                'error' => $e->getMessage()
            ]);
            return true;
        }
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
        } catch (Exception $e) {
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
    private function updateContactOnlineStatus(string $pubkey, string $status): void {
        try {
            $this->contactRepository->updateContactFields($pubkey, [
                'online_status' => $status,
                'last_ping_at' => date('Y-m-d H:i:s.u')
            ]);
        } catch (Exception $e) {
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
    private function updateContactChainStatus(string $pubkey, bool $valid): void {
        try {
            $this->contactRepository->updateContactFields($pubkey, [
                'valid_chain' => $valid ? 1 : 0
            ]);
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to update contact chain status", [
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
            // Lazy load sync service
            if ($this->syncService === null) {
                $this->syncService = Application::getInstance()->services->getSyncService();
            }

            // Use existing sync method
            $this->syncService->syncTransactionChain($address, $pubkey);

            Logger::getInstance()->info("Chain sync triggered from contact status ping", [
                'contact_address' => $address
            ]);
        } catch (Exception $e) {
            Logger::getInstance()->warning("Chain sync failed during contact status ping", [
                'contact_address' => $address,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset all contacts to 'unknown' status
     * Called when the feature is disabled
     */
    private function resetAllContactsToUnknown(): void {
        try {
            $contacts = $this->contactRepository->getAcceptedContacts();
            foreach ($contacts as $contact) {
                $this->contactRepository->updateContactFields($contact['pubkey'], [
                    'online_status' => Constants::CONTACT_ONLINE_STATUS_UNKNOWN,
                    'valid_chain' => null
                ]);
            }
            Logger::getInstance()->info("Reset all contacts to unknown status (feature disabled)");
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to reset contacts to unknown status", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Cleanup on shutdown
     */
    protected function onShutdown(): void {
        // Clear cached contacts
        $this->acceptedContacts = [];
        $this->currentContactIndex = 0;
    }
}
