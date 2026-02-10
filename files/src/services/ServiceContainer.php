<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Psr\Container\ContainerInterface;
use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\ChainOperationsInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\EventDispatcherInterface;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Contracts\TransactionServiceInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\Rp2pServiceInterface;
use Eiou\Contracts\WalletServiceInterface;
use Eiou\Contracts\MessageServiceInterface;
use Eiou\Contracts\CleanupServiceInterface;
use Eiou\Contracts\SyncServiceInterface;
use Eiou\Contracts\DebugServiceInterface;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Contracts\HeldTransactionServiceInterface;
use Eiou\Contracts\TransactionRecoveryServiceInterface;
use Eiou\Contracts\CliServiceInterface;
use Eiou\Contracts\RateLimiterServiceInterface;
use Eiou\Contracts\LockingServiceInterface;
use Eiou\Contracts\ContactStatusServiceInterface;
use Eiou\Contracts\ApiAuthServiceInterface;
use Eiou\Contracts\ApiKeyServiceInterface;
use Eiou\Contracts\BackupServiceInterface;
use Eiou\Contracts\BalanceServiceInterface;
use Eiou\Contracts\ChainVerificationServiceInterface;
use Eiou\Contracts\ChainDropServiceInterface;
use Eiou\Contracts\TransactionValidationServiceInterface;
use Eiou\Contracts\TransactionProcessingServiceInterface;
use Eiou\Contracts\SendOperationServiceInterface;
use Eiou\Events\EventDispatcher;
use Eiou\Events\SyncEvents;
use Eiou\Services\Proxies\SyncServiceProxy;
use Eiou\Services\ContactManagementService;
use Eiou\Services\ContactSyncService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\DebugRepository;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\MessageDeliveryRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\DeliveryMetricsRepository;
use Eiou\Database\HeldTransactionRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\ChainDropProposalRepository;
use Eiou\Database\RateLimiterRepository;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Cli\CliOutputManager;
use PDO;
use RuntimeException;
use Exception;
use function Eiou\Database\createPDOConnection;

/**
 * Service Container
 *
 * Centralized dependency injection container for managing service instances.
 */

class ServiceContainer implements ContainerInterface {
    /**
     * @var ServiceContainer|null Singleton instance
     */
    private static ?ServiceContainer $instance = null;

    /**
     * @var ContainerInterface|null PHP-DI container for PSR-11 compliance
     */
    private ?ContainerInterface $phpdi = null;

    /**
     * @var array Cached repository instances
     */
    private array $repositories = [];

    /**
     * @var array Cached service instances
     */
    private array $services = [];

    /**
     * @var array Cached Util instances
     */
    private array $utils = [];

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var PDO Database connection
     */
    private PDO|null $pdo;

    /**
     * Private constructor for singleton pattern
     * 
     * @param UserContext $currentUser Current user data (optional)
     * @param PDO $pdo Database connection (optional)
     */
    private function __construct(
        ?UserContext $currentUser,
        ?PDO $pdo
    ) {
        if($currentUser){
            $this->currentUser = $currentUser;
        } else{
            $this->loadCurrentUser();
        }
        if($pdo){
            $this->pdo = $pdo;
        } else{
            $this->loadDatabase();
        }
    }

    /**
     * Get singleton instance
     *
     * @param UserContext|null $currentUser Current user context (optional)
     * @param PDO|null $pdo Database connection (optional)
     * @return ServiceContainer
     */
    public static function getInstance(?UserContext $currentUser = null, ?PDO $pdo = null): ServiceContainer {
        if (self::$instance === null) {
            self::$instance = new self($currentUser, $pdo);
        }
        return self::$instance;
    }

