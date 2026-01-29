<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Proxies;

use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Services\ServiceContainer;
use Eiou\Services\SyncService;

/**
 * SyncServiceProxy - Lazy proxy for SyncService to break circular dependencies
 *
 * This proxy delays the resolution of SyncService until it's actually needed,
 * allowing services that depend on sync functionality to be constructed
 * without creating circular dependency issues.
 *
 * The proxy pattern works by:
 * 1. Accepting a ServiceContainer reference at construction time (no circular dep)
 * 2. Only resolving the actual SyncService when a method is first called
 * 3. Caching the resolved instance for subsequent calls
 *
 * Usage in ServiceContainer:
 * ```php
 * // Instead of injecting SyncService directly (which creates circular deps):
 * $service = new SomeService($this->getSyncService());
 *
 * // Inject the proxy which breaks the cycle:
 * $service = new SomeService(new SyncServiceProxy($this));
 * ```
 *
 * Services that use this proxy should type-hint against SyncTriggerInterface
 * rather than the concrete SyncService class.
 *
 * @see SyncTriggerInterface The interface this proxy implements
 * @see SyncService The actual service this proxy delegates to
 */
class SyncServiceProxy implements SyncTriggerInterface
{
    /**
     * @var ServiceContainer Reference to the service container for lazy resolution
     */
    private ServiceContainer $container;

    /**
     * @var SyncService|null Cached instance of the actual SyncService
     */
    private ?SyncService $instance = null;

    /**
     * Constructor
     *
     * @param ServiceContainer $container The service container for lazy resolution
     */
    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
    }

    /**
     * Get the actual SyncService instance (lazy loaded)
     *
     * The service is only resolved from the container on first access.
     * This breaks the circular dependency cycle because:
     * - Construction of dependent services doesn't trigger SyncService construction
     * - SyncService is only constructed when actually needed
     * - By that time, all other services are already constructed
     *
     * @return SyncService The actual sync service instance
     */
    private function getService(): SyncService
    {
        if ($this->instance === null) {
            $this->instance = $this->container->getSyncService();
        }
        return $this->instance;
    }

    /**
     * Synchronize a transaction chain for a contact.
     *
     * Delegates to SyncService::syncTransactionChain().
     *
     * @param string $contactAddress The contact's address
     * @param string $contactPublicKey The contact's public key
     * @param string|null $expectedTxid Optional expected transaction ID
     * @return array The sync result including any new transactions
     */
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array
    {
        return $this->getService()->syncTransactionChain($contactAddress, $contactPublicKey, $expectedTxid);
    }

    /**
     * Synchronize the balance for a specific contact.
     *
     * Delegates to SyncService::syncContactBalance().
     *
     * @param string $contactPubkey The contact's public key
     * @return array The updated balance information
     */
    public function syncContactBalance(string $contactPubkey): array
    {
        return $this->getService()->syncContactBalance($contactPubkey);
    }

    /**
     * Synchronize a single contact.
     *
     * Delegates to SyncService::syncSingleContact().
     *
     * @param mixed $contactAddress The contact address to sync
     * @param string $echo Echo mode ('SILENT' for no output, 'ECHO' for output)
     * @return bool True if sync was successful
     */
    public function syncSingleContact($contactAddress, $echo = 'SILENT'): bool
    {
        return $this->getService()->syncSingleContact($contactAddress, $echo);
    }

    /**
     * Full sync for a re-added contact.
     *
     * Delegates to SyncService::syncReaddedContact().
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @return array Result with success status and sync details
     */
    public function syncReaddedContact(string $contactAddress, string $contactPublicKey): array
    {
        return $this->getService()->syncReaddedContact($contactAddress, $contactPublicKey);
    }

    /**
     * Handle incoming transaction sync request from a contact
     *
     * @param array $request The sync request data
     * @return void
     */
    public function handleTransactionSyncRequest(array $request): void
    {
        $this->getService()->handleTransactionSyncRequest($request);
    }

    /**
     * Verify a transaction signature using the sender's public key
     *
     * Delegates to SyncService::verifyTransactionSignaturePublic().
     *
     * @param array $tx Transaction data with sender_signature and signature_nonce
     * @return bool True if signature is valid, false otherwise
     */
    public function verifyTransactionSignaturePublic(array $tx): bool
    {
        return $this->getService()->verifyTransactionSignaturePublic($tx);
    }

    /**
     * Check if the actual SyncService has been resolved
     *
     * Useful for debugging and testing to verify lazy loading behavior.
     *
     * @return bool True if the service has been resolved
     */
    public function isResolved(): bool
    {
        return $this->instance !== null;
    }

    /**
     * Force resolution of the SyncService
     *
     * Can be used during bootstrap to eagerly load the service if needed.
     * Generally, lazy loading should be preferred.
     *
     * @return void
     */
    public function resolve(): void
    {
        $this->getService();
    }
}
