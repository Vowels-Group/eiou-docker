# EIOU Docker Architecture

Technical architecture documentation for the EIOU Docker node implementation.

## Table of Contents

1. [Overview](#overview)
2. [System Architecture Diagram](#system-architecture-diagram)
3. [Core Components](#core-components)
4. [Service Layer](#service-layer)
5. [Dependency Injection Patterns](#dependency-injection-patterns)
6. [Circular Dependency Management](#circular-dependency-management)
7. [Message Processing Pipeline](#message-processing-pipeline)
8. [Data Layer](#data-layer)
9. [P2P Networking](#p2p-networking)
10. [Transaction Lifecycle](#transaction-lifecycle)
11. [Startup Sequence](#startup-sequence)
12. [Docker Topologies](#docker-topologies)
13. [GUI Architecture](#gui-architecture)
14. [CLI Architecture](#cli-architecture)
15. [Payload Schemas](#payload-schemas)
16. [Security Model](#security-model)
17. [Error Handling](#error-handling)
18. [Related Documentation](#related-documentation)

---

## Overview

EIOU Docker is a peer-to-peer (P2P) payment system implemented as self-contained Docker
containers. Each node operates as an independent payment processing unit capable of
sending, receiving, and routing transactions across a decentralized network.

### What Is an EIOU Node?

An EIOU node is a complete, isolated payment system running in a Docker container. Each
node contains:

- **Wallet**: BIP39-based cryptographic wallet with secp256k1 keypairs
- **Database**: MariaDB instance storing contacts, transactions, and routing data
- **Processors**: Background daemons for transaction, P2P, and cleanup processing
- **APIs**: REST API, CLI interface, and Web GUI for node interaction

### Key Characteristics

| Characteristic | Description |
|----------------|-------------|
| Self-Contained | Each node has its own database, wallet, and processing |
| Decentralized | No central server; nodes communicate peer-to-peer |
| Privacy-First | Supports Tor hidden services for anonymous communication |
| Fault-Tolerant | Automatic recovery from crashes, transaction replay protection |

### Access Points

Nodes provide three interfaces for interaction:

```
+------------------+     +------------------+     +------------------+
|    REST API      |     |       CLI        |     |     Web GUI      |
|  (Port 8080)     |     |  (eiou command)  |     |  (Port 8080)     |
+------------------+     +------------------+     +------------------+
        |                        |                        |
        +------------------------+------------------------+
                                 |
                    +------------------------+
                    |    ServiceContainer    |
                    +------------------------+
```

- **REST API**: HTTP/HTTPS endpoints for programmatic access (see `API_REFERENCE.md`)
- **CLI**: Command-line interface via `eiou` command (see `CLI_REFERENCE.md`)
- **Web GUI**: Browser-based interface served on same port (see `GUI_REFERENCE.md`)

---

## System Architecture Diagram

### High-Level Architecture

```
                           EXTERNAL CLIENTS
                                  |
                    +-------------+-------------+
                    |             |             |
               REST API         CLI          Web GUI
                    |             |             |
                    +-------------+-------------+
                                  |
                    +-------------v-------------+
                    |         Apache2           |
                    |    (SSL/HTTPS + Mod_PHP)  |
                    +-------------+-------------+
                                  |
                    +-------------v-------------+
                    |       Application         |
                    |       (Singleton)         |
                    +-------------+-------------+
                                  |
          +-----------------------+------------------------+
          |                       |                        |
+---------v---------+   +---------v---------+   +----------v----------+
|   UserContext     |   | ServiceContainer  |   |   UtilityContainer  |
|  (Wallet Config)  |   |   (DI Container)  |   |  (Helper Services)  |
+-------------------+   +---------+---------+   +---------------------+
                                  |
        +-------------------------+-------------------------+
        |           |             |            |            |
+-------v---+ +-----v-----+ +-----v-----+ +----v----+ +-----v------+
|Transaction| |    P2p    | |  Contact  | |  Sync   | |  Message   |
|  Service  | |  Service  | |  Service  | | Service | |  Delivery  |
+-----------+ +-----------+ +-----------+ +---------+ +------------+
        |           |             |            |            |
        +-------------------------+-------------------------+
                                  |
                    +-------------v-------------+
                    |       Repositories        |
                    |   (Data Access Layer)     |
                    +-------------+-------------+
                                  |
                    +-------------v-------------+
                    |        MariaDB            |
                    |      (Persistent)         |
                    +---------------------------+
```

### Background Processors

```
                    +---------------------------+
                    |       startup.sh          |
                    |    (Container Entry)      |
                    +-------------+-------------+
                                  |
          +-----------------------+------------------------+
          |             |                |                 |
+---------v----+ +------v-------+ +------v-------+ +-------v--------+
| Transaction  | |     P2P      | |   Cleanup    | | ContactStatus  |
|  Processor   | |  Processor   | |  Processor   | |   Processor    |
| (100ms-5s)   | | (100ms-5s)   | | (1s-30s)     | |  (5 min cycle) |
+--------------+ +--------------+ +--------------+ +----------------+
                                  |
                    +-------------v-------------+
                    |        Watchdog           |
                    |  (Process Monitor 30s)    |
                    +---------------------------+
```

---

## Core Components

### Application Singleton

The `Application` class (`/src/core/Application.php`) is the central entry point that
manages global state and coordinates component initialization.

```php
// Access the Application singleton
$app = Application::getInstance();

// Access services
$transactionService = $app->services->getTransactionService();
```

**Responsibilities:**

| Responsibility | Description |
|----------------|-------------|
| Database Setup | Creates database and runs migrations on first startup |
| PDO Connection | Maintains singleton PDO connection for all components |
| User Loading | Loads UserContext from configuration files |
| Service Wiring | Initializes ServiceContainer and wires circular dependencies |
| Recovery | Runs transaction recovery for CLI/daemon processes |

**Key Properties:**

```php
class Application {
    protected $currentUser;      // UserContext instance
    protected $currentDatabase;  // DbContext instance
    protected $pdo;              // PDO connection
    public $services;            // ServiceContainer instance
    public $utilityServices;     // UtilityServiceContainer instance
    public array $processors;    // Cached processor instances
    public array $utils;         // Cached utility instances
}
```

### UserContext

The `UserContext` class (`/src/core/UserContext.php`) provides access to wallet
configuration and user-specific settings.

**Configuration Files:**

| File | Purpose |
|------|---------|
| `/etc/eiou/config/defaultconfig.json` | System defaults (fee limits, P2P settings) |
| `/etc/eiou/config/userconfig.json` | User-specific settings (keys, addresses) |

**Key Methods:**

```php
$user = UserContext::getInstance();
$user->getPublicKey();      // Get user's public key
$user->getPublicKeyHash();  // Get public key hash for identification
$user->get('hostname');     // Get any configuration value
$user->getAuthCode();       // Get authentication code
```

### Constants

The `Constants` class (`/src/core/Constants.php`) centralizes application-wide
configuration values, replacing magic numbers throughout the codebase.

**Key Configuration Categories:**

| Category | Example Constants |
|----------|-------------------|
| Polling Intervals | `TRANSACTION_MIN_INTERVAL_MS`, `P2P_MAX_INTERVAL_MS` |
| Transaction Limits | `TRANSACTION_MAX_AMOUNT`, `TRANSACTION_MINIMUM_FEE` |
| P2P Network | `P2P_DEFAULT_MAX_REQUEST_LEVEL`, `P2P_DEFAULT_EXPIRATION_SECONDS` |
| Contact Settings | `CONTACT_DEFAULT_CREDIT_LIMIT`, `CONTACT_STATUS_ENABLED` |
| Security | `HASH_ALGORITHM`, `RECOVERY_MAX_RETRY_COUNT` |

### Wallet

The `Wallet` class (`/src/core/Wallet.php`) handles BIP39 seed phrase generation and
cryptographic key derivation.

**Capabilities:**

- Generate new 24-word BIP39 mnemonic seed phrases
- Restore wallets from existing seed phrases (CLI or file-based)
- Derive deterministic secp256k1 keypairs from seeds
- Generate Tor hidden service addresses
- Create authentication codes for secure access

**Security Notes:**

- Private keys and mnemonics are encrypted before storage
- File permissions are set to 0600 (owner read/write only)
- File-based restore prevents exposure in process listings

---

## Service Layer

### ServiceContainer Overview

The `ServiceContainer` class (`/src/services/ServiceContainer.php`) implements the
dependency injection pattern, providing centralized management of service instances.
It implements PSR-11 `ContainerInterface` and integrates with PHP-DI for autowiring.

**Key Features:**

| Feature | Description |
|---------|-------------|
| PSR-11 Compliant | Implements `ContainerInterface` with `get()` and `has()` methods |
| PHP-DI Integration | Uses PHP-DI container for autowiring and interface bindings |
| Singleton Pattern | Single instance manages all services |
| Lazy Loading | Services instantiated on first access |
| Circular Dependency Handling | Setter injection via `wireAllServices()` |
| Testability | Services can be mocked via `registerService()` |

**PHP-DI Configuration:**

The container configuration is defined in `/src/config/container.php`:

```php
// Interface to implementation bindings
ContactServiceInterface::class => get(ContactService::class),
TransactionServiceInterface::class => get(TransactionService::class),

// Autowired repositories (receive PDO automatically)
AddressRepository::class => autowire(),
ContactRepository::class => autowire(),
```

**Initialization Flow:**

```
Application::getInstance()
    -> loadServiceContainer()
        -> ServiceContainer::getInstance()
            -> buildPhpDiContainer() (lazy, on first DI access)
    -> services->wireAllServices()
        -> Initialize core services
        -> wireCircularDependencies()
```

**Accessing Services via PSR-11:**

```php
// Traditional getter method (still supported)
$contactService = $container->getContactService();

// PSR-11 interface method
$contactService = $container->get(ContactServiceInterface::class);

// Check if service exists
if ($container->has(ContactServiceInterface::class)) {
    // ...
}
```

### Service Catalog

| Service | Purpose | Key Dependencies |
|---------|---------|------------------|
| `TransactionService` | **Facade** for transaction operations, delegates to specialized services | BalanceService, ChainVerificationService, TransactionValidationService, TransactionProcessingService, SendOperationService, P2pService, ContactService, SyncTriggerProxy |
| `BalanceService` | Balance calculations and currency conversions | BalanceRepo, TransactionContactRepo, AddressRepo, CurrencyUtility |
| `ChainVerificationService` | Transaction chain integrity verification | TransactionChainRepo, SyncTriggerProxy |
| `TransactionValidationService` | Transaction validation with proactive sync | TransactionRepo, ContactRepo, ValidationUtility, SyncTriggerProxy, TransactionService |
| `TransactionProcessingService` | Transaction processing with atomic claiming; updates P2P sender address on relay when actual transaction sender differs from stored sender | TransactionRepo, TransactionRecoveryRepo, TransactionChainRepo, P2pRepo, BalanceRepo, SyncTriggerProxy, P2pService, HeldTransactionService |
| `SendOperationService` | Send orchestration with distributed locking | TransactionRepo, AddressRepo, P2pRepo, TransportUtility, LockingService, ContactService, P2pService, SyncTriggerProxy, TransactionService, TransactionChainRepo, ChainDropService |
| `P2pService` | Peer-to-peer message routing; mega-batch broadcast via `sendMultiBatch()` with coalesce delay, handles fast/best-fee mode (fast forced for Tor), tracks multi-path senders, currency-filtered contact selection, creates capacity reservations on relay, broadcasts full cancel downstream to all contacts via `broadcastFullCancelForHash()` | ContactRepo, P2pRepo, P2pSenderRepo, ContactCurrencyRepo, CapacityReservationRepo, TransportUtility, MessageDeliveryService |
| `Rp2pService` | Return P2P (response) message handling; candidate storage and best-fee selection with fallback iteration, rejection counting in fast mode, per-currency fee lookup, triggers route cancellation for unselected candidates | ContactRepo, Rp2pRepo, Rp2pCandidateRepo, P2pRepo, ContactCurrencyRepo, SendOperationService (via P2pTransactionSenderInterface), RouteCancellationService |
| `RouteCancellationService` | Actively cancels unselected P2P routes after best-fee selection; releases capacity reservations, sends `route_cancel` messages; handles incoming cancellations with two modes: partial (acknowledge only, multi-route safe) and full cancel (cancel P2P, release reservation, propagate downstream); randomized hop budget via geometric distribution (integrated into P2pService originator hop calculation via static `computeHopBudget()`); controllable via `EIOU_HOP_BUDGET_RANDOMIZED` env var | P2pService (via P2pServiceInterface), CapacityReservationRepo, RouteCancellationRepo, P2pRepo |
| `ContactService` | Contact management facade | ContactRepo, AddressRepo, TransactionContactRepo, SyncTriggerProxy, MessageDeliveryService |
| `ContactManagementService` | Contact CRUD and blocking | ContactRepo, ContactSyncService |
| `ContactSyncService` | Contact-level sync operations | ContactRepo, SyncTriggerProxy, MessageDeliveryService |
| `ContactStatusService` | Contact ping/status checking; auto-creates pending contacts for unknown pings (wallet restore scenario) | ContactRepo, TransactionRepo, SyncTriggerProxy, TransactionChainRepo, RateLimiterService, ChainDropService |
| `SyncService` | Transaction chain synchronization | ContactRepo, AddressRepo, P2pRepo, Rp2pRepo, TransactionRepo, TransactionChainRepo, TransactionContactRepo, BalanceRepo, UtilityContainer, HeldTransactionService, BackupService |
| `ChainDropService` | Chain drop agreement protocol with auto-accept balance guard | ChainDropProposalRepo, TransactionChainRepo, TransactionRepo, ContactRepo, UtilityContainer, BackupService, SyncTriggerProxy, BalanceRepo |
| `ChainOperationsService` | Centralized chain verification/repair | SyncService |
| `MessageDeliveryService` | Reliable delivery with retry/DLQ | MessageDeliveryRepo, DeadLetterQueueRepo, TransportUtility |
| `HeldTransactionService` | Pending transaction queue for sync | HeldTransactionRepo, TransactionRepo, TransactionChainRepo (uses EventDispatcher for sync notifications) |
| `CleanupService` | Expired message/proposal cleanup; releases expired capacity reservations, prunes old cancellation records | P2pRepo, Rp2pRepo, TransactionRepo, ChainDropService, CapacityReservationRepo, RouteCancellationRepo |
| `BackupService` | Encrypted backup and restore | TransactionRepo |
| `WalletService` | Wallet information access | UserContext |
| `MessageService` | Incoming message routing | ContactRepo, BalanceRepo, P2pRepo, TransactionRepo, TransactionContactRepo, SyncTriggerProxy, ChainDropService |
| `ApiAuthService` | API authentication (HMAC-SHA256) | ApiKeyRepo |
| `ApiKeyService` | API key management | ApiKeyRepo |
| `TransactionRecoveryService` | Stuck transaction recovery | TransactionRecoveryRepo |
| `RateLimiterService` | Request rate limiting | RateLimiterRepo |
| `DatabaseLockingService` | Distributed locking via MariaDB `GET_LOCK()` / `RELEASE_LOCK()` | PDO |
| `CliService` | CLI output formatting | ContactRepo, BalanceRepo, TransactionRepo + setter: ContactCreditRepo, P2pRepo |
| `DebugService` | Debug logging and diagnostics | DebugRepo |

### Utility Services

The `UtilityServiceContainer` provides helper services for common operations:

| Utility | Purpose |
|---------|---------|
| `TimeUtilityService` | Timestamp formatting, timezone handling |
| `CurrencyUtilityService` | Amount conversion, formatting |
| `ValidationUtilityService` | Input validation, sanitization |
| `GeneralUtilityService` | Miscellaneous helpers shared across services (ServiceContainer + UserContext access) |
| `TransportUtilityService` | HTTP/HTTPS/Tor message transport; parallel batch sends via `curl_multi` with per-protocol concurrency limits |

### Circular Dependency Resolution

Some services have circular dependencies (e.g., TransactionService needs SyncService
and vice versa). These are resolved via setter injection:

```php
// In ServiceContainer::wireCircularDependencies()

// Core sync-related dependencies (via SyncTriggerInterface proxy for loose coupling)
$this->services['TransactionService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);

// Contact services
$this->services['ContactManagementService']->setContactSyncService($this->services['ContactSyncService']);
$this->services['ContactSyncService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['ContactSyncService']->setMessageDeliveryService($this->services['MessageDeliveryService']);
$this->services['ContactService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['ContactStatusService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['ContactStatusService']->setTransactionChainRepository($this->getTransactionChainRepository());
$this->services['ContactStatusService']->setRateLimiterService($this->services['RateLimiterService']);

// MessageService handles sync requests and chain drop messages
$this->services['MessageService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['MessageService']->setChainDropService($this->services['ChainDropService']);

// RP2P uses P2pTransactionSenderInterface to break circular dependency
$this->services['Rp2pService']->setP2pTransactionSender($this->services['SendOperationService']);

// TransactionService facade receives P2p, Contact, and 5 specialized services
$this->services['TransactionService']->setP2pService($this->services['P2pService']);
$this->services['TransactionService']->setContactService($this->services['ContactService']);
$this->services['TransactionService']->setBalanceService($this->services['BalanceService']);
$this->services['TransactionService']->setChainVerificationService($this->services['ChainVerificationService']);
$this->services['TransactionService']->setTransactionValidationService($this->services['TransactionValidationService']);
$this->services['TransactionService']->setTransactionProcessingService($this->services['TransactionProcessingService']);
$this->services['TransactionService']->setSendOperationService($this->services['SendOperationService']);

// Specialized services use SyncTriggerInterface proxy
$this->services['ChainVerificationService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['TransactionValidationService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['TransactionValidationService']->setTransactionService($this->services['TransactionService']);
$this->services['TransactionProcessingService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['TransactionProcessingService']->setP2pService($this->services['P2pService']);
$this->services['TransactionProcessingService']->setHeldTransactionService($this->services['HeldTransactionService']);
$this->services['SendOperationService']->setContactService($this->services['ContactService']);
$this->services['SendOperationService']->setP2pService($this->services['P2pService']);
$this->services['SendOperationService']->setSyncTrigger($this->getSyncServiceProxy());
$this->services['SendOperationService']->setTransactionService($this->services['TransactionService']);
$this->services['SendOperationService']->setTransactionChainRepository($this->getTransactionChainRepository());
$this->services['SendOperationService']->setChainDropService($this->services['ChainDropService']);

// Chain operations and backup recovery
$this->services['ChainOperationsService']->setSyncService($this->services['SyncService']);
$this->services['SyncService']->setBackupService($this->getBackupService());
$this->services['ChainDropService']->setBackupService($this->getBackupService());
$this->services['CleanupService']->setChainDropService($this->services['ChainDropService']);

// CliService - repositories for info command display (fee earnings, available credit)
$this->services['CliService']->setContactCreditRepository($this->getContactCreditRepository());
$this->services['CliService']->setP2pRepository($this->getP2pRepository());
```

---

## Dependency Injection Patterns

The codebase uses several patterns to manage service dependencies while avoiding tight
coupling and circular dependencies.

### Interface Segregation

Services depend on focused interfaces rather than concrete implementations. This reduces
coupling and makes circular dependencies easier to break.

**Key Interfaces:**

| Interface | Purpose | Implementing Service |
|-----------|---------|---------------------|
| `SyncTriggerInterface` | Minimal sync operations for chain repair | `SyncService`, `SyncServiceProxy` |
| `P2pTransactionSenderInterface` | P2P transaction sending | `P2pService` |
| `ChainOperationsInterface` | Chain verification and repair | `ChainOperationsService` |
| `LockingServiceInterface` | Distributed locking | `DatabaseLockingService` |
| `EventDispatcherInterface` | Event-driven communication | `EventDispatcher` |
| `RouteCancellationServiceInterface` | Route cancellation and hop budget | `RouteCancellationService` |

**Example - SyncTriggerInterface:**

```php
// SyncTriggerInterface defines only the methods other services need
interface SyncTriggerInterface
{
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array;
    public function syncContactBalance(string $contactPubkey): array;
    public function syncSingleContact($contactAddress, $echo = 'SILENT'): bool;
    public function syncReaddedContact(string $contactAddress, string $contactPublicKey): array;
}

// Services depend on the interface, not the concrete SyncService
class HeldTransactionService
{
    private ?SyncTriggerInterface $syncService = null;

    public function setSyncService(SyncTriggerInterface $syncService): void {
        $this->syncService = $syncService;
    }
}
```

### Event-Driven Communication

The `EventDispatcher` enables loose coupling by allowing services to communicate via
events instead of direct dependencies.

**SyncEvents Constants:**

| Event | When Dispatched |
|-------|-----------------|
| `SYNC_COMPLETED` | After successful sync operation |
| `SYNC_FAILED` | When sync operation fails |
| `CHAIN_GAP_DETECTED` | When missing transactions detected |
| `BALANCE_SYNCED` | After contact balance sync |
| `CONTACT_SYNCED` | After contact sync completes |
| `CHAIN_CONFLICT_RESOLVED` | When chain conflict is resolved |

**ChainDropEvents Constants:**

| Event | When Dispatched |
|-------|-----------------|
| `CHAIN_DROP_PROPOSED` | When a chain drop is proposed to a contact |
| `CHAIN_DROP_ACCEPTED` | When a chain drop proposal is accepted |
| `CHAIN_DROP_REJECTED` | When a chain drop proposal is rejected |
| `CHAIN_DROP_EXECUTED` | When a chain drop has been fully executed locally |
| `TRANSACTION_RECOVERED_FROM_BACKUP` | When a missing transaction is recovered from a database backup instead of requiring a chain drop |

**Usage Example:**

```php
// Subscribe to events (typically in service constructor or bootstrap)
EventDispatcher::getInstance()->subscribe(SyncEvents::SYNC_COMPLETED, function($data) {
    $contactPubkey = $data['contact_pubkey'];
    $syncedCount = $data['synced_count'];
    // React to sync completion...
});

// Dispatch events (in the service performing the action)
EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_COMPLETED, [
    'contact_pubkey' => $pubkey,
    'synced_count' => 5,
    'success' => true
]);
```

### Lazy Proxy Pattern

`SyncServiceProxy` delays service resolution until first use, breaking circular
dependencies at construction time.

```php
// SyncServiceProxy delays resolution until a method is called
class SyncServiceProxy implements SyncTriggerInterface
{
    private ServiceContainer $container;
    private ?SyncService $instance = null;

    public function __construct(ServiceContainer $container) {
        $this->container = $container;
    }

    private function getService(): SyncService {
        if ($this->instance === null) {
            $this->instance = $this->container->getSyncService();
        }
        return $this->instance;
    }

    public function syncTransactionChain(...): array {
        return $this->getService()->syncTransactionChain(...);
    }
}
```

**When to Use Proxies:**

- Service A depends on Service B at runtime but not at construction
- Breaking a circular dependency where setter injection is not suitable
- Deferring expensive service initialization

### Constructor vs Setter Injection Guidelines

| Use Case | Pattern | Example |
|----------|---------|---------|
| Required dependencies | Constructor injection | Repositories, utilities, PDO |
| Optional dependencies | Setter injection with null default | Debug services |
| Circular dependencies | Setter injection | SyncService <-> HeldTransactionService |
| Late-bound dependencies | Lazy proxy | SyncServiceProxy |
| Deferred repo wiring | Setter injection with null guard | CliService (ContactCreditRepo, P2pRepo) |

**Constructor Injection (Required - No Fallbacks):**

Dependencies must be explicitly provided via constructor injection. There are no
automatic fallbacks to ServiceContainer - if a dependency is not provided, the
code will fail fast with a clear error.

```php
class SettingsController
{
    private Session $session;
    private ?PDO $pdo;

    // PDO must be injected - no ServiceContainer fallback
    public function __construct(Session $session, ?PDO $pdo = null)
    {
        $this->session = $session;
        $this->pdo = $pdo;
    }

    private function getPdoConnection(): ?PDO
    {
        return $this->pdo;  // Returns injected value only
    }
}
```

**Service Layer Constructor Injection:**

```php
class BalanceService
{
    public function __construct(
        BalanceRepository $balanceRepository,          // Required
        TransactionContactRepository $transactionContactRepository,
        AddressRepository $addressRepository,
        CurrencyUtilityService $currencyUtility
    ) {
        // All dependencies available immediately
    }
}
```

**Setter Injection (For Circular Dependencies):**

```php
class TransactionService
{
    private ?SyncTriggerInterface $syncTrigger = null;

    public function setSyncTrigger(SyncTriggerInterface $syncTrigger): void {
        $this->syncTrigger = $syncTrigger;
    }

    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException(
                'SyncTrigger not injected. Call setSyncTrigger() or ensure ' .
                'ServiceContainer::wireCircularDependencies() is called.'
            );
        }
        return $this->syncTrigger;
    }
}
```

### Dependency Graph

```
                         +------------------+
                         | ServiceContainer |
                         +--------+---------+
                                  |
     +-------------+--------------+--------------+------------+
     |             |              |              |            |
+----v----+  +-----v------+ +----v-----+  +------v----+ +-----v----+
|  Sync   |  |Transaction |  | Contact |  |  Message  | | Cleanup  |
| Service |  |  Service   |  | Service |  |  Service  | | Service  |
+----+----+  +-----+------+ +----+-----+  +-----+-----+ +-----+----+
     |             |             |              |             |
     |    +--------+--------+    |              |             |
     |    |        |        |    |              |             |
+----v--+ v   +----v---+ +--v----v-+   +--------v----+ +------v----+
| Held  | |   |  Send  | |  Chain  |   |  ChainDrop  | | Backup    |
|  Tx   | |   |  Op    | |  Verif  |   |  Service    | | Service   |
|Service| |   |Service | | Service |   +-------------+ +-----------+
+-------+ |   +----+---+ +--------+          ^               ^
          |        |                         |               |
     +----v----+   +--- ChainDropService     +--- Setter     |
     | Balance |   +--- SyncTriggerProxy     +--- Setter ----+
     | Service |
     +---------+

Legend:
  -----> Constructor injection
  ··· > Setter injection via SyncTriggerInterface proxy (loose coupling)
```

---

## Circular Dependency Management

### Why Setter Injection Exists

Some services have dependencies that require setter injection due to initialization
order constraints. Most circular dependencies have been eliminated:

| Dependency | Pattern | Notes |
|------------|---------|-------|
| SyncService -> HeldTransactionService | Setter injection | Sync notifies held transaction service |
| CliService -> ContactCreditRepo, P2pRepo | Setter injection | Info command displays fee earnings and available credit |

### How wireCircularDependencies() Works

`ServiceContainer::wireCircularDependencies()` is called after all services are
constructed to wire up setter-injected dependencies:

```php
public function wireCircularDependencies(): void {
    // Core sync-related dependencies (SyncTriggerInterface via proxy)
    $this->services['TransactionService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
    // Note: HeldTransactionService uses EventDispatcher for sync notifications (no setter injection)

    // Contact services
    $this->services['ContactManagementService']->setContactSyncService($this->services['ContactSyncService']);
    $this->services['ContactSyncService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['ContactSyncService']->setMessageDeliveryService($this->services['MessageDeliveryService']);
    $this->services['ContactService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['ContactStatusService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['ContactStatusService']->setChainDropService($this->services['ChainDropService']);

    // Message service handles sync and chain drop routing
    $this->services['MessageService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['MessageService']->setChainDropService($this->services['ChainDropService']);

    // RP2P uses P2pTransactionSenderInterface (breaks circular dependency)
    $this->services['Rp2pService']->setP2pTransactionSender($this->services['SendOperationService']);

    // Specialized services use SyncTriggerInterface via proxy
    $this->services['ChainVerificationService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['TransactionProcessingService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['SendOperationService']->setContactService($this->services['ContactService']);
    $this->services['SendOperationService']->setChainDropService($this->services['ChainDropService']);

    // Chain operations and backup recovery
    $this->services['ChainOperationsService']->setSyncService($this->services['SyncService']);
    $this->services['SyncService']->setBackupService($this->getBackupService());
    $this->services['ChainDropService']->setBackupService($this->getBackupService());
    $this->services['ChainDropService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['ChainDropService']->setBalanceRepository($this->getBalanceRepository());
    $this->services['CleanupService']->setChainDropService($this->services['ChainDropService']);

    // CliService - repositories for info command display (fee earnings, available credit)
    $this->services['CliService']->setContactCreditRepository($this->getContactCreditRepository());
    $this->services['CliService']->setP2pRepository($this->getP2pRepository());
}
```

**Initialization Order:**

```
1. Application::getInstance()
2. -> loadServiceContainer()
3. -> ServiceContainer::getInstance()
4. -> wireAllServices()
       -> Initialize all services (constructor injection)
       -> wireCircularDependencies() (setter injection)
```

**Important:** Every service that receives setter injection in `wireCircularDependencies()`
must be initialized in `wireAllServices()` first. The wiring uses `isset()` guards, so
if a service hasn't been created yet, the setter injection is silently skipped and the
service will have null dependencies at runtime.

### Future Roadmap for Eliminating Cycles

The codebase has significantly reduced circular dependencies:

| Strategy | Status | Services/Details |
|----------|--------|------------------|
| Lazy proxy pattern | ✅ Available | SyncServiceProxy for optional use |


**All SyncService Dependencies Now Use SyncTriggerInterface:**

**Only Remaining Direct SyncService Usage:**

- `SyncService -> HeldTransactionService` (one-way setter, not circular)
- `ChainOperationsService -> SyncService` (for chain repair coordination)

### CI Script for Cycle Detection

The `circularDependencyCheck.sh` script detects circular dependencies via static analysis:

```bash
# Run from eiou-docker root
cd tests/testfiles
./circularDependencyCheck.sh           # Normal output
./circularDependencyCheck.sh --verbose # Detailed dependency graph
```

**How It Works:**

1. Parses all PHP service files in `/files/src/services/`
2. Extracts constructor dependencies (type-hinted parameters)
3. Extracts setter injection dependencies (`set*Service` methods)
4. Builds a dependency graph
5. Uses DFS to detect cycles
6. Reports found cycles with dependency chains

**Exit Codes:**

| Code | Meaning |
|------|---------|
| 0 | No circular dependencies found |
| 1 | Circular dependencies detected |

**Sample Output:**

```
Circular Dependency Check
=========================

Analyzing files in /files/src/services...
Found 25 service files

Detecting cycles...

No circular dependencies detected in core services.

All major cycles have been eliminated using:
  - SyncTriggerInterface + SyncServiceProxy (ContactService, MessageService, TransactionService)
  - P2pTransactionSenderInterface (Rp2pService)
  - EventDispatcher + SyncEvents (HeldTransactionService)
```

---

## Message Processing Pipeline

### Processor Architecture

Four background processors handle asynchronous message processing. Each extends
`AbstractMessageProcessor` which provides:

- **Adaptive Polling**: Adjusts polling interval based on workload
- **Signal Handling**: Graceful shutdown on SIGTERM, reload on SIGHUP
- **Lockfile Management**: Ensures single instance per processor type
- **Statistics Logging**: Periodic throughput reporting

```
                    +---------------------------+
                    | AbstractMessageProcessor  |
                    +---------------------------+
                    | - poller: AdaptivePoller  |
                    | - shouldStop: bool        |
                    | - lockfile: string        |
                    +---------------------------+
                    | + run()                   |
                    | + handleShutdownSignal()  |
                    | # processMessages()       |  <- Abstract
                    | # getProcessorName()      |  <- Abstract
                    +---------------------------+
                              ^
          +-------------------+-------------------+
          |         |                   |         |
+---------+-+ +-----+-------+ +---------+-+ +-----+-------+
|Transaction| |     P2P     | |  Cleanup  | |ContactStatus|
| Processor | |  Processor  | | Processor | |  Processor  |
+-----------+ +-------------+ +-----------+ +-------------+
```

### TransactionMessageProcessor

Processes pending outbound transactions with fast polling for time-critical operations.

| Setting | Value |
|---------|-------|
| Min Interval | 100ms |
| Max Interval | 5000ms (5s) |
| Idle Interval | 2000ms (2s) |
| Log Interval | 60 seconds |
| Lockfile | `/tmp/transactionmessages_lock.pid` |

**Processing Loop:**
```php
protected function processMessages(): int {
    return $this->transactionService->processPendingTransactions();
}
```

### P2pMessageProcessor

The P2P processor uses a coordinator+worker architecture for parallel P2P broadcast.
The coordinator (`P2pMessageProcessor`) polls for queued P2P messages and spawns
independent worker processes (`P2pWorker.php`) for each one via `proc_open`.

| Setting | Value |
|---------|-------|
| Min Interval | 100ms |
| Max Interval | 5000ms (5s) |
| Idle Interval | 2000ms (2s) |
| Log Interval | 60 seconds |
| Lockfile | `/tmp/p2pmessages_lock.pid` |

**Coordinator+Worker Model:**

```
P2pMessageProcessor (Coordinator)
    |
    +-- Poll for queued P2P messages
    +-- For each queued P2P:
    |     +-- Check per-transport worker limit (HTTP: 50, Tor: 5)
    |     +-- Spawn P2pWorker.php via proc_open
    |     +-- Track worker by transport type independently
    |
    +-- Reap finished workers, log results
    +-- Recover stuck 'sending' P2Ps with dead worker PIDs (every 60s)
```

**Worker Lifecycle:**

Each `P2pWorker.php` process handles one P2P message end-to-end:

1. **Claim:** Atomically transitions P2P from `queued` → `sending` via
   `P2pRepository::claimQueuedP2p()`, recording `sending_started_at` and
   `sending_worker_pid` for crash recovery
2. **Broadcast:** Calls `P2pService::processSingleP2p()` which broadcasts to all
   accepted contacts via its own `curl_multi` session
3. **Complete:** Transitions P2P from `sending` → `sent`

**Worker Pool Configuration:**

| Setting | Value | Notes |
|---------|-------|-------|
| `P2P_MAX_WORKERS` (HTTP) | 50 | Per-transport concurrent workers |
| `P2P_MAX_WORKERS` (HTTPS) | 50 | Per-transport concurrent workers |
| `P2P_MAX_WORKERS` (Tor) | 5 | Lower limit to prevent SOCKS5 circuit overload |
| `P2P_SENDING_TIMEOUT_SECONDS` | 300 | Crash recovery threshold for stuck workers |
| Override | `EIOU_P2P_MAX_WORKERS` env var | Per-deployment tuning |

**Crash Recovery:**

The coordinator runs a recovery sweep every 60 seconds, finding P2P messages stuck
in `sending` status beyond `P2P_SENDING_TIMEOUT_SECONDS`. If the worker PID is no
longer alive, `P2pRepository::recoverStuckP2p()` resets the P2P to `queued` for
re-processing.

**P2P Status Flow:**

```
queued → sending → sent → found → completed
                      ↘ expired / cancelled
```

**Mega-Batch Processing (within each worker):**

Each worker's `processSingleP2p()` uses a 3-phase mega-batch approach to broadcast
to all contacts in a single `curl_multi` call:

1. **Phase 1 — Collect:** Builds per-contact payloads and accumulates them into a
   flat `$megaBatchSends` array. A coalesce delay (`P2P_QUEUE_COALESCE_MS`, 2000ms)
   groups concurrent P2Ps arriving within a short window.
2. **Phase 2 — Fire:** Calls `TransportUtilityService::sendMultiBatch($megaBatchSends)`
   which executes all sends in parallel via `curl_multi` with a sliding-window concurrency
   limit (see [Transport Concurrency Control](#transport-concurrency-control)).
3. **Phase 3 — Map Results:** Maps each `curl_multi` result back to its originating P2P
   by key, processes responses, and updates P2P status accordingly.

### CleanupMessageProcessor

Removes expired P2P and transaction messages with slower polling (less time-critical).

| Setting | Value |
|---------|-------|
| Min Interval | 1000ms (1s) |
| Max Interval | 30000ms (30s) |
| Idle Interval | 10000ms (10s) |
| Log Interval | 300 seconds (5 min) |
| Lockfile | `/tmp/cleanupmessages_lock.pid` |

**Processing Loop:**
```php
protected function processMessages(): int {
    return $this->cleanupService->processCleanupMessages();
}
```

### ContactStatusProcessor

Periodically pings accepted contacts to check online status and validate transaction
chains. Operates in 5-minute cycles.

| Setting | Value |
|---------|-------|
| Cycle Interval | 300000ms (5 min) |
| Max Interval | 1800000ms (30 min) |
| Log Interval | 60 seconds |
| Lockfile | `/tmp/contact_status.pid` |

**Features:**

- Pings one contact per iteration to spread load
- Updates contact online status (online/partial/offline/unknown)
- Validates per-currency transaction chain integrity (`prevTxidsByCurrency` maps)
- Triggers sync if any currency's chain heads don't match
- Auto-proposes chain drop if sync detects mutual gaps (both sides missing same transaction)
- Auto-creates pending contact records for unknown incoming pings (wallet restore scenario)
- Respects `EIOU_CONTACT_STATUS_ENABLED` environment variable

**Wallet Restore Contact Re-establishment:**

When a node receives a ping from an address that is not in its contacts database at all,
`ContactStatusService` auto-creates a pending contact record and inserts the address. This
handles the wallet restore scenario where a user restores from a seed phrase only (no backups)
and their prior contacts ping them. After the pending contact is created, sync is triggered
to restore the transaction chain from the contact. Once sync restores transactions, the
pending contact appears in the GUI with a "Prior Contact" badge indicating it has prior
transaction history. The user must then re-accept the contact by providing a name, fee,
credit limit, and currency before normal operations can resume.

### Watchdog Monitoring

The watchdog runs every 30 seconds and monitors processor health:

```
+------------------+
|    Watchdog      |
| (30s interval)   |
+------------------+
        |
        +-- Shutdown flag exists?
        |       |-- Yes: Skip all checks (processors intentionally stopped)
        |       |-- Flag just cleared? Reset all restart counters
        |
        +-- Check P2P PID alive?
        |       |-- No: Restart (if < 10 restarts, > 60s cooldown)
        |
        +-- Check Transaction PID alive?
        |       |-- No: Restart (if < 10 restarts, > 60s cooldown)
        |
        +-- Check Cleanup PID alive?
        |       |-- No: Restart (if < 10 restarts, > 60s cooldown)
        |
        +-- Check ContactStatus PID alive? (if enabled)
        |       |-- No: Restart (if < 10 restarts, > 60s cooldown)
        |
        +-- Tor restart signal file? (/tmp/tor-restart-requested)
        |       |-- Yes: Immediate Tor restart (within ~30s, bypasses 5-min cycle)
        |       |-- Created by TransportUtilityService on SOCKS5 proxy failure
        |
        +-- Tor self-health check (every 5 minutes)
                |-- Curl own .onion via SOCKS5 proxy
                |-- Failure: Fix HS dir permissions, restart Tor
                |-- (max 5 restarts, 300s cooldown, resets on recovery)
```

**Tor SOCKS5 Immediate Recovery:**

When `TransportUtilityService::send()` encounters a SOCKS5 proxy connection failure
during message delivery, it creates the signal file `/tmp/tor-restart-requested`. The
watchdog checks for this file every 30 seconds (its normal cycle) and triggers an
immediate Tor restart — reducing recovery time from up to 5 minutes (periodic health
check) to ~30 seconds. The Tor restart counter resets after a 5-minute cooldown to
prevent permanent Tor unavailability when recovery requires more than 5 restart attempts.

**Shutdown Flag Lifecycle:**

The shutdown flag (`/tmp/eiou_shutdown.flag`) coordinates between the PHP CLI commands and the bash watchdog:

| Event | Action |
|-------|--------|
| `eiou shutdown` | Creates flag, sends SIGTERM to processors, cleans PID files |
| Watchdog cycle | Checks for flag — if present, skips all processor checks |
| `eiou start` | Removes flag |
| Watchdog (after flag removal) | Detects transition, resets restart counters, resumes monitoring |
| Container startup (`startup.sh`) | Removes stale flag from previous container lifecycle |
| Docker SIGTERM (container stop) | `graceful_shutdown()` creates flag before stopping processors |

**Watchdog Configuration:**

| Setting | Value |
|---------|-------|
| Check Interval | 30 seconds |
| Restart Cooldown | 60 seconds |
| Max Restarts | 10 per processor |
| Tor Check Interval | 300 seconds (5 minutes) |
| Tor Restart Cooldown | 300 seconds |
| Tor Max Restarts | 5 |

---

## Data Layer

### Database Tables

Each node maintains a MariaDB database with these primary tables:

| Table | Purpose |
|-------|---------|
| `contacts` | Known peers with public keys, addresses, status |
| `addresses` | Contact address variants (HTTP, HTTPS, Tor) |
| `balances` | Current balance with each contact |
| `debug` | Debug log entries and diagnostics |
| `transactions` | Transaction history and chain links |
| `p2p` | Outbound P2P routing messages |
| `rp2p` | Return P2P (response) messages |
| `message_delivery` | Delivery tracking with retry state |
| `dead_letter_queue` | Failed messages for manual review |
| `delivery_metrics` | Message delivery statistics |
| `held_transactions` | Transactions pending sync completion |
| `api_keys` | API authentication keys |
| `api_request_log` | API request audit trail |
| `rate_limits` | Rate limiting state |
| `chain_drop_proposals` | Mutual chain drop agreement tracking |
| `p2p_senders` | Multi-path upstream sender tracking for RP2P forwarding |
| `p2p_relayed_contacts` | Contacts that returned `already_relayed` during P2P broadcast (used by two-phase relay selection in best-fee mode) |
| `rp2p_candidates` | Best-fee RP2P candidate responses awaiting selection |
| `contact_credit` | Per-contact, per-currency available credit received from pong (UNIQUE on `pubkey_hash, currency`) |
| `contact_currencies` | Per-contact, per-currency config (fee, credit limit) with direction tracking (`incoming`/`outgoing` = who initiated the relationship) |
| `capacity_reservations` | Credit reserved at each relay hop during P2P routing (base_amount and total_amount including fees), status: active/released/committed |
| `route_cancellations` | Audit trail for route cancellation messages sent to unselected P2P candidates after best-fee selection |

### Repository Pattern

Each table has a corresponding repository class extending `AbstractRepository`:

```
AbstractRepository
    |
    +-- AddressRepository
    +-- BalanceRepository
    +-- ContactRepository
    +-- TransactionRepository (core CRUD operations)
    |       |
    |       +-- TransactionStatisticsRepository (aggregations, statistics)
    |       +-- TransactionChainRepository (chain navigation, conflict resolution)
    |       +-- TransactionRecoveryRepository (stuck transaction recovery)
    |       +-- TransactionContactRepository (contact-based queries)
    +-- P2pRepository
    +-- P2pSenderRepository
    +-- P2pRelayedContactRepository
    +-- Rp2pRepository
    +-- Rp2pCandidateRepository
    +-- ContactCreditRepository
    +-- MessageDeliveryRepository
    +-- DeadLetterQueueRepository
    +-- DeliveryMetricsRepository
    +-- HeldTransactionRepository
    +-- ChainDropProposalRepository
    +-- ApiKeyRepository
    +-- DebugRepository
    +-- RateLimiterRepository
```

**Transaction Repository Specialization:**

The transaction data access layer is split into specialized repositories for maintainability:

| Repository | Responsibility |
|------------|----------------|
| `TransactionRepository` | Core CRUD, basic queries, transaction creation |
| `TransactionStatisticsRepository` | Balance aggregations, transaction counts, statistics |
| `TransactionChainRepository` | Chain traversal, prev_txid lookups, conflict resolution |
| `TransactionRecoveryRepository` | Finding/updating stuck transactions for recovery |
| `TransactionContactRepository` | Queries filtered by contact relationships |

All specialized repositories use the `QueryBuilder` trait for shared query building functionality.

**Supporting Classes:**

| Class | Location | Purpose |
|-------|----------|---------|
| `TransactionFormatter` | `/src/formatters/` | Output formatting for CLI and API responses |
| `QueryBuilder` | `/src/database/traits/` | Shared SQL building and parameter handling |

**AbstractRepository Features:**

| Feature | Description |
|---------|-------------|
| PDO Injection | Accepts PDO via constructor or creates from config |
| Prepared Statements | All queries use parameterized statements |
| Transaction Support | Begin/commit/rollback helpers |
| Error Handling | Exceptions logged via Logger |

**Repository Access:**

```php
$container = ServiceContainer::getInstance();
$contactRepo = $container->getContactRepository();
$contacts = $contactRepo->getAcceptedContacts();
```

### QueryBuilder Trait

The `QueryBuilder` trait (`/src/database/traits/QueryBuilder.php`) provides shared query
building utilities used across repositories to reduce code duplication:

```php
use Eiou\Database\Traits\QueryBuilder;

class MyRepository extends AbstractRepository
{
    use QueryBuilder;
}
```

**Available Methods:**

| Method | Purpose |
|--------|---------|
| `createPlaceholders($values)` | Generate PDO placeholder string (?, ?, ?) for IN clauses |
| `buildInClauseParams($values, $repeatCount, $additionalParams)` | Build parameters for queries with multiple IN clauses |
| `getUserAddressesOrNull()` | Get user addresses or null if empty (early return pattern) |
| `executeSelectAll($query, $params)` | Execute query returning all rows as associative array |
| `executeSelectOne($query, $params)` | Execute query returning single row or null |
| `buildUserTransactionQuery(...)` | Build user transaction query with address filtering |
| `buildInClause($values)` | Build complete IN clause like "IN (?,?,?)" |
| `buildWhereClause($conditions)` | Build WHERE clause from array of conditions |
| `buildOrderByClause($columns)` | Build ORDER BY clause with direction support |

**Usage Examples:**

```php
// Create placeholders for IN clause
$placeholders = $this->createPlaceholders($userIds);
// Result: "?,?,?" for 3 items

// Build complete IN clause
$inClause = $this->buildInClause($addresses);
// Result: "IN (?,?,?)"

// Build WHERE clause from conditions
$where = $this->buildWhereClause([
    'status' => 'active',           // "status = ?"
    'amount >' => 100,              // "amount > ?"
    'sender_address IN (?,?)'       // Raw SQL (numeric key)
]);
// Result: "status = ? AND amount > ? AND sender_address IN (?,?)"

// Build ORDER BY clause
$orderBy = $this->buildOrderByClause([
    'timestamp' => 'DESC',
    'id'                            // Defaults to ASC
]);
// Result: "timestamp DESC, id"

// Execute queries with helper methods
$results = $this->executeSelectAll($query, $params);
$row = $this->executeSelectOne($query, $params);
```

This trait centralizes common query patterns to reduce code duplication across repositories.

### Utils Infrastructure

The `/src/utils/` directory provides cross-cutting infrastructure used throughout the
application:

| Utility | Purpose |
|---------|---------|
| `Logger` | File-based logging with severity levels, log rotation, and context tagging |
| `SecureLogger` | Security-aware logger that redacts sensitive data (keys, mnemonics, auth codes) from log output |
| `AdaptivePoller` | Dynamic polling interval adjustment based on workload — ramps down to min interval when busy, ramps up to max interval when idle; used by all background processors |
| `AddressValidator` | Validates and normalizes node addresses (HTTP, HTTPS, Tor `.onion` formats) |
| `InputValidator` | General-purpose input sanitization and validation for CLI and API inputs |
| `Security` | Cryptographic helpers — message signing, signature verification, hash generation using secp256k1 ECDSA |
| `SecureSeedphraseDisplay` | Secure terminal output for seed phrases — clears screen, displays temporarily, handles clipboard-safe formatting |

---

## P2P Networking

### P2P Routing Overview

P2P routing enables transactions to reach recipients through intermediate nodes when
no direct connection exists. The system supports two routing modes:

| Mode | Flag | Internal | Behavior |
|------|------|----------|----------|
| **Fast** (default) | None | `fast=1` | First RP2P response wins; lowest latency |
| **Best-Fee** (experimental) | `--best` | `fast=0` | Collects all responses, selects lowest accumulated fee (forced to fast for Tor unless `EIOU_TOR_FORCE_FAST=false`) |

```
      ALICE                   BOB                    CAROL                   EVE
   (Sender)              (Intermediary)          (Intermediary)          (Recipient)
      |                       |                       |                       |
      |   P2P Request         |                       |                       |
      |  level=201            |                       |                       |
      |  max=207              |                       |                       |
      |---------------------->|                       |                       |
      |                       |   P2P Request         |                       |
      |                       |  level=202            |                       |
      |                       |  max=207              |                       |
      |                       |---------------------->|                       |
      |                       |                       |   P2P Request         |
      |                       |                       |  level=203            |
      |                       |                       |  max=207              |
      |                       |                       |---------------------->|
      |                       |                       |                       |
      |                       |                       |  RP2P Response        |
      |                       |                       |  (accepted)           |
      |                       |                       |<----------------------|
      |                       |  RP2P Response        |                       |
      |                       |  (propagating)        |                       |
      |                       |<----------------------|                       |
      |  RP2P Response        |                       |                       |
      |  (completed)          |                       |                       |
      |<----------------------|                       |                       |
```

### Request Level Randomization

The request level is randomized to prevent network traffic analysis:

```php
// Constants for randomization
P2P_MIN_REQUEST_LEVEL_RANGE_LOW = 300
P2P_MIN_REQUEST_LEVEL_RANGE_HIGH = 700
P2P_MIN_REQUEST_LEVEL_RANDOM_LOW = 200
P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH = 500
P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW = 1
P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH = 10

// Formula
level = abs(rand(300,700) - rand(200,500)) + rand(1,10)
```

This produces unpredictable but bounded values, preventing attackers from correlating
request patterns.

### Hop Budget Randomization

The **hop budget** controls how many relay hops a P2P request can traverse. It is set
once by the originator as `maxRequestLevel = minRequestLevel + hopBudget`.

```php
// Computed by RouteCancellationService::computeHopBudget(minHops, maxHops)
$maxP2pLevel = $user->getMaxP2pLevel();                // default: 6
$minHops = max(1, floor($maxP2pLevel * HOP_BUDGET_MIN_RATIO)); // default ratio 0.5 → 3
$hopBudget = computeHopBudget($minHops, $maxP2pLevel); // range: [3, 6]

// Geometric distribution (30% stop probability per hop beyond minHops):
//   3 hops: 30%
//   4 hops: 21%
//   5 hops: 15%
//   6 hops: 34% (remainder)
```

**Key properties:**
- Only set on the originator — relays inherit `maxRequestLevel` unchanged
- `HOP_BUDGET_MIN_RATIO` (default: 0.5) prevents uselessly low budgets (1 hop = direct contacts only)
- When `EIOU_HOP_BUDGET_RANDOMIZED=false` (test default), returns `maxP2pLevel` for deterministic behavior
- Dead-end behavior: when `requestLevel >= maxRequestLevel`, the relay stores as cancelled and sends `sendCancelNotificationForHash()` upstream immediately

### P2P Message Flow

**Outbound (P2pService):**

1. User initiates transaction to unknown recipient
2. P2pService creates P2P record with randomized level and status `queued`
3. P2pMessageProcessor daemon picks up queued messages (polls every 100ms–5s)
4. Coalesce delay groups concurrent P2Ps into a single mega-batch (2000ms window)
5. Mega-batch broadcasts to accepted contacts that support the transaction's currency via `sendMultiBatch()` (`curl_multi`)
6. Concurrency-limited sliding window caps simultaneous connections per protocol
7. Each relay node queues, coalesces, and broadcasts to its own contacts (level++)
8. Process continues until recipient found or level exceeds maxRequestLevel

**Inbound Response (Rp2pService):**

1. Recipient receives P2P request
2. Recipient sends RP2P response back along route
3. Intermediaries forward RP2P (reverse path)
4. Original sender receives acceptance
5. Transaction sent along the established route

### Routing Modes

#### Fast Mode (Default)

Fast mode processes the first RP2P response immediately. When a node receives an RP2P
in fast mode, `Rp2pService::checkRp2pPossible()` calls `handleRp2pRequest()` directly.
No candidate storage or selection logic is involved.

**Characteristics:**

- Lowest latency — uses first successful route
- May not select the cheapest fee route
- Single transaction path, no waiting
- Status flow: initial → queued → sent → found → completed
- **Forced for Tor recipients:** When the destination address is `.onion`, fast mode
  is automatically enforced regardless of the `--best` flag, because best-fee mode
  generates excessive relay traffic and Tor's ~5s/hop latency amplifies the wait overhead.
  Enforced on both sender side (`prepareP2pRequestData`) and receiver side
  (`handleP2pRequest`) to prevent remote nodes from forcing best-fee over Tor.
  Can be disabled via `EIOU_TOR_FORCE_FAST=false` env variable for testing.
- **Rejection counting:** When `handleRp2pRequest()` returns false (fee too high or
  relay can't afford), `checkRp2pPossible()` increments `contacts_responded_count` for
  the sender. When all contacts have responded (all rejected or cancelled), the node
  cancels the P2P immediately and propagates cancel upstream via
  `sendCancelNotificationForHash()`, avoiding a wasted wait until expiration.

#### Best-Fee Mode (Experimental)

Best-fee mode collects RP2P responses from all paths and selects the route with the
lowest accumulated fee. Enabled with the `--best` CLI flag or the GUI checkbox.

**Phase 1 — Request Broadcasting:**

The P2P request is broadcast to all accepted contacts with `fast=0` and a `hopWait`
value that controls per-hop expiration timing. Each relay stores the request and
tracks how many contacts it forwarded to (`contacts_sent_count`).

**Phase 2 — Candidate Collection:**

When an RP2P response arrives at a node in best-fee mode, it is stored as a
candidate in the `rp2p_candidates` table rather than being processed immediately.
The node atomically increments `contacts_responded_count` on the P2P record.

```php
// In Rp2pService::checkRp2pPossible()
if ($fast == 0) {
    handleRp2pCandidate();  // Store candidate, don't process yet
} else {
    handleRp2pRequest();    // Process immediately (fast mode)
}
```

**Phase 3 — Best-Fee Selection:**

Selection is triggered when either:
1. All contacts have responded (`contacts_responded_count >= contacts_sent_count`)
2. The per-hop expiration fires (orphaned candidate recovery via `CleanupService`)

The best candidate is the one with the lowest `amount` (since accumulated fees
increase the final amount):

```sql
SELECT * FROM rp2p_candidates WHERE hash = ? ORDER BY amount ASC
```

After selection, `selectAndForwardBestRp2p()` iterates through candidates from
cheapest to most expensive and calls `handleRp2pRequest()` for each one.
If a candidate fails validation (fee exceeds originator's `maxFee`, or relay
node can't afford the amount), the next candidate is tried. If all candidates
fail, the P2P is cancelled and a cancel notification is sent upstream.
All candidates are deleted after the loop completes regardless of outcome.

After successful selection, `RouteCancellationService::cancelUnselectedRoutes()`
sends `route_cancel` messages to all unselected candidates' contacts, releasing
their capacity reservations immediately rather than waiting for CleanupService
TTL expiry. Each cancellation is recorded in the `route_cancellations` audit
table. Receiving nodes acknowledge the partial `route_cancel` without cancelling
their own P2P or releasing reservations — this is safe for diamond topologies
where a node may be part of both selected and unselected routes.

When the originator rejects a P2P (via CLI `p2p reject` or API), a full cancel
is broadcast downstream via `P2pService::broadcastFullCancelForHash()`. This
sends `route_cancel` with `full_cancel=true` to all accepted contacts. Relay
nodes receiving a full cancel: (1) mark their local P2P as cancelled, (2) release
their capacity reservation, and (3) propagate the full cancel further downstream
to their own contacts — creating a cascade that frees resources through the
entire route chain. CleanupService TTL expiry remains as a natural fallback.

**Phase 3a — Two-Phase Relay Selection (Mesh Deadlock Prevention):**

In mesh topologies, two relay nodes may be contacts of each other (e.g., A2 and
A4). Both receive the P2P from upstream, broadcast to their downstream contacts,
and record each other as `already_relayed` in the `p2p_relayed_contacts` table.
Neither can complete selection without the other's best candidate — a deadlock.

The two-phase relay mechanism breaks this deadlock:

1. **Relay Phase 1** — When all *inserted* contacts have responded but relayed
   contacts haven't, the node sends its current best downstream candidate to all
   `already_relayed` contacts via `sendBestCandidateToRelayedContacts()`. The
   `phase1_sent` flag is set atomically before sending to prevent re-triggering.
   If no candidates exist (all inserted contacts cancelled), `sendCancelToRelayedContacts()`
   sends cancel notifications instead, so relayed contacts can count the response
   and break mutual deadlocks without waiting for hop-wait expiration.

2. **Relay Phase 2** — When all propagated contacts (inserted + relayed combined)
   have responded, `selectAndForwardBestRp2p()` picks the overall best candidate
   (which now includes candidates from relayed contacts) and forwards it upstream
   via `handleRp2pRequest()`.

```
  A1 (upstream)          A3 (upstream)
   |                      |
  A2 ←-- already_relayed --→ A4
   |                          |
  A5 (downstream)            A6 (downstream)

Timeline (candidates exist):
1. A5 responds to A2, A6 responds to A4  (inserted contacts done)
2. A2 sends best(A5) to A4              (relay Phase 1)
   A4 sends best(A6) to A2              (relay Phase 1)
3. A2 re-selects from {A5, A4's candidate}, sends upstream to A1  (relay Phase 2)
   A4 re-selects from {A6, A2's candidate}, sends upstream to A3  (relay Phase 2)

Timeline (all inserted cancelled — no candidates):
1. A5 cancels to A2, A6 cancels to A4   (inserted contacts done, zero candidates)
2. A2 sends cancel to A4                (relay Phase 1 cancel)
   A4 sends cancel to A2                (relay Phase 1 cancel)
3. A2 + A4 trigger Phase 2 → selectAndForwardBestRp2p → cancel + propagate upstream
```

**Race condition guard:** If a relayed contact's RP2P arrives before all inserted
contacts respond, relay Phase 2 can trigger directly (skipping relay Phase 1).
To prevent this, `selectAndForwardBestRp2p()` checks `phase1_sent` before
forwarding upstream. If Phase 1 was skipped, it calls
`sendBestCandidateToRelayedContacts()` first, ensuring the relayed contact
receives the node's best downstream candidate before the final selection goes
upstream.

**Queued status guard:** When a relay node receives a P2P, it is inserted with
status `'queued'`. The P2P daemon picks it up and forwards it to downstream
contacts, updating the status and `contacts_sent_count`. However, cancel
notifications or RP2P candidates from *other* paths can arrive at the node before
the daemon processes the queued P2P. If `handleCancelNotification()` runs while
`contacts_sent_count` is still 0, it sees `respondedCount >= sentCount` and
triggers selection prematurely — cancelling the P2P before it reaches the
destination.

Both `handleCancelNotification()` and `handleRp2pCandidate()` check the P2P
status and defer selection when it is `'queued'`. The response is still counted
(incrementing `contacts_responded_count`), but the selection trigger is skipped.
After the daemon forwards the queued P2P and sets `contacts_sent_count`, the
subsequent `checkBestFeeSelection()` call picks up the deferred responses and
triggers selection with the correct counts.

For matched-contact sends (destination node is a direct contact of the relay),
`processQueuedP2pMessages()` also tracks `contacts_sent_count` for `'found'`
responses and calls `checkBestFeeSelection()` after forwarding — ensuring RP2P
responses that arrived during the blocking send are processed.

**Sender list merge:** `handleRp2pRequest()` merges `p2p_relayed_contacts` into
the `p2p_senders` list before sending RP2P responses. This ensures that contacts
which returned `already_relayed` during broadcast — but whose own P2P hasn't
arrived at this node yet — still receive the RP2P response.

**Phase 4 — Cascading Selection:**

Per-hop expiration ensures selection cascades from leaf nodes back to the originator.
Leaf nodes (closest to recipient) expire first, forward their best route upstream,
and each level selects its best before its own expiration fires.

### Per-Hop Expiration

In best-fee mode, each relay node calculates a local expiration based on its
position in the route, ensuring leaves expire before upstream nodes.

**Calculation at originator:**

```php
$hopWait = floor($fullExpiration / $maxRoutingLevel) - P2P_HOP_PROCESSING_BUFFER_SECONDS;
$hopWait = max($hopWait, P2P_MIN_HOP_WAIT_SECONDS);  // Minimum 3 seconds
```

**Calculation at relay:**

```php
$remainingHops = max(1, $maxRequestLevel - $requestLevel);
$scaledWait = $hopWait * $remainingHops;
$scaledExpiration = now() + $scaledWait;
```

**Upstream expiration cap:**

The scaled calculation can produce expirations that exceed the originator's timeout
(e.g., `15s * 19 hops = 285s` vs a 60s originator expiration). When this happens,
the originator expires first, finds zero candidates (relays are still alive), and
kills the P2P — defeating best-fee routing entirely.

To prevent this, each relay caps its expiration to the upstream node's expiration
minus one `hopWait` buffer. This preserves the leaf-to-root cascade ordering while
guaranteeing no relay outlives its upstream:

```php
$upstreamExpiration = $request['expiration'];  // Incoming from upstream node
if ($upstreamExpiration > 0 && $scaledExpiration >= $upstreamExpiration) {
    $cappedExpiration = $upstreamExpiration - convertToMicrotime($hopWait);
    $minExpiration = now() + convertToMicrotime(P2P_HOP_PROCESSING_BUFFER_SECONDS);
    $localExpiration = max($minExpiration, $cappedExpiration);
} else {
    $localExpiration = $scaledExpiration;
}
```

**Example cascade** (60s originator expiration, `hopWait = 15s`, 4 relay levels):

```
A0 (Originator):  expiration = T+60   (set by user's p2pExpiration)
A1 (Relay 1):     min(T+75, T+60-15) = T+45   ← capped by upstream
A3 (Relay 2):     min(T+60, T+45-15) = T+30   ← capped by upstream
A6 (Relay 3):     min(T+45, T+30-15) = T+15   ← capped by upstream
```

Leaves expire first → select best candidate → RP2P propagates upstream → each
level selects its best → originator selects at T+60. The cascade completes within
the originator's window regardless of hop count or `maxRequestLevel`.

### Orphaned Candidate Recovery

If a P2P expires before all contacts respond, the `CleanupService` triggers
best-fee selection on the available candidates rather than expiring the P2P:

```php
// In CleanupService::expireMessage()
if (!$message['fast'] && $candidateCount > 0) {
    $this->rp2pService->selectAndForwardBestRp2p($hash);
    $this->p2pRepository->updateStatus($hash, 'found');  // Prevent re-processing
    return;  // Don't expire — forward best available route
}
```

After selection, the P2P status is set to `'found'`. This is critical because
`getExpiredP2p()` runs on every cleanup cycle — without this status update, the
same P2P would be re-processed on the next cycle, potentially interfering with
the in-progress best-fee delivery. The `getExpiredP2p()` query excludes
`'found'` alongside `'completed'`, `'expired'`, and `'cancelled'`.

This ensures that even partial responses produce a usable route.

### Multi-Path Sender Tracking

In a mesh network, a relay node may receive the same P2P request from multiple
upstream nodes (e.g., A1 and A3 both relay to A4). The `p2p_senders` table
tracks all upstream senders so RP2P responses can be forwarded back along every
path that delivered the P2P.

```
  A0 (originator)
   |  \
  A1   A2
   |    |
  A3---A4 (relay)    ← A4 receives P2P from both A1 and A3
         |
        A5 (recipient)
```

**How it works:**

1. When A4 first receives the P2P from A1, it stores the P2P record
   (`p2p.sender_address = A1`) and records A1 in `p2p_senders`.
2. When A4 receives the duplicate P2P from A3, it returns `already_relayed`
   but also records A3 in `p2p_senders`.
3. When A4 receives the RP2P from A5, it forwards the response to **all**
   senders in `p2p_senders` (both A1 and A3), not just the original sender.

**Sender address correction on transaction arrival:**

When the actual transaction arrives at A4, it may come from A3 (the route A0 chose)
rather than A1 (stored in `p2p.sender_address`). The `TransactionProcessingService`
detects this mismatch and updates `p2p.sender_address` to match the actual sender.
This ensures completion relay, cleanup recovery, and txid bookkeeping reference the
correct upstream node.

### Transport Modes

| Mode | URL Pattern | Use Case |
|------|-------------|----------|
| HTTP | `http://hostname` | Local testing only |
| HTTPS | `https://hostname` | Production with SSL |
| Tor | `http://xxx.onion` | Anonymous communication |

**Priority:** Tor > HTTPS > HTTP (security preference)

**Transport Fallback:** When TOR delivery fails during contact requests (SOCKS5 connection
error), `TransportUtilityService::send()` attempts HTTP/HTTPS delivery using stored alternative
addresses. This fallback is only enabled for contact creation/acceptance messages
(`allowTransportFallback` parameter) — transactions and other messages respect the user's
chosen transport to preserve privacy. The fallback looks up alternative addresses via
`AddressRepository::getContactPubkeyHash()` and `lookupByPubkeyHash()`.

### Transport Concurrency Control

Batch sends (`sendBatch()`, `sendMultiBatch()`) use `curl_multi` with a sliding-window
concurrency limit to prevent overwhelming network circuits, particularly Tor. Instead of
firing all connections at once, the `executeWithConcurrencyLimit()` method runs up to N
handles simultaneously and adds the next as each completes.

Limits are configured per protocol in `Constants::CURL_MULTI_MAX_CONCURRENT`:

| Protocol | Max Concurrent | Rationale |
|----------|---------------|-----------|
| HTTP | 10 | Fast connections, high throughput |
| HTTPS | 10 | Same as HTTP with TLS overhead |
| Tor | 5 | SOCKS5 circuits overload easily; lower limit prevents thundering herd |

When a batch contains mixed protocols, the most restrictive (lowest) limit is used.
Unknown protocols fall back to the lowest configured value.

The lookup is centralized in `TransportUtilityService::getConcurrencyLimit(array $addresses)`
which resolves addresses to protocols via `determineTransportType()` and returns `min()` of
the applicable limits. To tune: edit the `CURL_MULTI_MAX_CONCURRENT` array in `Constants.php`.

---

## Transaction Lifecycle

### Transaction States

```
                                +----------+
                                |  pending |
                                +----+-----+
                                     |
                                     v
                                +----------+
                                | sending  |
                                +----+-----+
                                     |
               +---------------------+---------------------+
               |                     |                     |
               v                     v                     v
         +----------+          +----------+          +----------+
         |   sent   |          | rejected |          |  failed  |
         +----+-----+          +----------+          +----------+
               |
               +---------------------+
               |                     |
               v                     v
         +----------+          +-----------+
         | accepted |          | cancelled |
         +----+-----+          +-----------+
               |
               v
         +----------+
         |completed |
         +----------+
```

### State Descriptions

| State | Description |
|-------|-------------|
| `pending` | Transaction created, awaiting processing |
| `sending` | TransactionProcessor is attempting delivery |
| `sent` | Message delivered, awaiting recipient response |
| `accepted` | Recipient acknowledged receipt |
| `completed` | Transaction finalized, balances updated |
| `rejected` | Recipient declined (various reasons) |
| `cancelled` | Not received by peer in time (timeout) |
| `failed` | Delivery failed after max retries |

### Chain Integrity

Transactions form a chain linked by `prev_txid`:

```
+--------+     +--------+     +--------+     +--------+
| TX #1  |<----| TX #2  |<----| TX #3  |<----| TX #4  |
|prev=   |     |prev=#1 |     |prev=#2 |     |prev=#3 |
| null   |     |        |     |        |     |        |
+--------+     +--------+     +--------+     +--------+
```

Both parties maintain their own view of the chain. The `ContactStatusProcessor`
validates chain consistency and triggers sync if discrepancies are found.

When a chain gap is detected during sync, the `SyncService` attempts backup
recovery before falling through to a chain drop:
1. **Local self-repair** — checks local database backups for missing transactions
2. **Remote backup request** — sends remaining missing txids to the contact via the
   `missingTxids` field in the sync request; the contact checks its DB and backups
3. **Chain drop fallback** — only if neither side has the transaction in any backup,
   the `ChainDropService` coordinates mutual agreement to drop the missing transaction
   and relink the chain

### Send Flow (SendOperationService)

When a user sends a transaction, chain integrity is verified **before** the
transaction is created. If the chain has gaps, sync (with backup recovery) is
attempted automatically. The transaction only proceeds once the chain is valid:

```
sendEiou()
  +-- Validate inputs (address, amount, currency, etc.)
  +-- verifySenderChainAndSync()
  |     +-- verifyChainIntegrity() -> if valid, return success
  |     +-- syncTransactionChain()
  |     |     +-- Local backup recovery (self-repair)
  |     |     +-- Sync with contact (+ missingTxids for remote backup recovery)
  |     |     +-- Post-sync chain integrity check
  |     +-- Re-verify chain after sync
  |     +-- Return success if chain repaired, failure if gaps remain
  |
  +-- If chain verification failed:
  |     +-- Auto-propose chain drop (if sync completed but gaps remain)
  |     +-- Return error to user (transaction NOT created)
  |
  +-- If chain valid -> prepareStandardTransactionData() (tx created here)
  +-- Send transaction to contact
```

The transaction is never created or held during chain repair — if backup recovery
or sync repairs the chain, the send proceeds immediately. If the chain cannot be
repaired, the user receives an error and no transaction is created.

### HeldTransactionService

When a transaction arrives with an unknown `prev_txid`, it cannot be immediately
processed because the chain is incomplete.

```
Incoming Transaction (prev_txid=unknown)
              |
              v
+---------------------------+
| HeldTransactionService    |
|                           |
| 1. Store in held_trans    |
| 2. Request chain sync     |
| 3. Wait for missing TX    |
| 4. Process held when      |
|    chain complete         |
+---------------------------+
```

**P2P-aware lifecycle**: P2P transactions have a fixed expiration timestamp set at
creation (`P2P_DEFAULT_EXPIRATION_SECONDS = 300`). Every relay node in the P2P chain
holds an independent copy with the same expiration. Setting a local transaction back
to "pending" does **not** extend the P2P lifetime on other nodes. Therefore:

- **Proactive hold skips P2P if insufficient lifetime**: Before holding a P2P
  transaction during sync, `processOutgoingP2p` checks the remaining P2P lifetime.
  If less than `HELD_TX_SYNC_TIMEOUT_SECONDS` remains, the hold is skipped because
  the P2P will expire on every other relay node before sync completes.
- **Resume checks actual expiration timestamp**: `isP2pExpiredOrCancelled` checks
  both the P2P status field AND the raw expiration timestamp (the cleanup cycle may
  not have updated the status yet).
- **Stale sync timeout < P2P expiration**: `HELD_TX_SYNC_TIMEOUT_SECONDS` (120s) is
  intentionally shorter than `P2P_DEFAULT_EXPIRATION_SECONDS` (300s) so stale syncs
  are failed before the P2P network-wide expiry window closes.
- **Standard direct transactions** are the primary beneficiary of hold-and-resume
  since there are no multi-hop expiration constraints.

### Receive Flow (Incoming Transaction)

When a node receives a standard direct transaction from a contact, the request passes
through validation, chain integrity checks, storage, and acceptance response:

```
HTTP Request (from sender)
  |
  +-- index.html (Entry Point)
  |     +-- Verify envelope signature (secp256k1)
  |     +-- Validate required fields (senderPublicKey, senderAddress)
  |     +-- Validate public key and address format
  |     +-- Route by type === "send"
  |
  +-- TransactionValidationService.checkTransactionPossible()
  |     +-- Is sender blocked? -> reject with 'contact_blocked'
  |     +-- checkPreviousTxid()
  |     |     +-- Get expected: getPreviousTxid(senderPubkey, receiverPubkey)
  |     |     +-- Compare with request['previousTxid']
  |     |     +-- Mismatch? -> Proactive sync with sender
  |     |           +-- syncTransactionChain() (with backup recovery)
  |     |           +-- Retry previousTxid check after sync
  |     +-- checkAvailableFundsTransaction()
  |     |     +-- (balance + credit_limit) >= amount? -> reject if not
  |     +-- Check for duplicate txid -> reject if exists
  |     +-- Generate recipient signature
  |
  +-- TransactionProcessingService.processStandardIncoming()
  |     +-- INSERT transaction with status='received'
  |     +-- UPDATE tracking fields (initial_sender, end_recipient)
  |
  +-- Response: Echo acceptance JSON with recipientSignature
```

The proactive sync in the `checkPreviousTxid` step uses the same backup recovery
mechanism described in the [Chain Integrity](#chain-integrity) section: local backup
check, then remote backup request via `missingTxids`, then chain drop as last resort.

### Sync Flow (SyncService)

Transaction chain synchronization repairs chain gaps between two contacts. The flow
includes bilateral backup recovery so both sides can self-repair in a single round trip:

```
syncTransactionChain(contactAddress, contactPublicKey)
  |
  +-- 1. Get lastKnownTxid (our latest tx with this contact)
  |
  +-- 2. Detect chain gaps and attempt local self-repair
  |     +-- verifyChainIntegrity() -> get list of gaps
  |     +-- If BackupService available:
  |     |     +-- For each missing txid: restoreTransactionFromBackup()
  |     |     +-- Re-verify chain, update lastKnownTxid and remaining gaps
  |     +-- Else: all gaps become missingTxids for remote to resolve
  |
  +-- 3. Build sync request
  |     +-- buildTransactionSyncRequest(contactAddress, contactPubkey, lastKnownTxid)
  |     +-- Append missingTxids[] (remaining gaps for remote to check)
  |
  +-- 4. Send request to contact -> contact's handleTransactionSyncRequest()
  |
  +-- 5. Process sync response
        +-- For each transaction in response:
        |     +-- Skip if already exists locally
        |     +-- Check for chain conflict (same previous_txid)
        |     |     +-- Deterministic resolution: lower txid wins
        |     +-- Verify sender signature
        |     +-- Insert transaction
        +-- Dispatch SYNC_COMPLETED event
        +-- HeldTransactionService processes any unblocked held transactions


handleTransactionSyncRequest(request)  [Contact's side]
  |
  +-- 1. Verify sender is known contact
  +-- 2. Get transactions newer than request's lastKnownTxid
  +-- 3. Filter and format via formatTransactionForSync()
  |     +-- Description privacy: only include description for 'contact' or 'standard' memo
  +-- 4. Check missingTxids[] from requester (cap at 10)
  |     +-- For each missing txid not already in response:
  |     |     +-- Check local DB -> if found, format and include
  |     |     +-- If BackupService available: check local backups -> if restored, format and include
  +-- 5. Return filtered transactions (oldest first)
```

### Chain Drop Agreement Flow

When neither side has a missing transaction in their database or backups, the chain
drop protocol coordinates mutual agreement to remove the gap and relink the chain:

```
      PROPOSER (A)                                     RECEIVER (B)
           |                                                |
  1. verifyChainIntegrity()                                 |
     -> gap detected, missing txid                          |
           |                                                |
  2. Sync attempted (with backup recovery)                  |
     -> neither side has it                                 |
           |                                                |
  3. proposeChainDrop(contactPubkeyHash)                    |
     +-- Backup recovery fallback (safety net)              |
     +-- Create proposal record (direction=outgoing)        |
     +-- Send proposal ------------------------------------>|
           |                                   4. handleIncomingProposal()
           |                                      +-- Verify gap exists locally
           |                                      +-- Backup recovery fallback
           |                                      +-- Store proposal (direction=incoming)
           |                                                |
           |                                   5. User reviews via CLI/GUI
           |                                                |
           |                             +------------------+------------------+
           |                           Accept                                Reject
           |                             |                                     |
           |                    6. acceptProposal()                      6r. rejectProposal()
           |                       +-- executeChainDrop()                    +-- Update status: rejected
           |                       |     +-- Relink broken_txid's            +-- Send rejection --+
           |                       |     +-- previous_txid to skip                        |
           |                       |     +-- Re-sign affected tx                          |
           |                       +-- syncContactBalance()                               |
           |                       +-- updateChainStatus(valid=true)                      |
           |                       +-- Update status: accepted                            |
           |                       +-- Send acceptance                                    |
           |                       |   + resigned txs                                     |
           |<----------------------+                                                      |
           |                                                                              |
  7. handleIncomingAcceptance()                                                           |
     +-- executeChainDrop() locally                                                       |
     +-- processResignedTransactions()                                                    |
     +-- syncContactBalance()                                                             |
     +-- updateChainStatus(valid=true)                                                    |
     +-- Update status: accepted                                                          |
     +-- Mark proposal executed                                                           |
     +-- Send acknowledgment + our resigned txs -------->|                                |
           |                                             |                                |
           |                          8. handleIncomingAcknowledgment()                   |
           |                             +-- processResignedTransactions()                |
           |                             +-- updateChainStatus(valid=true)                |
           |                             +-- Mark proposal fully executed                 |
           |                                                                              |
           |<-----------------------------------------------------------------------------+
           |
  7r. handleIncomingRejection()
      +-- Update status: rejected
      +-- Log rejection reason
           |
         (done -- gap remains unresolved)
```

**Auto-Propose:** The `send` command and `ping` (Check Status) both auto-propose a chain drop
when sync detects mutual gaps. The `ContactStatusService` calls `proposeChainDrop()` after
`syncTransactionChain()` returns with unresolved `chain_gaps`. Controlled by
`Constants::isAutoChainDropProposeEnabled()` (env: `EIOU_AUTO_CHAIN_DROP_PROPOSE`, default: `true`).

**Auto-Accept:** Incoming proposals can be auto-accepted when `Constants::isAutoChainDropAcceptEnabled()`
is true (env: `EIOU_AUTO_CHAIN_DROP_ACCEPT`, default: `false` for safety). A **balance guard** can
optionally run before auto-accepting, controlled by `Constants::isAutoChainDropAcceptGuardEnabled()`
(env: `EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD`, default: `true`). When the guard is enabled, it compares
stored balances (from `BalanceRepository`) against balances calculated from existing transactions.
If the missing transactions include net payments TO us (`net_missing > 0`), auto-accept is blocked
and the proposal requires manual review. This prevents a malicious proposer from erasing debt by
forcing a chain drop on transactions where they owed us money. When the guard is disabled
(`EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD=false`), auto-accept proceeds unconditionally.

**Post-Drop Actions:** After successful execution, `ChainDropService` recalculates the contact
balance (via `SyncTriggerInterface::syncContactBalance()`) and updates `valid_chain` in the
contacts table so the GUI immediately reflects the repaired chain.

**Proposal States:** `pending` → `accepted` → `executed` (or `rejected` / `expired` / `failed`)

### Contact Lifecycle

Contacts progress through states managed by the `contacts` table:

```
                              +----------+
     Contact request sent --> |  pending |
                              +----+-----+
                                   |
                      +------------+------------+
                      |                         |
                      v                         v
               +----------+             +-----------+
               | accepted |             |  blocked  |
               +----+-----+             +-----------+
                    |                         ^
                    |                         |
                    +--- eiou block ----------+
                    |
                    +--- eiou delete --> (row deleted from DB)
```

| State | Description |
|-------|-------------|
| `pending` | Contact request created, awaiting acceptance by other party |
| `pending` (prior contact) | Auto-created by `ContactStatusService` when an unknown address pings after wallet restore; displayed with a "Prior Contact" badge in the GUI indicating prior transaction history exists; user must re-accept with name, fee, credit limit, and currency |
| `accepted` | Both parties confirmed; transactions and sync are enabled |
| `blocked` | Contact blocked; incoming messages rejected |

**Online Status:** Accepted contacts also have an `online_status` field updated by
the `ContactStatusProcessor`: `online`, `partial`, `offline`, or `unknown` (default).
The `partial` status indicates the node is reachable but has degraded message processors
(some of P2P, Transaction, or Cleanup processors are not running). The pong response
includes `processorsRunning` and `processorsTotal` fields for remote nodes to determine
partial vs online status, `chainStatusByCurrency` for per-currency chain validation results,
and `availableCreditByCurrency` for per-currency available credit. The processor pings one
contact per cycle, validates chain integrity per currency, and triggers sync if any
currency's chain heads don't match.

**Contact Request Flow:**

```
  Node A                                        Node B
    |                                             |
    +-- eiou add <address>                        |
    |     +-- Send contact request -------------->|
    |         (tx_type='contact', amount=0,       +-- Contact appears as 'pending'
    |          currency=<requested currency>)     |
    |                                             |
    |                                             +-- eiou accept <name>
    |                                             |     +-- Update contact to 'accepted'
    |<---------- Send acceptance message ---------+     +-- Complete contact transaction
    +-- Update contact to 'accepted'              |
    +-- Complete contact transaction              |
```

### Error Handling

**Recovery Service:**

Transactions stuck in `sending` state (e.g., after a crash) are recovered on startup:

```php
// TransactionRecoveryService
RECOVERY_SENDING_TIMEOUT_SECONDS = 120   // Stuck after 2 minutes
RECOVERY_MAX_RETRY_COUNT = 3             // Max recovery attempts
```

**Dead Letter Queue:**

Messages that fail after all retries are moved to the dead letter queue for manual
review rather than being silently dropped.

---

## Startup Sequence

### Container Startup (startup.sh)

```
1. Configure output buffering for real-time logging
         |
         v
2. Register signal handlers (SIGTERM, SIGINT, SIGHUP)
         |
         v
3. Generate or install SSL certificates (priority chain)
   - Check /ssl-certs/ for external certs
   - Check for Let's Encrypt (LETSENCRYPT_EMAIL env var → certbot)
   - Check /ssl-ca/ for CA-signed generation
   - Fall back to self-signed
         |
         v
4. Start services: cron -> tor -> apache2 -> mariadb
         |
         v
5. Wait for MariaDB readiness (mysqladmin ping)
         |
         v
6. Wallet generation or restoration
   - RESTORE_FILE (file-based, most secure)
   - RESTORE (env var)
   - QUICKSTART (new wallet with hostname)
   - Default (new wallet, Tor only)
         |
         v
7. Restart Tor to load hidden service keys
   - Fix permissions on /var/lib/tor/hidden_service
   - Retry up to 3 times with exponential backoff
         |
         v
8. Validate message processing prerequisites (MessageCheck.php)
         |
         v
9. Wait for Tor connectivity (curl through SOCKS5 proxy)
   - Timeout: EIOU_TOR_TIMEOUT (default 120s)
         |
         v
10. Start background message processors
    - processors/P2pMessages.php
    - processors/TransactionMessages.php
    - processors/CleanupMessages.php
    - processors/ContactStatusMessages.php (if enabled)
         |
         v
11. Start watchdog for process monitoring
         |
         v
12. Enter main loop (sleep + wait for signals)
```

### Application Initialization Order

**CRITICAL:** The initialization order in `Application::__construct()` is essential:

```
1. getLogger()           -> Initialize Logger
         |
         v
2. Database Setup
   - constructDatabase() if first run
   - loadCurrentDatabase()
         |
         v
3. getDatabase()         -> Create PDO connection
         |
         v
4. runMigrations()       -> Add new tables if needed
         |
         v
5. If config/userconfig.json exists:
   - loadCurrentUser()   -> UserContext MUST load first
   - loadServiceContainer()
   - loadUtilityServiceContainer()
   - wireAllServices()   -> Wire circular dependencies
   - runTransactionRecovery() (CLI only)
```

**Important:** `UserContext` MUST initialize BEFORE `ServiceContainer`. Violating this
order causes runtime crashes.

---

## Docker Topologies

Four docker-compose files provide different network topologies for development and testing:

| File | Nodes | Use Case |
|------|-------|----------|
| `docker-compose-single.yml` | 1 (`eiou-single`) | Basic development, startup validation |
| `docker-compose-4line.yml` | 4 (`alice`, `bob`, `carol`, `daniel`) | Linear P2P routing tests (~1.1GB) |
| `docker-compose-10line.yml` | 10 (`node-a` through `node-j`) | Extended routing, latency testing (~2.8GB) |
| `docker-compose-cluster.yml` | 13 (`cluster-a0` hub + spokes) | Hub-and-spoke mesh topology |

### Single Node

```
+-------------+
| eiou-single |
+-------------+
```

Used for startup validation, wallet generation testing, and single-node API/CLI
development. Minimal resource footprint.

### 4-Node Linear

```
alice <---> bob <---> carol <---> daniel
```

Each node is a direct contact of its neighbour. Tests P2P routing across 1-3 hops,
transaction chain synchronization, and contact status monitoring.

### 10-Node Linear

```
node-a <-> node-b <-> node-c <-> ... <-> node-j
```

Extended linear topology for testing deeper routing paths, per-hop expiration
cascading in best-fee mode, and network propagation delays.

### Cluster (Hub-and-Spoke Mesh)

```
  a31 a32     a41 a42
    \  /       \  /
     a3         a4
       \       /
         a0
       /       \
     a2         a1
    /  \       /  \
  a22 a21   a12  a11
```

Hub node (`cluster-a0`) connects to spoke nodes (`cluster-a1` through `cluster-a4`),
each with their own leaf nodes. Tests multi-path routing, two-phase relay selection
deadlock prevention, and multi-path sender tracking.

---

## GUI Architecture

The Web GUI is a server-rendered PHP application served on the same port as the REST
API (8080). It uses an MVC-like structure with controllers, helpers, and HTML templates.

### GUI Component Structure

```
/src/gui/
├── controllers/              # POST request handlers
│   ├── ContactController     # Contact add, accept, block, delete, settings
│   ├── TransactionController # Send eIOU, transaction operations
│   └── SettingsController    # Node settings management
├── helpers/                  # View data preparation
│   ├── ContactDataBuilder    # Builds contact data arrays for templates
│   ├── MessageHelper         # Flash message formatting and display
│   └── ViewHelper            # Common view utilities
├── functions/
│   └── Functions             # Shared template functions
├── includes/
│   └── Session               # Secure session management (auth code-based)
├── layout/
│   ├── authenticationForm    # Login page (auth code entry)
│   ├── wallet.html           # Main wallet layout (authenticated)
│   └── walletSubParts/       # Wallet page sections
│       ├── header             # Wallet title, logout button
│       ├── banner             # System status banners
│       ├── quickActions        # Action buttons (Send, Add Contact, etc.)
│       ├── walletInformation   # Balance, earnings, available credit cards
│       ├── contactSection      # Contact cards with scroll navigation
│       ├── contactForm         # Contact modal (add/accept/view) with currency slider pills
│       ├── eiouForm            # Send eIOU form with dynamic currency list and P2P options
│       ├── transactionHistory  # Recent transactions list
│       ├── settingsSection     # Node settings panel
│       ├── notifications       # Toast notification container
│       └── floatingButtons     # Refresh and back-to-top buttons
└── assets/
    ├── css/                  # Stylesheets
    ├── js/                   # JavaScript (vanilla, Tor-compatible)
    └── fontawesome/          # Icon library
```

### Session Management

Authentication uses the node's auth code (derived from the wallet seed). The `Session`
class implements secure session handling:

- Session cookies: `httponly`, `samesite=Strict`, `secure` (when HTTPS)
- Auth code comparison with timing-safe equality check
- Session regeneration on login to prevent fixation attacks

### Tor Compatibility

All GUI JavaScript uses Tor-compatible patterns:
- `var` instead of `let`/`const` (older Tor Browser versions)
- `className` instead of `classList`
- Vendor-prefixed flex properties
- No external resource loading (all assets bundled)

---

## CLI Architecture

The CLI is the primary interface for node management, accessible via the `eiou` command
inside the Docker container.

### CLI Component Structure

```
/src/cli/
├── CliOutputManager     # Output format controller (text or JSON mode)
└── CliJsonResponse      # Standardized JSON response formatter (RFC 9457-inspired)

/src/services/
└── CliService           # Command implementations (88KB, largest service)
```

### Output Modes

The `CliOutputManager` singleton supports two output modes:

| Mode | Flag | Output |
|------|------|--------|
| Text (default) | — | Human-readable formatted output with colours |
| JSON | `--json` | Structured JSON with metadata, timing, error codes |

**JSON Response Format** (based on kubectl, docker CLI, gh CLI patterns):

```json
{
  "status": "success",
  "command": "send",
  "data": { ... },
  "metadata": {
    "version": "1.0.0",
    "nodeId": "alice",
    "executionTime": "0.234s"
  }
}
```

### Command Dispatch

The `Eiou.php` entry point handles command routing:

```
eiou <command> [args...] [--json] [--help]
    |
    +-- Parse arguments, detect --json flag
    +-- Initialize Application singleton
    +-- Route to CliService method
    +-- Catch ServiceExceptions → formatted output
```

---

## Payload Schemas

The `/src/schemas/payloads/` directory defines structured payload builders for all
message types exchanged between nodes. Each payload class extends `BasePayload` and
provides type-safe construction of the EIOU wire protocol messages.

### Payload Hierarchy

```
BasePayload (abstract)
    |
    +-- TransactionPayload    # Standard send transactions
    +-- ContactPayload        # Contact request/acceptance messages
    +-- ContactStatusPayload  # Ping/pong status messages (per-currency chain validation and credit exchange)
    +-- P2pPayload            # P2P routing request messages
    +-- Rp2pPayload           # Return P2P response messages
    +-- MessagePayload        # General inter-node messages (sync, chain drop)
    +-- UtilPayload           # Utility messages (debug, test)
```

### BasePayload

The abstract `BasePayload` provides common functionality:

- Access to `UserContext` for sender identity (public key, addresses)
- Access to `UtilityServiceContainer` for currency formatting, time formatting,
  validation, and transport services
- Envelope construction with sender signature (secp256k1 ECDSA)

### OutputSchema

The `OutputSchema` class (`/src/schemas/OutputSchema.php`) defines standardized
response formats for API and CLI output, ensuring consistent field naming and
structure across all endpoints.

---

## Security Model

### Key Management

**BIP39 Mnemonic:**

- 24-word seed phrase generated using cryptographically secure random
- Used to derive all cryptographic material deterministically
- Stored encrypted with AES-256-GCM

**Key Derivation:**

```
Mnemonic (24 words)
        |
        v
BIP39::mnemonicToSeed()
        |
        v
   Seed (512 bits)
        |
        v
BIP39::seedToKeyPair()
        |
        v
+-------------------+
| secp256k1 Keypair |
| - Private Key     |
| - Public Key      |
+-------------------+
        |
        v
TorKeyDerivation
        |
        v
+-------------------+
| Ed25519 Keypair   |
| - .onion Address  |
+-------------------+
```

### Security Components

| Component | Path | Purpose |
|-----------|------|---------|
| `BIP39` | `/src/security/BIP39.php` | Mnemonic generation, seed derivation, secp256k1 keypair creation |
| `KeyEncryption` | `/src/security/KeyEncryption.php` | AES-256-GCM encryption/decryption for private keys and auth codes |
| `PayloadEncryption` | `/src/security/PayloadEncryption.php` | ECDH + AES-256-GCM end-to-end encryption for all contact message payloads |
| `TorKeyDerivation` | `/src/security/TorKeyDerivation.php` | Derives Ed25519 keypairs from secp256k1 keys for Tor v3 hidden service addresses |

### Encrypted Storage

| Item | Encryption | File |
|------|------------|------|
| Private Key | AES-256-GCM | `/etc/eiou/config/userconfig.json` |
| Auth Code | AES-256-GCM | `/etc/eiou/config/userconfig.json` |
| Mnemonic | AES-256-GCM | Displayed once, not stored |

### Payload Encryption (E2E)

All messages sent to known contacts are end-to-end encrypted using ephemeral ECDH
key agreement + AES-256-GCM. Every content field — including `type` — is encrypted,
making all message types (P2P, RP2P, transactions, pings, route cancellations, etc.)
indistinguishable on the wire. Encryption happens in `TransportUtilityService::signWithCapture()`
(encrypt-then-sign), decryption in `index.html` before message routing.

**Excluded from encryption:**
- `create` (contact requests) — recipient may not be a contact yet (no public key)

**Graceful cleartext fallback** (recipient public key unavailable):
- Transaction inquiry to P2P end-recipient — not necessarily a direct contact
- Contact acceptance inquiry to pending contacts — public key not yet known
- Any message where `ContactRepository::getPublicKeyFromAddress()` returns null

The signed message structure is `{encrypted: {ciphertext, iv, tag, ephemeralKey}, nonce}`
when encrypted, or `{...fields..., nonce}` when falling back to cleartext. The receiver
decrypts the `encrypted` block (if present) before type-based routing.

### Transport Security

| Layer | Protection |
|-------|------------|
| HTTPS | TLS 1.2+ with auto-generated or custom certificates |
| Tor | Onion routing for IP anonymization |
| Message Signing | secp256k1 ECDSA signatures on all messages |
| E2E Encryption | ECDH + AES-256-GCM for all contact message payloads (type-indistinguishable) |

**SSL Certificate Priority Chain:**

| Priority | Source | Configuration |
|----------|--------|---------------|
| 1 | External certificates | Mount to `/ssl-certs/` volume |
| 2 | Let's Encrypt (certbot) | `LETSENCRYPT_EMAIL` env var; persistent `/etc/letsencrypt` volume |
| 3 | CA-signed generation | Mount CA key/cert to `/ssl-ca/` volume |
| 4 | Self-signed (fallback) | Auto-generated on startup |

**Let's Encrypt Integration:**

- In-container certbot for single-node deployments
- Host-level scripts for multi-node or wildcard certificates:
  - `scripts/create-ssl-letsencrypt.sh` — obtain certs (HTTP-01 or DNS-01 wildcard)
  - `scripts/renew-ssl-letsencrypt.sh` — automated renewal for cron
- Automatic renewal cron inside containers using Let's Encrypt
- Wildcard certs shared across multiple nodes via `/ssl-certs/` volume mount
- Environment variables: `LETSENCRYPT_EMAIL`, `LETSENCRYPT_DOMAIN`, `LETSENCRYPT_STAGING`

### API Authentication

API requests use HMAC-SHA256 signature-based authentication:

```
Signature = HMAC-SHA256(string_to_sign, api_secret)

string_to_sign = METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + BODY
```

**Security Features:**

| Feature | Implementation |
|---------|----------------|
| Replay Prevention | Timestamps must be within 5 minutes |
| Secret Protection | Only signature sent, never the secret |
| Rate Limiting | Per-key limits (default: 100 req/min) |

### Rate Limiting

The `RateLimiterService` protects against abuse:

```php
RATE_LIMIT_ENABLED = true  // Always true in production
```

**Warning:** Only disable rate limiting during development debugging.

---

## Error Handling

### Error Handling Architecture

The application uses a layered error handling approach with specialized exceptions for
business logic errors and a global safety net for unexpected failures.

```
                    +------------------------------------------+
                    |            ErrorHandler.php               |
                    |   (Global safety net - set_exception_     |
                    |    handler for truly uncaught errors)     |
                    +------------------------------------------+
                                        ^
                                        | (only if not caught below)
                    +-------------------+-------------------+
                    |                                       |
        +-----------+------------+          +---------------+----------------+
        |      Eiou.php          |          |         ApiController          |
        |    (CLI Entry)         |          |         (API Entry)            |
        +------------------------+          +--------------------------------+
        | catch Validation ->    |          | catch ServiceException ->      |
        |   format + exit(1)     |          |   use getMessage()             |
        |                        |          |   use getHttpStatus()          |
        | catch Fatal ->         |          |   use getErrorCode()           |
        |   format + exit(1)     |          |                                |
        |                        |          | catch Exception ->             |
        | catch Recoverable ->   |          |   generic 500 error            |
        |   format + exit(0)     |          |                                |
        +-----------+------------+          +---------------+----------------+
                    |                                       |
                    +-------------------+-------------------+
                                        |
        +-------------------------------+-------------------------------+
        |                        Service Layer                          |
        |   ContactService, MessageService, WalletService, etc.         |
        |                                                               |
        |   throw ValidationServiceException("Invalid name", ...)       |
        |   throw FatalServiceException("Wallet not found", ...)        |
        +---------------------------------------------------------------+
```

### ServiceException Hierarchy

The `ServiceException` classes (`/src/exceptions/`) provide structured error handling
for business logic errors, replacing direct `exit()` calls in service methods.

```
ServiceException (abstract)
    |
    +-- FatalServiceException
    |     Unrecoverable errors (missing wallet, unauthorized access)
    |     Exit code: 1
    |
    +-- RecoverableServiceException
    |     Retryable errors (network timeouts, temporary unavailability)
    |     Exit code: 0 (configurable)
    |
    +-- ValidationServiceException
          Input validation errors (invalid address, invalid name)
          Exit code: 1
          Includes field name for targeted error display
```

**ServiceException Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `errorCode` | string | Maps to `ErrorCodes` constants |
| `httpStatus` | int | HTTP status code for API responses |
| `context` | array | Additional debugging data |

**Key Methods:**

```php
$exception->getMessage();      // Human-readable error message
$exception->getErrorCode();    // ErrorCodes constant (e.g., INVALID_NAME)
$exception->getHttpStatus();   // HTTP status (e.g., 400, 404, 500)
$exception->getContext();      // Additional context array
$exception->getExitCode();     // CLI exit code (0 or 1)
$exception->toArray();         // Full error as array for JSON
$exception->toJson();          // JSON-encoded error response
```

### Error Handling by Entry Point

**CLI Entry Point (Eiou.php):**

```php
try {
    // Command dispatch...
} catch (ValidationServiceException $e) {
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
    $logger->warning("Validation error", ['field' => $e->getField()]);
    exit($e->getExitCode());  // exit(1)

} catch (FatalServiceException $e) {
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
    $logger->error("Fatal service error", ['context' => $e->getContext()]);
    exit($e->getExitCode());  // exit(1)

} catch (RecoverableServiceException $e) {
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
    $logger->info("Recoverable error");
    exit($e->getExitCode());  // exit(0)
}
```

**API Entry Point (ApiController):**

```php
try {
    $response = match ($resource) { ... };
} catch (ServiceException $e) {
    // Use rich error context from exception
    $response = $this->errorResponse(
        $e->getMessage(),
        $e->getHttpStatus(),
        strtolower($e->getErrorCode())
    );
} catch (Exception $e) {
    // Generic fallback for unexpected errors
    $response = $this->errorResponse('Internal server error', 500, 'internal_error');
}
```

### ErrorHandler (Global Safety Net)

The `ErrorHandler` class (`/src/core/ErrorHandler.php`) provides last-resort handling
for any exceptions that escape the entry point try-catch blocks.

**Initialization:**

```php
ErrorHandler::init();  // Called during Application bootstrap
```

**What It Handles:**

| Handler | Purpose |
|---------|---------|
| `set_error_handler()` | PHP errors (warnings, notices) |
| `set_exception_handler()` | Uncaught exceptions |
| `register_shutdown_function()` | Fatal errors on shutdown |

**Environment-Aware Output:**

| Environment | Behavior |
|-------------|----------|
| Production | Shows generic "An error occurred" message |
| Development | Shows full error details, stack trace |

### When to Use Each Exception Type

| Scenario | Exception Type | Example |
|----------|----------------|---------|
| Invalid user input | `ValidationServiceException` | Bad address format, invalid name |
| Missing required resource | `FatalServiceException` | Wallet doesn't exist |
| Unauthorized action | `FatalServiceException` | Invalid message source |
| Network timeout | `RecoverableServiceException` | Contact temporarily unreachable |
| Rate limited | `RecoverableServiceException` | Too many requests |

### Throwing Exceptions in Services

```php
// Validation error with field context
throw new ValidationServiceException(
    "Invalid name: " . $validation['error'],
    ErrorCodes::INVALID_NAME,
    'name',           // Field that failed
    400               // HTTP status
);

// Fatal error with context
throw new FatalServiceException(
    "Wallet does not exist. Run 'generate' or 'restore' first.",
    ErrorCodes::WALLET_NOT_FOUND,
    ['requested_action' => $request],  // Context for debugging
    404
);

// Recoverable error
throw new RecoverableServiceException(
    "Contact temporarily unavailable",
    ErrorCodes::CONTACT_OFFLINE,
    ['retry_after' => 60],
    503,
    0  // Exit code 0 (not a hard failure)
);
```

### Integration with ErrorCodes

ServiceExceptions integrate with the existing `ErrorCodes` class for consistent
error identification:

```php
// ErrorCodes provides:
ErrorCodes::INVALID_NAME        // Error code constant
ErrorCodes::getHttpStatus($code)  // Auto-detect HTTP status from code
ErrorCodes::getTitle($code)     // Human-readable title
```

### Testing Error Paths

ServiceExceptions enable proper unit testing of error conditions:

```php
// Test that validation errors are properly thrown
public function testSearchContactsWithInvalidName(): void
{
    $this->expectException(ValidationServiceException::class);
    $this->expectExceptionMessage('Invalid name');

    $contactService->searchContacts(['eiou', 'search', '<script>'], $output);
}
```

---

## Related Documentation

### API and CLI Reference

| Document | Description |
|----------|-------------|
| [API_REFERENCE.md](API_REFERENCE.md) | Complete REST API documentation |
| [API_QUICK_REFERENCE.md](API_QUICK_REFERENCE.md) | API endpoint quick reference |
| [CLI_REFERENCE.md](CLI_REFERENCE.md) | Command-line interface guide |

### GUI Documentation

| Document | Description |
|----------|-------------|
| [GUI_REFERENCE.md](GUI_REFERENCE.md) | Web interface documentation |
| [GUI_QUICK_REFERENCE.md](GUI_QUICK_REFERENCE.md) | GUI quick reference card |

### Configuration and Errors

| Document | Description |
|----------|-------------|
| [DOCKER_CONFIGURATION.md](DOCKER_CONFIGURATION.md) | Container configuration options |
| [ERROR_CODES.md](ERROR_CODES.md) | Error code reference |

### Source Code Locations

| Component | Path |
|-----------|------|
| Application | `/etc/eiou/src/core/Application.php` |
| ServiceContainer | `/etc/eiou/src/services/ServiceContainer.php` |
| DI Container Config | `/etc/eiou/src/config/container.php` |
| ErrorHandler | `/etc/eiou/src/core/ErrorHandler.php` |
| Exceptions | `/etc/eiou/src/exceptions/` |
| Processors | `/etc/eiou/src/processors/` |
| Repositories | `/etc/eiou/src/database/` |
| Repository Traits | `/etc/eiou/src/database/traits/` |
| Services | `/etc/eiou/src/services/` |
| Service Proxies | `/etc/eiou/src/services/proxies/` |
| Formatters | `/etc/eiou/src/formatters/` |
| Utility Services | `/etc/eiou/src/services/utilities/` |
| Utils (Logging, Validation) | `/etc/eiou/src/utils/` |
| Security (BIP39, Encryption) | `/etc/eiou/src/security/` |
| Contracts (Interfaces) | `/etc/eiou/src/contracts/` |
| Events | `/etc/eiou/src/events/` |
| Payload Schemas | `/etc/eiou/src/schemas/payloads/` |
| CLI | `/etc/eiou/src/cli/` |
| GUI Controllers | `/etc/eiou/src/gui/controllers/` |
| GUI Templates | `/etc/eiou/src/gui/layout/` |
| GUI Helpers | `/etc/eiou/src/gui/helpers/` |
| Startup Checks | `/etc/eiou/src/startup/` |
| API Controller | `/etc/eiou/src/api/ApiController.php` |

---

*Document generated from source code analysis. For the latest information, refer to
the source files directly.*
