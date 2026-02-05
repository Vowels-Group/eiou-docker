<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Config;

/**
 * PHP-DI Container Configuration
 *
 * This file configures the dependency injection container using PHP-DI.
 * It maps interfaces to implementations and configures service factories.
 *
 * Phase 1: Foundation setup - defines interface bindings and basic autowiring.
 * The existing ServiceContainer delegates to this container internally.
 *
 * @see https://php-di.org/doc/php-definitions.html
 */

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use function DI\autowire;
use function DI\create;
use function DI\get;
use function DI\factory;

// Repository imports
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
use Eiou\Database\RateLimiterRepository;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionContactRepository;

// Service interface imports
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
use Eiou\Contracts\SyncTriggerInterface;
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
use Eiou\Contracts\TransactionValidationServiceInterface;
use Eiou\Contracts\TransactionProcessingServiceInterface;
use Eiou\Contracts\SendOperationServiceInterface;
use Eiou\Contracts\ChainOperationsInterface;
use Eiou\Contracts\EventDispatcherInterface;
use Eiou\Contracts\LoggerInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;

// Service implementation imports
use Eiou\Services\ContactService;
use Eiou\Services\ContactManagementService;
use Eiou\Services\ContactSyncService;
use Eiou\Services\TransactionService;
use Eiou\Services\P2pService;
use Eiou\Services\Rp2pService;
use Eiou\Services\WalletService;
use Eiou\Services\MessageService;
use Eiou\Services\CleanupService;
use Eiou\Services\SyncService;
use Eiou\Services\DebugService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Services\HeldTransactionService;
use Eiou\Services\TransactionRecoveryService;
use Eiou\Services\CliService;
use Eiou\Services\RateLimiterService;
use Eiou\Services\LockingService;
use Eiou\Services\ContactStatusService;
use Eiou\Services\ApiAuthService;
use Eiou\Services\ApiKeyService;
use Eiou\Services\BackupService;
use Eiou\Services\BalanceService;
use Eiou\Services\ChainVerificationService;
use Eiou\Services\TransactionValidationService;
use Eiou\Services\TransactionProcessingService;
use Eiou\Services\SendOperationService;
use Eiou\Services\ChainOperationsService;
use Eiou\Services\Proxies\SyncServiceProxy;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Events\EventDispatcher;
use Eiou\Core\UserContext;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Logger;
use Eiou\Utils\SecureLogger;
use Eiou\Utils\Security;
use Eiou\Schemas\Payloads\TransactionPayload;

/**
 * Build and return the PHP-DI container
 *
 * @param PDO|null $pdo Database connection (optional, will be loaded if not provided)
 * @param UserContext|null $userContext User context (optional, will be loaded if not provided)
 * @param bool $enableCompilation Enable container compilation for production
 * @param string|null $compilationPath Path for compiled container cache
 * @return ContainerInterface
 */