    /**
     * Load current user from global scope
     */
    private function loadCurrentUser(): void {
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Set current user (for testing or manual injection)
     *
     * @param UserContext $user User data
     */
    public function setCurrentUser(UserContext $user): void {
        $this->currentUser = $user;
    }

    /**
     * Get current user
     *
     * @return UserContext Current user data
     */
    public function getCurrentUser(): UserContext {
        return $this->currentUser;
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return void
     */
    public function loadDatabase(): void {
        try {
            $this->pdo = createPDOConnection();
        } catch (RuntimeException $e) {
            // Log the error
            Logger::getInstance()->logException($e, [], 'CRITICAL');
            // Set PDO to null to indicate unavailability
            $this->pdo = null;
        }
    }

    /**
     * Get PDO instance
     *
     * @return PDO Database connection
     * @throws RuntimeException If database connection is not available
     */
    public function getPdo(): PDO {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is not available');
        }
        return $this->pdo;
    }

    /**
     * Get AddressRepository instance
     *
     * @return AddressRepository
     */
    public function getAddressRepository(): AddressRepository {
        if (!isset($this->repositories['AddressRepository'])) {
            $this->repositories['AddressRepository'] = new AddressRepository(
                $this->pdo
            );
        }
        return $this->repositories['AddressRepository'];
    }

    /**
     * Get BalanceRepository instance
     *
     * @return BalanceRepository
     */
    public function getBalanceRepository(): BalanceRepository {
        if (!isset($this->repositories['BalanceRepository'])) {
            $this->repositories['BalanceRepository'] = new BalanceRepository(
                $this->pdo
            );
        }
        return $this->repositories['BalanceRepository'];
    }

    /**
     * Get ContactRepository instance
     *
     * @return ContactRepository
     */
    public function getContactRepository(): ContactRepository {
        if (!isset($this->repositories['ContactRepository'])) {
            $this->repositories['ContactRepository'] = new ContactRepository(
                $this->pdo
            );
        }
        return $this->repositories['ContactRepository'];
    }

    /**
     * Get P2pRepository instance
     *
     * @return P2pRepository
     */
    public function getP2pRepository(): P2pRepository {
        if (!isset($this->repositories['P2pRepository'])) {
            $this->repositories['P2pRepository'] = new P2pRepository(
                $this->pdo
            );
        }
        return $this->repositories['P2pRepository'];
    }

    /**
     * Get Rp2pRepository instance
     *
     * @return Rp2pRepository
     */
    public function getRp2pRepository(): Rp2pRepository {
        if (!isset($this->repositories['Rp2pRepository'])) {
            $this->repositories['Rp2pRepository'] = new Rp2pRepository(
                $this->pdo
            );
        }
        return $this->repositories['Rp2pRepository'];
    }

    /**
     * Get Rp2pCandidateRepository instance
     *
     * @return Rp2pCandidateRepository
     */
    public function getRp2pCandidateRepository(): Rp2pCandidateRepository {
        if (!isset($this->repositories['Rp2pCandidateRepository'])) {
            $this->repositories['Rp2pCandidateRepository'] = new Rp2pCandidateRepository(
                $this->pdo
            );
        }
        return $this->repositories['Rp2pCandidateRepository'];
    }

    /**
     * Get P2pSenderRepository instance
     *
     * @return P2pSenderRepository
     */
    public function getP2pSenderRepository(): P2pSenderRepository {
        if (!isset($this->repositories['P2pSenderRepository'])) {
            $this->repositories['P2pSenderRepository'] = new P2pSenderRepository(
                $this->pdo
            );
        }
        return $this->repositories['P2pSenderRepository'];
    }

    /**
     * Get P2pRelayedContactRepository instance
     *
     * @return P2pRelayedContactRepository
     */
    public function getP2pRelayedContactRepository(): P2pRelayedContactRepository {
        if (!isset($this->repositories['P2pRelayedContactRepository'])) {
            $this->repositories['P2pRelayedContactRepository'] = new P2pRelayedContactRepository(
                $this->pdo
            );
        }
        return $this->repositories['P2pRelayedContactRepository'];
    }

    /**
     * Get TransactionRepository instance
     *
     * @return TransactionRepository
     */
    public function getTransactionRepository(): TransactionRepository {
        if (!isset($this->repositories['TransactionRepository'])) {
            $this->repositories['TransactionRepository'] = new TransactionRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionRepository'];
    }

    /**
     * Get DebugRepository instance
     *
     * @return DebugRepository
     */
    public function getDebugRepository(): DebugRepository {
        if (!isset($this->repositories['DebugRepository'])) {
            $this->repositories['DebugRepository'] = new DebugRepository(
                $this->pdo
            );
        }
        return $this->repositories['DebugRepository'];
    }

    /**
     * Get ApiKeyRepository instance
     *
     * @return ApiKeyRepository
     */
    public function getApiKeyRepository(): ApiKeyRepository {
        if (!isset($this->repositories['ApiKeyRepository'])) {
            $this->repositories['ApiKeyRepository'] = new ApiKeyRepository(
                $this->pdo
            );
        }
        return $this->repositories['ApiKeyRepository'];
    }
    
    /** Get MessageDeliveryRepository instance
     *
     * @return MessageDeliveryRepository
     */
    public function getMessageDeliveryRepository(): MessageDeliveryRepository {
        if (!isset($this->repositories['MessageDeliveryRepository'])) {
            $this->repositories['MessageDeliveryRepository'] = new MessageDeliveryRepository(
                $this->pdo
            );
        }
        return $this->repositories['MessageDeliveryRepository'];
    }

    /**
     * Get DeadLetterQueueRepository instance
     *
     * @return DeadLetterQueueRepository
     */
    public function getDeadLetterQueueRepository(): DeadLetterQueueRepository {
        if (!isset($this->repositories['DeadLetterQueueRepository'])) {
            $this->repositories['DeadLetterQueueRepository'] = new DeadLetterQueueRepository(
                $this->pdo
            );
        }
        return $this->repositories['DeadLetterQueueRepository'];
    }

    /**
     * Get DeliveryMetricsRepository instance
     *
     * @return DeliveryMetricsRepository
     */
    public function getDeliveryMetricsRepository(): DeliveryMetricsRepository {
        if (!isset($this->repositories['DeliveryMetricsRepository'])) {
            $this->repositories['DeliveryMetricsRepository'] = new DeliveryMetricsRepository(
                $this->pdo
            );
        }
        return $this->repositories['DeliveryMetricsRepository'];
    }

    /**
     * Get HeldTransactionRepository instance
     *
     * @return HeldTransactionRepository
     */
    public function getHeldTransactionRepository(): HeldTransactionRepository {
        if (!isset($this->repositories['HeldTransactionRepository'])) {
            $this->repositories['HeldTransactionRepository'] = new HeldTransactionRepository(
                $this->pdo
            );
        }
        return $this->repositories['HeldTransactionRepository'];
    }

    /**
     * Get ChainDropProposalRepository instance
     *
     * @return ChainDropProposalRepository
     */
    public function getChainDropProposalRepository(): ChainDropProposalRepository {
        if (!isset($this->repositories['ChainDropProposalRepository'])) {
            $this->repositories['ChainDropProposalRepository'] = new ChainDropProposalRepository(
                $this->pdo
            );
        }
        return $this->repositories['ChainDropProposalRepository'];
    }

    /**
     * Get RateLimiterRepository instance
     *
     * @return RateLimiterRepository
     */
    public function getRateLimiterRepository(): RateLimiterRepository {
        if (!isset($this->repositories['RateLimiterRepository'])) {
            $this->repositories['RateLimiterRepository'] = new RateLimiterRepository(
                $this->pdo
            );
        }
        return $this->repositories['RateLimiterRepository'];
    }

    /**
     * Get TransactionStatisticsRepository instance
     *
     * @return TransactionStatisticsRepository
     */
    public function getTransactionStatisticsRepository(): TransactionStatisticsRepository {
        if (!isset($this->repositories['TransactionStatisticsRepository'])) {
            $this->repositories['TransactionStatisticsRepository'] = new TransactionStatisticsRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionStatisticsRepository'];
    }

    /**
     * Get TransactionChainRepository instance
     *
     * @return TransactionChainRepository
     */
    public function getTransactionChainRepository(): TransactionChainRepository {
        if (!isset($this->repositories['TransactionChainRepository'])) {
            $this->repositories['TransactionChainRepository'] = new TransactionChainRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionChainRepository'];
    }

    /**
     * Get TransactionRecoveryRepository instance
     *
     * @return TransactionRecoveryRepository
     */
    public function getTransactionRecoveryRepository(): TransactionRecoveryRepository {
        if (!isset($this->repositories['TransactionRecoveryRepository'])) {
            $this->repositories['TransactionRecoveryRepository'] = new TransactionRecoveryRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionRecoveryRepository'];
    }

    /**
     * Get TransactionContactRepository instance
     *
     * @return TransactionContactRepository
     */
    public function getTransactionContactRepository(): TransactionContactRepository {
        if (!isset($this->repositories['TransactionContactRepository'])) {
            $this->repositories['TransactionContactRepository'] = new TransactionContactRepository(
                $this->pdo
            );
        }
        return $this->repositories['TransactionContactRepository'];
    }

    /**
     * Get ContactManagementService instance
     * @return ContactManagementServiceInterface
     */
    public function getContactManagementService(): ContactManagementServiceInterface {
        if (!isset($this->services['ContactManagementService'])) {
            $this->services['ContactManagementService'] = new ContactManagementService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->currentUser
            );
        }
        return $this->services['ContactManagementService'];
    }

    /**
     * Get ContactSyncService instance
     * @return ContactSyncServiceInterface
     */
    public function getContactSyncService(): ContactSyncServiceInterface {
        if (!isset($this->services['ContactSyncService'])) {
            $this->services['ContactSyncService'] = new ContactSyncService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionContactRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['ContactSyncService'];
    }

    /**
     * Get ContactService instance (facade)
     *
     * Integrates ContactManagementService and ContactSyncService for contact operations.
     *
     * @return ContactServiceInterface
     */
    public function getContactService(): ContactServiceInterface {
        if (!isset($this->services['ContactService'])) {
            $this->services['ContactService'] = new ContactService(
                $this->getContactManagementService(),
                $this->getContactSyncService()
            );
        }
        return $this->services['ContactService'];
    }

    /**
     * Get TransactionService instance
     *
     * Integrates MessageDeliveryService for reliable transaction message delivery
     * with retry logic and dead letter queue support.
     *
     * @return TransactionServiceInterface
     */
    public function getTransactionService(): TransactionServiceInterface {
        if (!isset($this->services['TransactionService'])) {
            $this->services['TransactionService'] = new TransactionService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getTransactionRecoveryRepository(),
                $this->getTransactionContactRepository(),
                $this->getTransactionStatisticsRepository(),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getHeldTransactionService() // Set HeldTransactionService for handling invalid_previous_txid rejections
            );
            // Inject LockingService for distributed contact send locks
            $this->services['TransactionService']->setLockingService($this->getLockingService());
        }
        return $this->services['TransactionService'];
    }

    /**
     * Get P2pService instance
     *
     * Integrates MessageDeliveryService for reliable P2P message delivery
     * with retry logic and dead letter queue support.
     *
     * @return P2pServiceInterface
     */
    public function getP2pService(): P2pServiceInterface {
        if (!isset($this->services['P2pService'])) {
            $this->services['P2pService'] = new P2pService(
                $this->getContactService(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getP2pSenderRepository()
            );
        }
        return $this->services['P2pService'];
    }

    /**
     * Get Rp2pService instance
     *
     * Integrates MessageDeliveryService for reliable RP2P message delivery
     * with retry logic and dead letter queue support.
     *
     * @return Rp2pService
     */
    public function getRp2pService(): Rp2pServiceInterface {
        if (!isset($this->services['Rp2pService'])) {
            $this->services['Rp2pService'] = new Rp2pService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getRp2pCandidateRepository(),
                $this->getP2pSenderRepository()
            );
        }
        return $this->services['Rp2pService'];
    }

    /**
     * Get WalletService instance
     *
     * @return WalletService
     */
    public function getWalletService(): WalletServiceInterface {
        if (!isset($this->services['WalletService'])) {
            $this->services['WalletService'] = new WalletService(
                $this->currentUser
            );
        }
        return $this->services['WalletService'];
    }

    /**
     * Get MessageService instance
     *
     * @return MessageService
     */
    public function getMessageService(): MessageServiceInterface {
        if (!isset($this->services['MessageService'])) {
            $this->services['MessageService'] = new MessageService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getP2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionContactRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['MessageService'];
    }

    /**
     * Get CleanupService instance
     *
     * @return CleanupService
     */
    public function getCleanupService(): CleanupServiceInterface {
        if (!isset($this->services['CleanupService'])) {
            $this->services['CleanupService'] = new CleanupService(
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['CleanupService'];
    }

    /**
     * Get SyncService instance
     *
     * @return SyncServiceInterface
     */
    public function getSyncService(): SyncServiceInterface {
        if (!isset($this->services['SyncService'])) {
            $this->services['SyncService'] = new SyncService(
                $this->getContactRepository(),
                $this->getAddressRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getTransactionContactRepository(),
                $this->getBalanceRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['SyncService'];
    }

    /**
     * Get DebugService instance
     *
     * @return DebugService
     */
    public function getDebugService(): DebugServiceInterface {
        if (!isset($this->services['DebugService'])) {
            $this->services['DebugService'] = new DebugService(
                $this->getDebugRepository(),
                $this->currentUser
            );
        }
        return $this->services['DebugService'];
    }

    /**
     * Get MessageDeliveryService instance
     *
     * @return MessageDeliveryService
     */
    public function getMessageDeliveryService(): MessageDeliveryServiceInterface {
        if (!isset($this->services['MessageDeliveryService'])) {
            $this->services['MessageDeliveryService'] = new MessageDeliveryService(
                $this->getMessageDeliveryRepository(),
                $this->getDeadLetterQueueRepository(),
                $this->getDeliveryMetricsRepository(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->currentUser
            );
        }
        return $this->services['MessageDeliveryService'];
    }

    /**
     * Get HeldTransactionService instance
     *
     * @return HeldTransactionService
     */
    public function getHeldTransactionService(): HeldTransactionServiceInterface {
        if (!isset($this->services['HeldTransactionService'])) {
            $this->services['HeldTransactionService'] = new HeldTransactionService(
                $this->getHeldTransactionRepository(),
                $this->getTransactionRepository(),
                $this->getTransactionChainRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['HeldTransactionService'];
    }

    /**
     * Get TransactionRecoveryService instance
     *
     * @return TransactionRecoveryService
     */
    public function getTransactionRecoveryService(): TransactionRecoveryServiceInterface {
        if (!isset($this->services['TransactionRecoveryService'])) {
            $this->services['TransactionRecoveryService'] = new TransactionRecoveryService(
                $this->getTransactionRepository(),
                $this->getTransactionRecoveryRepository()
            );
        }
        return $this->services['TransactionRecoveryService'];
    }

    /**
     * Get CliService instance
     *
     * @return CliService
     */
    public function getCliService(): CliServiceInterface {
        if (!isset($this->services['CliService'])) {
            $this->services['CliService'] = new CliService(
                $this->getContactRepository(),
                $this->getBalanceRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['CliService'];
    }

    /**
     * Get RateLimiterService instance
     *
     * @return RateLimiterService
     */
    public function getRateLimiterService(): RateLimiterServiceInterface {
        if (!isset($this->services['RateLimiterService'])) {
            $this->services['RateLimiterService'] = new RateLimiterService(
                $this->getRateLimiterRepository()
            );
        }
        return $this->services['RateLimiterService'];
    }

    /**
     * Get LockingService instance
     *
     * Provides database-backed distributed locking for concurrent operations.
     *
     * @return LockingServiceInterface
     */
    public function getLockingService(): LockingServiceInterface {
        if (!isset($this->services['LockingService'])) {
            $this->services['LockingService'] = new DatabaseLockingService(
                $this->getPdo()
            );
        }
        return $this->services['LockingService'];
    }

    /**
     * Get ContactStatusService instance
     *
     * Handles incoming ping requests and chain validation.
     *
     * @return ContactStatusService
     */
    public function getContactStatusService(): ContactStatusServiceInterface {
        if (!isset($this->services['ContactStatusService'])) {
            $this->services['ContactStatusService'] = new ContactStatusService(
                $this->getContactRepository(),
                $this->getTransactionRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['ContactStatusService'];
    }

    /**
     * Get ApiAuthService instance
     *
     * Provides API authentication using HMAC signature verification.
     *
     * @return ApiAuthService
     */
    public function getApiAuthService(): ApiAuthServiceInterface {
        if (!isset($this->services['ApiAuthService'])) {
            $this->services['ApiAuthService'] = new ApiAuthService(
                $this->getApiKeyRepository(),
                $this->getLogger()
            );
        }
        return $this->services['ApiAuthService'];
    }

    /**
     * Get ApiKeyService instance
     *
     * Provides CLI management for API keys.
     *
     * @param CliOutputManager $output CLI output manager for formatting
     * @return ApiKeyService
     */
    public function getApiKeyService(CliOutputManager $output): ApiKeyServiceInterface {
        if (!isset($this->services['ApiKeyService'])) {
            $this->services['ApiKeyService'] = new ApiKeyService(
                $this->getApiKeyRepository(),
                $output
            );
        }
        return $this->services['ApiKeyService'];
    }

    /**
     * Get BackupService instance
     *
     * Provides database backup and restore functionality with encryption.
     *
     * @return BackupServiceInterface
     */
    public function getBackupService(): BackupServiceInterface {
        if (!isset($this->services['BackupService'])) {
            $this->services['BackupService'] = new BackupService(
                $this->currentUser,
                $this->pdo
            );
        }
        return $this->services['BackupService'];
    }

    /**
     * Get BalanceService instance
     *
     * Handles balance-related operations including user balance retrieval,
     * contact balance calculations, and contact information conversion.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return BalanceServiceInterface
     */
    public function getBalanceService(): BalanceServiceInterface {
        if (!isset($this->services['BalanceService'])) {
            $this->services['BalanceService'] = new BalanceService(
                $this->getBalanceRepository(),
                $this->getTransactionContactRepository(),
                $this->getAddressRepository(),
                $this->getUtilityContainer()->getCurrencyUtility(),
                $this->currentUser
            );
        }
        return $this->services['BalanceService'];
    }

    /**
     * Get ChainVerificationService instance
     *
     * Handles verification of transaction chain integrity before new transactions
     * are created. Coordinates with SyncService to repair chains when gaps exist.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return ChainVerificationServiceInterface
     */
    public function getChainVerificationService(): ChainVerificationServiceInterface {
        if (!isset($this->services['ChainVerificationService'])) {
            $this->services['ChainVerificationService'] = new ChainVerificationService(
                $this->getTransactionChainRepository(),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['ChainVerificationService'];
    }

    /**
     * Get TransactionValidationService instance
     *
     * Handles all validation logic for transaction processing including
     * previous transaction ID validation, funds checking, and full
     * transaction possibility validation with proactive sync.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return TransactionValidationServiceInterface
     */
    public function getTransactionValidationService(): TransactionValidationServiceInterface {
        if (!isset($this->services['TransactionValidationService'])) {
            $this->services['TransactionValidationService'] = new TransactionValidationService(
                $this->getTransactionRepository(),
                $this->getContactService(),
                $this->getUtilityContainer()->getValidationUtility(),
                $this->getInputValidator(),
                $this->getTransactionPayload(),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['TransactionValidationService'];
    }

    /**
     * Get TransactionProcessingService instance
     *
     * Handles core transaction processing logic including incoming transactions,
     * pending transactions, and P2P transactions.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return TransactionProcessingServiceInterface
     */
    public function getTransactionProcessingService(): TransactionProcessingServiceInterface {
        if (!isset($this->services['TransactionProcessingService'])) {
            $this->services['TransactionProcessingService'] = new TransactionProcessingService(
                $this->getTransactionRepository(),
                $this->getTransactionRecoveryRepository(),
                $this->getTransactionChainRepository(),
                $this->getP2pRepository(),
                $this->getRp2pRepository(),
                $this->getBalanceRepository(),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService()
            );
        }
        return $this->services['TransactionProcessingService'];
    }

    /**
     * Get SendOperationService instance
     *
     * Handles high-level send operation orchestration for eIOU transactions.
     * Manages direct sends, P2P routing, transaction message delivery,
     * and distributed locking for concurrent send prevention.
     * Extracted from TransactionService as part of God Class refactoring.
     *
     * @return SendOperationServiceInterface
     */
    public function getSendOperationService(): SendOperationServiceInterface {
        if (!isset($this->services['SendOperationService'])) {
            $this->services['SendOperationService'] = new SendOperationService(
                $this->getTransactionRepository(),
                $this->getAddressRepository(),
                $this->getP2pRepository(),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->getInputValidator(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService(),
                $this->getLockingService()
            );
        }
        return $this->services['SendOperationService'];
    }

    /**
     * Get ChainOperationsService instance
     *
     * Provides centralized chain verification and repair operations.
     * This service encapsulates chain-related operations that multiple
     * services need, including integrity verification, previous txid
     * resolution, and chain repair coordination.
     *
     * Note: The sync dependency is wired via setter injection in
     * wireCircularDependencies() to avoid circular dependency issues.
     *
     * @return ChainOperationsInterface
     */
    public function getChainOperationsService(): ChainOperationsInterface {
        if (!isset($this->services['ChainOperationsService'])) {
            $this->services['ChainOperationsService'] = new ChainOperationsService(
                $this->getTransactionChainRepository(),
                $this->getTransactionRepository(),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['ChainOperationsService'];
    }

    /**
     * Get ChainDropService instance
     *
     * Manages mutual agreement protocol for dropping missing transactions
     * from the chain between two contacts.
     *
     * Note: MessageService dependency is wired via setter injection in
     * wireCircularDependencies() to avoid circular dependency.
     *
     * @return ChainDropServiceInterface
     */
    public function getChainDropService(): ChainDropServiceInterface {
        if (!isset($this->services['ChainDropService'])) {
            $this->services['ChainDropService'] = new ChainDropService(
                $this->getChainDropProposalRepository(),
                $this->getTransactionChainRepository(),
                $this->getTransactionRepository(),
                $this->getContactRepository(),
                $this->getUtilityContainer(),
                $this->currentUser
            );
        }
        return $this->services['ChainDropService'];
    }

    /**
     * Get SyncServiceProxy instance
     *
     * Returns a lazy-loading proxy for SyncService that implements SyncTriggerInterface.
     * This proxy enables services to depend on sync functionality without creating
     * circular dependencies - the actual SyncService is only resolved when first needed.
     *
     * Services that need loose coupling to sync operations should use this proxy
     * instead of direct SyncService injection. Type-hint against SyncTriggerInterface.
     *
     * @return SyncTriggerInterface
     */
    public function getSyncServiceProxy(): SyncTriggerInterface {
        if (!isset($this->services['SyncServiceProxy'])) {
            $this->services['SyncServiceProxy'] = new SyncServiceProxy($this);
        }
        return $this->services['SyncServiceProxy'];
    }

    /**
     * Get EventDispatcher singleton instance
     *
     * Returns the central event dispatcher for event-driven communication.
     * This enables loose coupling between services by allowing them to
     * communicate via events instead of direct dependencies.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface {
        if (!isset($this->services['EventDispatcher'])) {
            $this->services['EventDispatcher'] = EventDispatcher::getInstance();
        }
        return $this->services['EventDispatcher'];
    }

    /**
     * Get UtilityServiceContainer instance
     *
     *
     * @return UtilityServiceContainer
     */
    public function getUtilityContainer(): UtilityServiceContainer {
        if (!isset($this->services['UtilityServiceContainer'])) {
            $this->services['UtilityServiceContainer'] = new UtilityServiceContainer($this);
        }
        return $this->services['UtilityServiceContainer'];
    }

    /**
     * Get InputValidator instance
     *
     *
     * @return InputValidator
     */
    public function getInputValidator(): InputValidator {
        if (!isset($this->utils['InputValidator'])) {
            $this->utils['InputValidator'] = new InputValidator();
        }
        return $this->utils['InputValidator'];
    }

    /**
     * Get logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger {
        if (!isset($this->utils['Logger'])) {
            Logger::init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
            $this->utils['Logger'] = Logger::getInstance();
        }
        return $this->utils['Logger'];
    }
    /**
     * Get Security instance
     *
     *
     * @return Security
     */
    public function getSecurity(): Security {
        if (!isset($this->utils['Security'])) {
            $this->utils['Security'] = new Security();
        }
        return $this->utils['Security'];
    }

    /**
     * Get TransactionPayload instance
     *
     * @return TransactionPayload
     */
    public function getTransactionPayload(): TransactionPayload {
        if (!isset($this->utils['TransactionPayload'])) {
            $this->utils['TransactionPayload'] = new TransactionPayload($this->currentUser, $this->getUtilityContainer());
        }
        return $this->utils['TransactionPayload'];
    }

    /**
     * Clear all cached services (useful for testing)
     */
    public function clearServices(): void {
        $this->services = [];
    }

    /**
     * Clear all cached utils (useful for testing)
     */
    public function clearUtils(): void {
        $this->utils = [];
    }

    /**
     * Wire circular dependencies between services using setter injection
     *
     * This method should be called after all services that have circular dependencies
     * are initialized. It uses setter injection to break circular dependency cycles
     * while maintaining the lazy loading pattern.
     *
     * Dependencies handled (all SyncService deps now use SyncTriggerInterface via proxy):
     * - TransactionService --> SyncTriggerInterface (via proxy)
     * - ContactService --> SyncTriggerInterface (via proxy)
     * - ContactStatusService --> SyncTriggerInterface (via proxy), RateLimiterService
     * - MessageService --> SyncTriggerInterface (via proxy), ChainDropService
     * - ChainVerificationService --> SyncTriggerInterface (via proxy)
     * - TransactionValidationService --> SyncTriggerInterface (via proxy), TransactionService
     * - TransactionProcessingService --> SyncTriggerInterface (via proxy), P2pService, HeldTransactionService
     * - SendOperationService --> ContactService, P2pService, SyncTriggerInterface (via proxy), TransactionService
     * - Rp2pService --> SendOperationService (via P2pTransactionSenderInterface)
     * - HeldTransactionService --> SyncService (via EventDispatcher events)
     * - SyncService --> HeldTransactionService (one-way setter injection)
     * - TransactionService --> P2pService, ContactService
     * - ChainOperationsService --> SyncService (for chain repair coordination)
     *
     * Future improvements (to reduce circular dependencies):
     * - Services can use SyncServiceProxy instead of direct SyncService injection
     *   to avoid construction-time circular dependencies
     * - Services can use EventDispatcher for loose coupling between services
     *   (e.g., dispatching SyncEvents instead of direct sync calls)
     * - ChainOperationsService can be injected into services that need chain
     *   verification/repair instead of them depending directly on SyncService
     *
     * Note: Repositories are now passed via constructor injection, not setter injection.
     */
    public function wireCircularDependencies(): void {
        // =========================================================================
        // Core sync-related dependencies
        // Now using SyncTriggerInterface via proxy for loose coupling
        // =========================================================================

        // Wire TransactionService -> SyncTriggerInterface (via proxy)
        // Reason: TransactionService needs to trigger sync before sending (fallback path)
        // Note: Uses SyncTriggerInterface for loose coupling - breaks circular dependency
        if (isset($this->services['TransactionService'])) {
            $this->services['TransactionService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // Wire SyncService <-> HeldTransactionService (one-way now - event-driven in reverse)
        // Reason: SyncService creates held transactions and notifies HeldTransactionService via events
        // Note: HeldTransactionService no longer has setSyncService() - it uses EventDispatcher
        //       to listen for SyncEvents::SYNC_COMPLETED instead of direct dependency
        if (isset($this->services['SyncService']) && isset($this->services['HeldTransactionService'])) {
            $this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
        }

        // =========================================================================
        // Contact and message service dependencies
        // These now use SyncTriggerInterface via proxy for loose coupling
        // =========================================================================

        // Wire ContactManagementService with ContactSyncService and SyncTriggerInterface
        // Reason: ContactManagementService needs to trigger sync operations after contact changes
        //         and recalculate balances after accepting contacts (wallet restore scenario)
        if (isset($this->services['ContactManagementService'])) {
            if (isset($this->services['ContactSyncService'])) {
                $this->services['ContactManagementService']->setContactSyncService($this->services['ContactSyncService']);
            }
            $this->services['ContactManagementService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // Wire ContactSyncService -> SyncTriggerInterface (via proxy)
        // Reason: ContactSyncService needs to trigger sync operations for contacts
        if (isset($this->services['ContactSyncService'])) {
            $this->services['ContactSyncService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // Wire ContactSyncService -> MessageDeliveryService
        // Reason: ContactSyncService needs reliable message delivery for contact operations
        if (isset($this->services['ContactSyncService']) && isset($this->services['MessageDeliveryService'])) {
            $this->services['ContactSyncService']->setMessageDeliveryService($this->services['MessageDeliveryService']);
        }

        // Wire ContactService -> SyncTriggerInterface (via proxy) - backwards compatibility
        // Reason: ContactService facade delegates to underlying services but may need sync trigger
        // Note: Uses SyncTriggerInterface for loose coupling - breaks circular dependency
        if (isset($this->services['ContactService'])) {
            $this->services['ContactService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // Wire ContactStatusService -> SyncTriggerInterface (via proxy), RateLimiterService, TransactionChainRepository, ChainDropService, AddressRepository
        // Reason: ContactStatusService validates chains, needs rate limiting, detects internal gaps, auto-proposes chain drops,
        //         and auto-creates pending contacts from pings (wallet restore scenario)
        // Note: Uses SyncTriggerInterface for loose coupling
        if (isset($this->services['ContactStatusService'])) {
            $this->services['ContactStatusService']->setSyncTrigger($this->getSyncServiceProxy());
            $this->services['ContactStatusService']->setTransactionChainRepository($this->getTransactionChainRepository());
            $this->services['ContactStatusService']->setAddressRepository($this->getAddressRepository());
            if (isset($this->services['RateLimiterService'])) {
                $this->services['ContactStatusService']->setRateLimiterService($this->services['RateLimiterService']);
            }
            if (isset($this->services['ChainDropService'])) {
                $this->services['ContactStatusService']->setChainDropService($this->services['ChainDropService']);
            }
        }

        // Wire MessageService -> SyncTriggerInterface (via proxy), ChainDropService
        // Reason: MessageService handles incoming sync requests and chain drop messages
        // Note: Uses SyncTriggerInterface for loose coupling - breaks circular dependency
        if (isset($this->services['MessageService'])) {
            $this->services['MessageService']->setSyncTrigger($this->getSyncServiceProxy());
            if (isset($this->services['ChainDropService'])) {
                $this->services['MessageService']->setChainDropService($this->services['ChainDropService']);
            }
        }

        // Wire ChainDropService -> SyncTriggerInterface (via proxy)
        // Reason: ChainDropService needs to recalculate balances after dropping transactions
        if (isset($this->services['ChainDropService'])) {
            $this->services['ChainDropService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // =========================================================================
        // Transaction-related circular dependencies
        // =========================================================================

        // Wire Rp2pService -> SendOperationService (via P2pTransactionSenderInterface)
        // Reason: RP2P operations complete transaction flows by calling sendP2pEiou()
        // Note: Uses P2pTransactionSenderInterface to break circular dependency with TransactionService
        if (isset($this->services['Rp2pService']) && isset($this->services['SendOperationService'])) {
            $this->services['Rp2pService']->setP2pTransactionSender($this->services['SendOperationService']);
        }

        // Wire TransactionService -> P2pService, ContactService, and new refactored services
        if (isset($this->services['TransactionService'])) {
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['ContactService'])) {
                $this->services['TransactionService']->setContactService($this->services['ContactService']);
            }
            // Wire the 5 new refactored services to TransactionService facade
            if (isset($this->services['BalanceService'])) {
                $this->services['TransactionService']->setBalanceService($this->services['BalanceService']);
            }
            if (isset($this->services['ChainVerificationService'])) {
                $this->services['TransactionService']->setChainVerificationService($this->services['ChainVerificationService']);
            }
            if (isset($this->services['TransactionValidationService'])) {
                $this->services['TransactionService']->setTransactionValidationService($this->services['TransactionValidationService']);
            }
            if (isset($this->services['TransactionProcessingService'])) {
                $this->services['TransactionService']->setTransactionProcessingService($this->services['TransactionProcessingService']);
            }
            if (isset($this->services['SendOperationService'])) {
                $this->services['TransactionService']->setSendOperationService($this->services['SendOperationService']);
            }
        }

        // =========================================================================
        // Refactored service dependencies (from God Class refactoring)
        // These services now use SyncTriggerInterface via proxy for loose coupling
        // =========================================================================

        // Wire ChainVerificationService -> SyncTriggerInterface (via proxy)
        // Reason: ChainVerificationService repairs chains via sync
        if (isset($this->services['ChainVerificationService'])) {
            $this->services['ChainVerificationService']->setSyncTrigger($this->getSyncServiceProxy());
        }

        // Wire TransactionValidationService -> SyncTriggerInterface (via proxy), TransactionService
        // Reason: Proactive sync before validation, transaction lookup for validation
        if (isset($this->services['TransactionValidationService'])) {
            $this->services['TransactionValidationService']->setSyncTrigger($this->getSyncServiceProxy());
            if (isset($this->services['TransactionService'])) {
                $this->services['TransactionValidationService']->setTransactionService($this->services['TransactionService']);
            }
        }

        // Wire TransactionProcessingService -> SyncTriggerInterface (via proxy), P2pService, HeldTransactionService
        // Reason: Chain sync after conflicts, P2P processing, held transaction handling
        if (isset($this->services['TransactionProcessingService'])) {
            $this->services['TransactionProcessingService']->setSyncTrigger($this->getSyncServiceProxy());
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionProcessingService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['HeldTransactionService'])) {
                $this->services['TransactionProcessingService']->setHeldTransactionService($this->services['HeldTransactionService']);
            }
        }

        // Wire SendOperationService -> ContactService, P2pService, SyncTriggerInterface (via proxy), TransactionService
        // Reason: Contact lookup, P2P routing, pre-send sync, transaction creation
        if (isset($this->services['SendOperationService'])) {
            if (isset($this->services['ContactService'])) {
                $this->services['SendOperationService']->setContactService($this->services['ContactService']);
            }
            if (isset($this->services['P2pService'])) {
                $this->services['SendOperationService']->setP2pService($this->services['P2pService']);
            }
            $this->services['SendOperationService']->setSyncTrigger($this->getSyncServiceProxy());
            if (isset($this->services['TransactionService'])) {
                $this->services['SendOperationService']->setTransactionService($this->services['TransactionService']);
            }
            // Set TransactionChainRepository for chain verification
            $this->services['SendOperationService']->setTransactionChainRepository($this->getTransactionChainRepository());
            // Set ChainDropService for auto-proposing chain drops when sync fails
            if (isset($this->services['ChainDropService'])) {
                $this->services['SendOperationService']->setChainDropService($this->services['ChainDropService']);
            }
        }

        // =========================================================================
        // New dependency injection pattern services
        // These use setter injection to coordinate with SyncService
        // =========================================================================

        // Wire ChainOperationsService -> SyncService
        // Reason: ChainOperationsService needs SyncService for chain repair via repairChainIfNeeded()
        // Note: This is the new centralized chain operations service that can be used by other
        //       services instead of them directly depending on SyncService for chain repair
        if (isset($this->services['ChainOperationsService']) && isset($this->services['SyncService'])) {
            $this->services['ChainOperationsService']->setSyncService($this->services['SyncService']);
        }

        // Wire SyncService -> BackupService
        // Reason: SyncService checks local/remote backups for missing transactions during sync exchange
        if (isset($this->services['SyncService'])) {
            $this->services['SyncService']->setBackupService($this->getBackupService());
        }

        // Wire ChainDropService -> BackupService
        // Reason: ChainDropService checks backups as fallback safety net when sync-level recovery missed
        if (isset($this->services['ChainDropService'])) {
            $this->services['ChainDropService']->setBackupService($this->getBackupService());
        }

        // Wire CleanupService -> ChainDropService
        // Reason: CleanupService needs to expire stale chain drop proposals
        if (isset($this->services['CleanupService']) && isset($this->services['ChainDropService'])) {
            $this->services['CleanupService']->setChainDropService($this->services['ChainDropService']);
        }

        // Wire CleanupService -> Rp2pCandidateRepository, Rp2pService, P2pSenderRepository, P2pRelayedContactRepository
        // Reason: CleanupService needs to select best-fee route before expiring P2P with candidates
        //         and clean up old P2P sender and relayed contact records
        if (isset($this->services['CleanupService'])) {
            $this->services['CleanupService']->setRp2pCandidateRepository($this->getRp2pCandidateRepository());
            $this->services['CleanupService']->setP2pSenderRepository($this->getP2pSenderRepository());
            $this->services['CleanupService']->setP2pRelayedContactRepository($this->getP2pRelayedContactRepository());
            if (isset($this->services['Rp2pService'])) {
                $this->services['CleanupService']->setRp2pService($this->services['Rp2pService']);
            }
        }

        // Wire Rp2pService -> P2pRelayedContactRepository
        // Reason: Rp2pService needs relayed contacts for two-phase best-fee selection
        if (isset($this->services['Rp2pService'])) {
            $this->services['Rp2pService']->setP2pRelayedContactRepository($this->getP2pRelayedContactRepository());
        }

        // Wire P2pService -> P2pRelayedContactRepository
        // Reason: P2pService stores already_relayed contacts during broadcast for two-phase selection
        if (isset($this->services['P2pService'])) {
            $this->services['P2pService']->setP2pRelayedContactRepository($this->getP2pRelayedContactRepository());
        }
    }

    /**
     * Initialize all services and wire circular dependencies
     *
     * This method eagerly initializes all commonly used services and wires up
     * their circular dependencies. Call this when you want to ensure all services
     * are ready and properly connected before processing requests.
     *
     * IMPORTANT: This method MUST be called before using services with circular
     * dependencies. Services no longer fall back to Application::getInstance()
     * and will throw RuntimeException if dependencies are not wired.
     */
    public function wireAllServices(): void {
        // Initialize core services (order matters for some dependencies)
        $this->getSyncService();
        $this->getHeldTransactionService();
        $this->getContactManagementService();
        $this->getContactSyncService();
        $this->getContactService();
        $this->getTransactionService();
        $this->getP2pService();
        $this->getRp2pService();
        $this->getMessageService();
        $this->getContactStatusService();
        $this->getRateLimiterService();
        $this->getCleanupService();
        $this->getTransactionRecoveryService();

        // Initialize new refactored services
        $this->getBalanceService();
        $this->getChainVerificationService();
        $this->getTransactionValidationService();
        $this->getTransactionProcessingService();
        $this->getSendOperationService();

        // Initialize new dependency injection pattern services
        $this->getChainOperationsService();
        $this->getChainDropService();
        $this->getEventDispatcher();

        // Wire circular dependencies
        $this->wireCircularDependencies();
    }

    /**
     * Register a custom service instance
     *
     * @param string $name Service name
     * @param mixed $instance Service instance
     */
    public function registerService(string $name, $instance): void {
        $this->services[$name] = $instance;
    }

    /**
     * Register a custom util instance
     *
     * @param string $name util name
     * @param mixed $instance Util instance
     */
    public function registerUtil(string $name, $instance): void {
        $this->utils[$name] = $instance;
    }

    /**
     * Check if a service is registered
     *
     * @param string $name Service name
     * @return bool True if service exists
     */
    public function hasService(string $name): bool {
        return isset($this->services[$name]);
    }

    /**
     * Check if a util is registered
     *
     * @param string $name Util name
     * @return bool True if Util exists
     */
    public function hasUtil(string $name): bool {
        return isset($this->utils[$name]);
    }

    /**
     * Get a registered service by name
     *
     * @param string $name Service name
     * @return mixed Service instance or null
     */
    public function getService(string $name) {
        return $this->services[$name] ?? null;
    }

    /**
     * Get a registered util by name
     *
     * @param string $name Util name
     * @return mixed Util instance or null
     */
    public function getUtil(string $name) {
        return $this->utils[$name] ?? null;
    }

    // =========================================================================
    // PSR-11 CONTAINER INTERFACE IMPLEMENTATION
    // =========================================================================

    /**
     * Get the PHP-DI container instance (lazy-loaded)
     *
     * This provides access to the underlying DI container for advanced use cases
     * and gradual migration from service locator to proper dependency injection.
     *
     * @return ContainerInterface PHP-DI container
     */
    public function getContainer(): ContainerInterface {
        if ($this->phpdi === null) {
            $this->phpdi = $this->buildPhpDiContainer();
        }
        return $this->phpdi;
    }

    /**
     * Build the PHP-DI container
     *
     * @return ContainerInterface
     * @throws RuntimeException If container configuration is not found
     */
    private function buildPhpDiContainer(): ContainerInterface {
        $configPath = dirname(__DIR__) . '/config/container.php';

        if (!file_exists($configPath)) {
            throw new RuntimeException("Container configuration not found: {$configPath}");
        }

        require_once $configPath;

        if (!function_exists('Eiou\\Config\\buildContainer')) {
            throw new RuntimeException("buildContainer function not found in container configuration");
        }

        return \Eiou\Config\buildContainer($this->pdo, $this->currentUser);
    }

    /**
     * PSR-11: Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed Entry
     * @throws \Psr\Container\NotFoundExceptionInterface No entry found for identifier
     */
    public function get(string $id): mixed {
        // First check if we have a getter method for this service
        $getterMethod = 'get' . $id;
        if (method_exists($this, $getterMethod)) {
            return $this->$getterMethod();
        }

        // Check if it's a registered service or util
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }
        if (isset($this->utils[$id])) {
            return $this->utils[$id];
        }
        if (isset($this->repositories[$id])) {
            return $this->repositories[$id];
        }

        // Try to resolve via PHP-DI if available
        if ($this->phpdi !== null && $this->phpdi->has($id)) {
            return $this->phpdi->get($id);
        }

        throw new class("Service not found: {$id}") extends \Exception implements \Psr\Container\NotFoundExceptionInterface {};
    }

    /**
     * PSR-11: Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     * @return bool
     */
    public function has(string $id): bool {
        // Check for getter method
        $getterMethod = 'get' . $id;
        if (method_exists($this, $getterMethod)) {
            return true;
        }

        // Check registered services/utils/repos
        if (isset($this->services[$id]) || isset($this->utils[$id]) || isset($this->repositories[$id])) {
            return true;
        }

        // Check PHP-DI container
        if ($this->phpdi !== null && $this->phpdi->has($id)) {
            return true;
        }

        return false;
    }

    /**
     * Reset the singleton instance (for testing purposes)
     *
     * @return void
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    /**
     * Prevent cloning of singleton
     *
     * @return void
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of singleton
     *
     * @throws Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
}