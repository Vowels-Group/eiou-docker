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
use Eiou\Contracts\RouteCancellationServiceInterface;
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
use Eiou\Services\PaymentRequestService;
use Eiou\Services\PaymentRequestArchivalService;
use Eiou\Services\TransactionArchivalService;
use Eiou\Services\ChainAuditService;
use Eiou\Events\EventDispatcher;
use Eiou\Events\SyncEvents;
use Eiou\Services\Proxies\SyncServiceProxy;
use Eiou\Services\ContactManagementService;
use Eiou\Services\ContactSyncService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Database\RepositoryFactory;
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
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\RateLimiterRepository;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\RouteCancellationRepository;
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
     * @var RepositoryFactory Centralized repository creation and caching (ARCH-05)
     */
    private ?RepositoryFactory $repositoryFactory = null;

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

        if ($this->pdo !== null) {
            $this->repositoryFactory = new RepositoryFactory($this->pdo);
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
            $this->repositoryFactory = new RepositoryFactory($this->pdo);
        } catch (RuntimeException $e) {
            // Log the error
            Logger::getInstance()->logException($e, [], 'CRITICAL');
            // Set PDO to null to indicate unavailability
            $this->pdo = null;
            $this->repositoryFactory = null;
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

    // =========================================================================
    // Repository Access
    // =========================================================================
    // Use $this->getRepositoryFactory()->get(XxxRepository::class) to obtain
    // repository instances. RepositoryFactory handles lazy creation & caching.
    // =========================================================================

    /** @return RepositoryFactory */
    public function getRepositoryFactory(): RepositoryFactory {
        if ($this->repositoryFactory === null) {
            throw new RuntimeException('Database connection is not available — cannot create repositories');
        }
        return $this->repositoryFactory;
    }

    /**
     * Get ContactManagementService instance
     * @return ContactManagementServiceInterface
     */
    public function getContactManagementService(): ContactManagementServiceInterface {
        if (!isset($this->services['ContactManagementService'])) {
            $this->services['ContactManagementService'] = new ContactManagementService(
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->currentUser,
                $this->getRepositoryFactory(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionContactRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(TransactionRecoveryRepository::class),
                $this->getRepositoryFactory()->get(TransactionContactRepository::class),
                $this->getRepositoryFactory()->get(TransactionStatisticsRepository::class),
                $this->getUtilityContainer(),
                $this->getInputValidator(),
                $this->getLogger(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getHeldTransactionService(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory(),
                $this->getMessageDeliveryService(),
                $this->getRepositoryFactory()->get(P2pSenderRepository::class)
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
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getRepositoryFactory()->get(Rp2pCandidateRepository::class),
                $this->getRepositoryFactory()->get(P2pSenderRepository::class),
                $this->getRepositoryFactory()
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
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionContactRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getSyncServiceProxy(),
                $this->getRepositoryFactory()
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
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getMessageDeliveryService(),
                $this->getRepositoryFactory()
            );
        }
        return $this->services['CleanupService'];
    }

    /**
     * Get RouteCancellationService instance
     *
     * @return RouteCancellationServiceInterface
     */
    public function getRouteCancellationService(): RouteCancellationServiceInterface {
        if (!isset($this->services['RouteCancellationService'])) {
            $this->services['RouteCancellationService'] = new RouteCancellationService(
                $this->getRepositoryFactory()
            );
        }
        return $this->services['RouteCancellationService'];
    }

    /**
     * Get SyncService instance
     *
     * @return SyncServiceInterface
     */
    public function getSyncService(): SyncServiceInterface {
        if (!isset($this->services['SyncService'])) {
            $this->services['SyncService'] = new SyncService(
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(TransactionContactRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
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
                $this->getRepositoryFactory()->get(DebugRepository::class),
                $this->currentUser
            );
        }
        return $this->services['DebugService'];
    }

    /**
     * Get DebugReportService instance
     *
     * @return DebugReportService
     */
    public function getDebugReportService(): DebugReportService {
        if (!isset($this->services['DebugReportService'])) {
            $this->services['DebugReportService'] = new DebugReportService(
                $this->getRepositoryFactory()->get(DebugRepository::class),
                $this->pdo
            );
        }
        return $this->services['DebugReportService'];
    }

    /**
     * Get MessageDeliveryService instance
     *
     * @return MessageDeliveryService
     */
    public function getMessageDeliveryService(): MessageDeliveryServiceInterface {
        if (!isset($this->services['MessageDeliveryService'])) {
            $this->services['MessageDeliveryService'] = new MessageDeliveryService(
                $this->getRepositoryFactory()->get(MessageDeliveryRepository::class),
                $this->getRepositoryFactory()->get(DeadLetterQueueRepository::class),
                $this->getRepositoryFactory()->get(DeliveryMetricsRepository::class),
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
                $this->getRepositoryFactory()->get(HeldTransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory()->get(P2pRepository::class)
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
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionRecoveryRepository::class)
            );
        }
        return $this->services['TransactionRecoveryService'];
    }

    /**
     * Get CliService instance
     *
     * Note: ContactCreditRepository and P2pRepository are wired via setter
     * injection in wireCircularDependencies(). This service must be initialized
     * in wireAllServices() before that method runs.
     *
     * @return CliService
     */
    public function getCliService(): CliServiceInterface {
        if (!isset($this->services['CliService'])) {
            $this->services['CliService'] = new CliService(
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory()
            );
        }
        return $this->services['CliService'];
    }

    /**
     * Get CliP2pApprovalService instance
     *
     * Extracted from CliService (ARCH-04) to handle P2P approval CLI commands.
     * Dependencies are setter-injected in wireCircularDependencies().
     *
     * @return CliP2pApprovalService
     */
    public function getCliP2pApprovalService(): CliP2pApprovalService {
        if (!isset($this->services['CliP2pApprovalService'])) {
            $service = new CliP2pApprovalService(
                $this->getUtilityContainer()->getCurrencyUtility()
            );
            $service->setP2pRepository($this->getRepositoryFactory()->get(P2pRepository::class));
            $service->setP2pApprovalDependencies(
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pCandidateRepository::class),
                $this->getSendOperationService(),
                $this->getP2pService()
            );
            $service->setApprovalService($this->getP2pApprovalService());
            $this->services['CliP2pApprovalService'] = $service;
        }
        return $this->services['CliP2pApprovalService'];
    }

    /**
     * Shared approve/reject commit point used by CLI, API, and GUI. Holds
     * the validation rules and side-effect sequence in one place so every
     * path emits P2P_APPROVED / P2P_REJECTED consistently.
     */
    public function getP2pApprovalService(): P2pApprovalService {
        if (!isset($this->services['P2pApprovalService'])) {
            $this->services['P2pApprovalService'] = new P2pApprovalService(
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pCandidateRepository::class),
                $this->getSendOperationService(),
                $this->getP2pService()
            );
        }
        return $this->services['P2pApprovalService'];
    }

    /**
     * Registry for plugin-owned CLI subcommands. Plugins grab this in
     * boot() and call ->register() to expose `eiou <plugin> ...` verbs.
     */
    public function getPluginCliRegistry(): PluginCliRegistry {
        if (!isset($this->services['PluginCliRegistry'])) {
            $this->services['PluginCliRegistry'] = new PluginCliRegistry();
        }
        return $this->services['PluginCliRegistry'];
    }

    /**
     * Registry for plugin-owned REST endpoints under
     * /api/v1/plugins/{plugin}/{action}. Plugins register in boot().
     */
    public function getPluginApiRegistry(): PluginApiRegistry {
        if (!isset($this->services['PluginApiRegistry'])) {
            $this->services['PluginApiRegistry'] = new PluginApiRegistry();
        }
        return $this->services['PluginApiRegistry'];
    }

    /**
     * Get CliDlqService instance
     *
     * Extracted from CliService (ARCH-04) to handle DLQ management CLI commands.
     * Dependencies are setter-injected in wireCircularDependencies().
     *
     * @return CliDlqService
     */
    public function getCliDlqService(): CliDlqService {
        if (!isset($this->services['CliDlqService'])) {
            $service = new CliDlqService(
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getRepositoryFactory()->get(TransactionRepository::class)
            );
            $service->setDeadLetterQueueRepository($this->getRepositoryFactory()->get(DeadLetterQueueRepository::class));
            $service->setMessageDeliveryService($this->getMessageDeliveryService());
            $this->services['CliDlqService'] = $service;
        }
        return $this->services['CliDlqService'];
    }

    /**
     * Get CliSettingsService instance
     *
     * Extracted from CliService (ARCH-04) to handle settings management CLI commands.
     *
     * @return CliSettingsService
     */
    public function getCliSettingsService(): CliSettingsService {
        if (!isset($this->services['CliSettingsService'])) {
            $this->services['CliSettingsService'] = new CliSettingsService(
                $this->currentUser
            );
        }
        return $this->services['CliSettingsService'];
    }

    /**
     * Get CliHelpService instance
     *
     * Extracted from CliService (ARCH-04) to handle help display CLI commands.
     *
     * @return CliHelpService
     */
    public function getCliHelpService(): CliHelpService {
        if (!isset($this->services['CliHelpService'])) {
            $this->services['CliHelpService'] = new CliHelpService();
        }
        return $this->services['CliHelpService'];
    }

    /**
     * Get RateLimiterService instance
     *
     * @return RateLimiterService
     */
    public function getRateLimiterService(): RateLimiterServiceInterface {
        if (!isset($this->services['RateLimiterService'])) {
            $this->services['RateLimiterService'] = new RateLimiterService(
                $this->getRepositoryFactory()->get(RateLimiterRepository::class)
            );
        }
        return $this->services['RateLimiterService'];
    }

    /**
     * Get RememberTokenService — issues, rotates, revokes GUI "Remember me"
     * login tokens backed by the `remember_tokens` table.
     */
    public function getRememberTokenService(): \Eiou\Services\RememberTokenService {
        if (!isset($this->services['RememberTokenService'])) {
            $this->services['RememberTokenService'] = new \Eiou\Services\RememberTokenService(
                $this->getRepositoryFactory()->get(\Eiou\Database\RememberTokenRepository::class)
            );
        }
        return $this->services['RememberTokenService'];
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
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(ApiKeyRepository::class),
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
                $this->getRepositoryFactory()->get(ApiKeyRepository::class),
                $output
            );
        }
        return $this->services['ApiKeyService'];
    }

    /**
     * Get PaybackMethodService instance
     *
     * Orchestrates CRUD for the user's payback-methods profile, wrapping the
     * PaybackMethodRepository and per-type validator and transparently
     * encrypting the sensitive fields via KeyEncryption.
     *
     * @return \Eiou\Services\PaybackMethodService
     */
    public function getPaybackMethodService(): \Eiou\Services\PaybackMethodService {
        if (!isset($this->services['PaybackMethodService'])) {
            $registry = $this->getPaybackMethodTypeRegistry();
            $this->services['PaybackMethodService'] = new \Eiou\Services\PaybackMethodService(
                $this->getRepositoryFactory()->get(\Eiou\Database\PaybackMethodRepository::class),
                new \Eiou\Validators\PaybackMethodTypeValidator($registry),
                new \Eiou\Services\SettlementPrecisionService($registry),
                null,     // logger — defaults
                $registry
            );
        }
        return $this->services['PaybackMethodService'];
    }

    /**
     * Get PaybackMethodTypeRegistry instance.
     *
     * Plugin-extensible map of payback-method rail types (BTC, PayPal, …).
     * Plugins register types against this during their `register()` phase;
     * the validator + service consult it for non-core ids. Always returns
     * the same instance within a process so plugin registrations stick
     * for every subsequent resolver.
     */
    public function getPaybackMethodTypeRegistry(): \Eiou\Services\PaybackMethodTypeRegistry {
        if (!isset($this->services['PaybackMethodTypeRegistry'])) {
            $this->services['PaybackMethodTypeRegistry'] = new \Eiou\Services\PaybackMethodTypeRegistry();
        }
        return $this->services['PaybackMethodTypeRegistry'];
    }

    /**
     * Get PaybackMethodCliHandler instance
     *
     * Thin CLI surface (list/add/show/edit/remove/share-policy) on top of the
     * PaybackMethodService.
     */
    public function getPaybackMethodCliHandler(CliOutputManager $output): \Eiou\Cli\PaybackMethodCliHandler {
        return new \Eiou\Cli\PaybackMethodCliHandler(
            $this->getPaybackMethodService(),
            $output
        );
    }

    /**
     * Get PluginCredentialService instance.
     *
     * Generates and stores the encrypted MySQL password for each plugin's
     * isolated DB user. See docs/PLUGIN_ISOLATION.md §1.
     */
    public function getPluginCredentialService(): \Eiou\Services\PluginCredentialService {
        if (!isset($this->services['PluginCredentialService'])) {
            $this->services['PluginCredentialService'] = new \Eiou\Services\PluginCredentialService(
                $this->getRepositoryFactory()->get(\Eiou\Database\PluginCredentialRepository::class)
            );
        }
        return $this->services['PluginCredentialService'];
    }

    /**
     * Get PluginDbUserService instance.
     *
     * Manages MySQL user lifecycle (create / grant / revoke / drop) for
     * plugins that declare `database.user: true` in their manifest. Runs
     * as the root/app PDO — user-management DDL is a privileged operation
     * the plugin users themselves will never hold.
     *
     * See docs/PLUGIN_ISOLATION.md §2, §3, §5.
     */
    public function getPluginDbUserService(): \Eiou\Services\PluginDbUserService {
        if (!isset($this->services['PluginDbUserService'])) {
            $this->services['PluginDbUserService'] = new \Eiou\Services\PluginDbUserService(
                $this->getPdo()
            );
        }
        return $this->services['PluginDbUserService'];
    }

    /**
     * Get ReceivedPaybackMethodService instance
     *
     * Handles the E2E contact-fetch flow for payback methods: outgoing
     * requests, incoming request/response/revoke handlers, and the
     * TTL-cached list surfaced by the contact-modal "Payback Options" view.
     */
    public function getReceivedPaybackMethodService(): \Eiou\Services\ReceivedPaybackMethodService {
        if (!isset($this->services['ReceivedPaybackMethodService'])) {
            $this->services['ReceivedPaybackMethodService'] = new \Eiou\Services\ReceivedPaybackMethodService(
                $this->getRepositoryFactory()->get(\Eiou\Database\PaybackMethodReceivedRepository::class),
                $this->getPaybackMethodService()
            );
        }
        return $this->services['ReceivedPaybackMethodService'];
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
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getRepositoryFactory()->get(TransactionContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
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
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->currentUser,
                $this->getLogger(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getContactService(),
                $this->getUtilityContainer()->getValidationUtility(),
                $this->getInputValidator(),
                $this->getTransactionPayload(),
                $this->currentUser,
                $this->getLogger(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(TransactionRecoveryRepository::class),
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getRepositoryFactory()->get(Rp2pRepository::class),
                $this->getRepositoryFactory()->get(BalanceRepository::class),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getRepositoryFactory()->get(P2pRepository::class),
                $this->getTransactionPayload(),
                $this->getUtilityContainer()->getTransportUtility(),
                $this->getUtilityContainer()->getTimeUtility(),
                $this->getInputValidator(),
                $this->currentUser,
                $this->getLogger(),
                $this->getMessageDeliveryService(),
                $this->getLockingService(),
                $this->getRepositoryFactory(),
                $this->getSyncServiceProxy()
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
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
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
                $this->getRepositoryFactory()->get(ChainDropProposalRepository::class),
                $this->getRepositoryFactory()->get(TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(TransactionRepository::class),
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getUtilityContainer(),
                $this->currentUser,
                $this->getRepositoryFactory(),
                $this->getSyncServiceProxy()
            );
        }
        return $this->services['ChainDropService'];
    }

    /**
     * Get PaymentRequestService instance
     *
     * @return PaymentRequestService
     */
    public function getPaymentRequestService(): PaymentRequestService {
        if (!isset($this->services['PaymentRequestService'])) {
            $this->services['PaymentRequestService'] = new PaymentRequestService(
                $this->getRepositoryFactory()->get(\Eiou\Database\PaymentRequestRepository::class),
                $this->getRepositoryFactory()->get(ContactRepository::class),
                $this->getRepositoryFactory()->get(AddressRepository::class),
                $this->getTransactionService(),
                $this->getUtilityContainer()->getTransportUtility($this->currentUser),
                $this->currentUser,
                $this->getLogger()
            );
        }
        return $this->services['PaymentRequestService'];
    }

    /**
     * Get PaymentRequestArchivalService instance
     *
     * Used by the payment-request-archive-cron to move resolved requests
     * older than the retention threshold into payment_requests_archive.
     */
    public function getPaymentRequestArchivalService(): PaymentRequestArchivalService {
        if (!isset($this->services['PaymentRequestArchivalService'])) {
            $this->services['PaymentRequestArchivalService'] = new PaymentRequestArchivalService(
                $this->getRepositoryFactory()->get(\Eiou\Database\PaymentRequestArchiveRepository::class),
                $this->currentUser,
                $this->getBackupService()
            );
        }
        return $this->services['PaymentRequestArchivalService'];
    }

    /**
     * Get TransactionArchivalService instance
     *
     * Used by the transaction-archive-cron to move completed transactions
     * older than the retention threshold into transactions_archive, gated
     * on per-bilateral-pair chain-integrity verification.
     */
    public function getTransactionArchivalService(): TransactionArchivalService {
        if (!isset($this->services['TransactionArchivalService'])) {
            $this->services['TransactionArchivalService'] = new TransactionArchivalService(
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionArchiveRepository::class),
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionChainRepository::class),
                $this->currentUser,
                $this->getBackupService()
            );
        }
        return $this->services['TransactionArchivalService'];
    }

    /**
     * Get ChainAuditService instance
     *
     * Used by the `eiou verify-chain` CLI command to audit bilateral chains
     * end-to-end (live + archive) and compare each pair's archive hash
     * against the stored checkpoint — Phase 2's safety net against archive
     * tampering. Distinct from ChainVerificationService above which runs
     * pre-send chain-integrity checks.
     */
    public function getChainAuditService(): ChainAuditService {
        if (!isset($this->services['ChainAuditService'])) {
            $this->services['ChainAuditService'] = new ChainAuditService(
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionChainRepository::class),
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionArchiveRepository::class)
            );
        }
        return $this->services['ChainAuditService'];
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
     * - CliService --> ContactCreditRepository, P2pRepository (for info command display)
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
        // Service-to-service circular dependencies (ARCH-01)
        //
        // Only genuine circular or late-bound service deps remain here.
        // Repository deps and SyncTriggerInterface are now constructor-injected.
        // =========================================================================

        // SyncService -> HeldTransactionService (one-way; reverse is event-driven)
        if (isset($this->services['SyncService']) && isset($this->services['HeldTransactionService'])) {
            $this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
        }

        // SyncService -> BackupService
        if (isset($this->services['SyncService'])) {
            $this->services['SyncService']->setBackupService($this->getBackupService());
        }

        // ContactManagementService -> ContactSyncService
        if (isset($this->services['ContactManagementService']) && isset($this->services['ContactSyncService'])) {
            $this->services['ContactManagementService']->setContactSyncService($this->services['ContactSyncService']);
        }

        // ContactSyncService -> MessageDeliveryService
        if (isset($this->services['ContactSyncService']) && isset($this->services['MessageDeliveryService'])) {
            $this->services['ContactSyncService']->setMessageDeliveryService($this->services['MessageDeliveryService']);
        }

        // ContactStatusService -> RateLimiterService, ChainDropService
        if (isset($this->services['ContactStatusService'])) {
            if (isset($this->services['RateLimiterService'])) {
                $this->services['ContactStatusService']->setRateLimiterService($this->services['RateLimiterService']);
            }
            if (isset($this->services['ChainDropService'])) {
                $this->services['ContactStatusService']->setChainDropService($this->services['ChainDropService']);
            }
        }

        // MessageService -> ChainDropService
        if (isset($this->services['MessageService']) && isset($this->services['ChainDropService'])) {
            $this->services['MessageService']->setChainDropService($this->services['ChainDropService']);
        }

        // MessageService -> PaymentRequestService
        if (isset($this->services['MessageService']) && isset($this->services['PaymentRequestService'])) {
            $this->services['MessageService']->setPaymentRequestService($this->services['PaymentRequestService']);
        }

        // PaymentRequestService -> MessageDeliveryService
        if (isset($this->services['PaymentRequestService']) && isset($this->services['MessageDeliveryService'])) {
            $this->services['PaymentRequestService']->setMessageDeliveryService($this->services['MessageDeliveryService']);
        }

        // MessageDeliveryService -> TransactionRepository, TransactionChainRepository
        // Needed for refreshing stale transaction DLQ payloads (previousTxid + time)
        // before retry. Setter-injected late so MessageDeliveryService can be
        // constructed before the repositories are resolved.
        if (isset($this->services['MessageDeliveryService'])) {
            $this->services['MessageDeliveryService']->setTransactionRepository(
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class)
            );
            $this->services['MessageDeliveryService']->setTransactionChainRepository(
                $this->getRepositoryFactory()->get(\Eiou\Database\TransactionChainRepository::class)
            );
        }

        // ChainDropService -> BackupService
        if (isset($this->services['ChainDropService'])) {
            $this->services['ChainDropService']->setBackupService($this->getBackupService());
        }

        // CliService -> sub-services (ARCH-04 delegation)
        if (isset($this->services['CliService'])) {
            $this->services['CliService']->setP2pApprovalService($this->getCliP2pApprovalService());
            $this->services['CliService']->setDlqService($this->getCliDlqService());
            $this->services['CliService']->setSettingsService($this->getCliSettingsService());
            $this->services['CliService']->setHelpService($this->getCliHelpService());
        }

        // ChainOperationsService -> SyncService
        if (isset($this->services['ChainOperationsService']) && isset($this->services['SyncService'])) {
            $this->services['ChainOperationsService']->setSyncService($this->services['SyncService']);
        }

        // =========================================================================
        // Transaction-related circular dependencies
        // =========================================================================

        // Rp2pService -> SendOperationService (via P2pTransactionSenderInterface)
        if (isset($this->services['Rp2pService']) && isset($this->services['SendOperationService'])) {
            $this->services['Rp2pService']->setP2pTransactionSender($this->services['SendOperationService']);
        }

        // TransactionService -> P2pService, ContactService, and refactored sub-services
        if (isset($this->services['TransactionService'])) {
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['ContactService'])) {
                $this->services['TransactionService']->setContactService($this->services['ContactService']);
            }
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

        // TransactionValidationService -> TransactionService (circular)
        if (isset($this->services['TransactionValidationService']) && isset($this->services['TransactionService'])) {
            $this->services['TransactionValidationService']->setTransactionService($this->services['TransactionService']);
        }

        // TransactionProcessingService -> P2pService, HeldTransactionService, ContactCurrencyRepository
        if (isset($this->services['TransactionProcessingService'])) {
            if (isset($this->services['P2pService'])) {
                $this->services['TransactionProcessingService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['HeldTransactionService'])) {
                $this->services['TransactionProcessingService']->setHeldTransactionService($this->services['HeldTransactionService']);
            }
            $this->services['TransactionProcessingService']->setContactCurrencyRepository(
                $this->getRepositoryFactory()->get(ContactCurrencyRepository::class)
            );
        }

        // SendOperationService -> ContactService, P2pService, TransactionService, ChainDropService
        if (isset($this->services['SendOperationService'])) {
            if (isset($this->services['ContactService'])) {
                $this->services['SendOperationService']->setContactService($this->services['ContactService']);
            }
            if (isset($this->services['P2pService'])) {
                $this->services['SendOperationService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['TransactionService'])) {
                $this->services['SendOperationService']->setTransactionService($this->services['TransactionService']);
            }
            if (isset($this->services['ChainDropService'])) {
                $this->services['SendOperationService']->setChainDropService($this->services['ChainDropService']);
            }
        }

        // =========================================================================
        // P2P / RP2P / routing circular dependencies
        // =========================================================================

        // CleanupService -> ChainDropService, Rp2pService, P2pService
        if (isset($this->services['CleanupService'])) {
            if (isset($this->services['ChainDropService'])) {
                $this->services['CleanupService']->setChainDropService($this->services['ChainDropService']);
            }
            if (isset($this->services['Rp2pService'])) {
                $this->services['CleanupService']->setRp2pService($this->services['Rp2pService']);
            }
            if (isset($this->services['P2pService'])) {
                $this->services['CleanupService']->setP2pService($this->services['P2pService']);
            }
        }

        // Rp2pService -> P2pService, RouteCancellationService
        if (isset($this->services['Rp2pService'])) {
            if (isset($this->services['P2pService'])) {
                $this->services['Rp2pService']->setP2pService($this->services['P2pService']);
            }
            if (isset($this->services['RouteCancellationService'])) {
                $this->services['Rp2pService']->setRouteCancellationService($this->services['RouteCancellationService']);
            }
        }

        // P2pService -> Rp2pService (circular)
        if (isset($this->services['P2pService']) && isset($this->services['Rp2pService'])) {
            $this->services['P2pService']->setRp2pService($this->services['Rp2pService']);
        }

        // RouteCancellationService -> P2pService
        if (isset($this->services['RouteCancellationService']) && isset($this->services['P2pService'])) {
            $this->services['RouteCancellationService']->setP2pService($this->services['P2pService']);
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
     *
     * IMPORTANT: Any service that receives setter injection in wireCircularDependencies()
     * MUST be initialized here first, otherwise the isset() check will fail silently
     * and the dependency will not be wired (the service will have null references).
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
        $this->getRouteCancellationService();
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

        // Initialize CLI service (must be before wireCircularDependencies
        // so setter injection for ContactCreditRepository and P2pRepository runs)
        $this->getCliService();

        // Initialize PaymentRequestService so wireCircularDependencies() can
        // wire it into MessageService (required for incoming payment_request handling)
        $this->getPaymentRequestService();

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
        if ($this->repositoryFactory !== null && is_subclass_of($id, \Eiou\Database\AbstractRepository::class)) {
            return $this->repositoryFactory->get($id);
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
        if (isset($this->services[$id]) || isset($this->utils[$id]) || ($this->repositoryFactory !== null && is_subclass_of($id, \Eiou\Database\AbstractRepository::class))) {
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