function buildContainer(
    ?PDO $pdo = null,
    ?UserContext $userContext = null,
    bool $enableCompilation = false,
    ?string $compilationPath = null
): ContainerInterface {
    $builder = new ContainerBuilder();

    // Enable compilation in production for better performance
    if ($enableCompilation && $compilationPath !== null) {
        $builder->enableCompilation($compilationPath);
    }

    // Core dependencies that must be provided or loaded
    $definitions = [
        // =================================================================
        // FOUNDATIONAL DEPENDENCIES
        // =================================================================

        PDO::class => $pdo ?? factory(function () {
            return \Eiou\Database\createPDOConnection();
        }),

        UserContext::class => $userContext ?? factory(function () {
            return UserContext::getInstance();
        }),

        // =================================================================
        // REPOSITORIES
        // All repositories receive PDO via autowiring
        // =================================================================

        AddressRepository::class => autowire(),
        BalanceRepository::class => autowire(),
        ContactRepository::class => autowire(),
        P2pRepository::class => autowire(),
        Rp2pRepository::class => autowire(),
        TransactionRepository::class => autowire(),
        DebugRepository::class => autowire(),
        ApiKeyRepository::class => autowire(),
        MessageDeliveryRepository::class => autowire(),
        DeadLetterQueueRepository::class => autowire(),
        DeliveryMetricsRepository::class => autowire(),
        HeldTransactionRepository::class => autowire(),
        RateLimiterRepository::class => autowire(),
        TransactionStatisticsRepository::class => autowire(),
        TransactionChainRepository::class => autowire(),
        TransactionRecoveryRepository::class => autowire(),
        TransactionContactRepository::class => autowire(),

        // =================================================================
        // UTILITY SERVICES
        // =================================================================

        InputValidator::class => autowire(),
        Security::class => autowire(),
        TransactionPayload::class => autowire(),

        // SecureLogger is a static utility, but we can still provide instance
        SecureLogger::class => factory(function () {
            return new SecureLogger();
        }),

        // Logger facade wraps SecureLogger + optional DebugService bridge
        Logger::class => factory(function () {
            return Logger::getInstance();
        }),

        // =================================================================
        // EVENT DISPATCHER
        // =================================================================

        EventDispatcherInterface::class => autowire(EventDispatcher::class),
        EventDispatcher::class => autowire(),

        // =================================================================
        // INTERFACE TO IMPLEMENTATION MAPPINGS
        // These allow services to depend on interfaces rather than concrete classes
        // =================================================================

        // Contact Services
        ContactManagementServiceInterface::class => get(ContactManagementService::class),
        ContactSyncServiceInterface::class => get(ContactSyncService::class),
        ContactServiceInterface::class => get(ContactService::class),

        // Transaction Services
        TransactionServiceInterface::class => get(TransactionService::class),
        BalanceServiceInterface::class => get(BalanceService::class),
        ChainVerificationServiceInterface::class => get(ChainVerificationService::class),
        TransactionValidationServiceInterface::class => get(TransactionValidationService::class),
        TransactionProcessingServiceInterface::class => get(TransactionProcessingService::class),
        SendOperationServiceInterface::class => get(SendOperationService::class),
        ChainOperationsInterface::class => get(ChainOperationsService::class),

        // P2P Services
        P2pServiceInterface::class => get(P2pService::class),
        Rp2pServiceInterface::class => get(Rp2pService::class),
        P2pTransactionSenderInterface::class => get(SendOperationService::class),

        // Sync Services
        SyncServiceInterface::class => get(SyncService::class),
        SyncTriggerInterface::class => get(SyncServiceProxy::class),

        // Other Services
        WalletServiceInterface::class => get(WalletService::class),
        MessageServiceInterface::class => get(MessageService::class),
        CleanupServiceInterface::class => get(CleanupService::class),
        DebugServiceInterface::class => get(DebugService::class),
        LoggerInterface::class => get(Logger::class),
        MessageDeliveryServiceInterface::class => get(MessageDeliveryService::class),
        HeldTransactionServiceInterface::class => get(HeldTransactionService::class),
        TransactionRecoveryServiceInterface::class => get(TransactionRecoveryService::class),
        CliServiceInterface::class => get(CliService::class),
        RateLimiterServiceInterface::class => get(RateLimiterService::class),
        LockingServiceInterface::class => get(LockingService::class),
        ContactStatusServiceInterface::class => get(ContactStatusService::class),
        ApiAuthServiceInterface::class => get(ApiAuthService::class),
        ApiKeyServiceInterface::class => get(ApiKeyService::class),
        BackupServiceInterface::class => get(BackupService::class),
    ];

    $builder->addDefinitions($definitions);

    return $builder->build();
}

/**
 * Get pre-built definitions array for extending the container
 *
 * @return array Definition array
 */
function getContainerDefinitions(): array {
    return [
        // This can be used by ServiceContainer to merge definitions
        // without rebuilding the entire container
    ];
}
