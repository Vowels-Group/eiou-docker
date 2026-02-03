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
12. [Security Model](#security-model)
13. [Error Handling](#error-handling)
14. [Related Documentation](#related-documentation)

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
|   UserContext     |   | ServiceContainer  |   |    UtilityContainer |
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
          |             |                |                  |
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
| `/etc/eiou/defaultconfig.json` | System defaults (fee limits, P2P settings) |
| `/etc/eiou/userconfig.json` | User-specific settings (keys, addresses) |

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
| `TransactionService` | **Facade** for transaction operations, delegates to specialized services | BalanceService, ChainVerificationService, TransactionValidationService, TransactionProcessingService, SendOperationService, P2pService, SyncService |
| `BalanceService` | Balance calculations and currency conversions | BalanceRepo, TransactionContactRepo, AddressRepo, CurrencyUtility |
| `ChainVerificationService` | Transaction chain integrity verification | TransactionChainRepo, SyncService |
| `TransactionValidationService` | Transaction validation with proactive sync | TransactionRepo, ContactRepo, ValidationUtility, SyncService |
| `TransactionProcessingService` | Transaction processing with atomic claiming | TransactionRepo, TransactionRecoveryRepo, TransactionChainRepo, P2pRepo, BalanceRepo, SyncService, HeldTransactionService |
| `SendOperationService` | Send orchestration with distributed locking | TransactionRepo, AddressRepo, P2pRepo, TransportUtility, ContactService, LockingService |
| `P2pService` | Peer-to-peer message routing | ContactRepo, P2pRepo, TransportUtility, MessageDeliveryService |
| `Rp2pService` | Return P2P (response) message handling | ContactRepo, Rp2pRepo, TransactionService |
| `ContactService` | Contact management and messaging | ContactRepo, AddressRepo, TransactionContactRepo, SyncService, MessageDeliveryService |
| `SyncService` | Transaction chain synchronization | ContactRepo, TransactionRepo, TransactionChainRepo, HeldTransactionService |
| `MessageDeliveryService` | Reliable delivery with retry/DLQ | MessageDeliveryRepo, DeadLetterQueueRepo, TransportUtility |
| `HeldTransactionService` | Pending transaction queue for sync | HeldTransactionRepo, TransactionRepo, TransactionChainRepo, SyncService |
| `CleanupService` | Expired message cleanup | P2pRepo, Rp2pRepo, TransactionRepo |
| `WalletService` | Wallet information access | UserContext |
| `MessageService` | Incoming message handling | ContactRepo, P2pRepo, TransactionRepo, TransactionContactRepo, SyncService |
| `ContactStatusService` | Contact ping/status checking | ContactRepo, TransactionRepo, SyncService |
| `ApiAuthService` | API authentication (HMAC-SHA256) | ApiKeyRepo |
| `ApiKeyService` | API key management | ApiKeyRepo |
| `TransactionRecoveryService` | Stuck transaction recovery | TransactionRecoveryRepo |
| `RateLimiterService` | Request rate limiting | RateLimiterRepo |
| `CliService` | CLI output formatting | ContactRepo, BalanceRepo, TransactionRepo |
| `DebugService` | Debug logging and diagnostics | DebugRepo |

### Utility Services

The `UtilityServiceContainer` provides helper services for common operations:

| Utility | Purpose |
|---------|---------|
| `TimeUtilityService` | Timestamp formatting, timezone handling |
| `CurrencyUtilityService` | Amount conversion, formatting |
| `ValidationUtilityService` | Input validation, sanitization |
| `TransportUtilityService` | HTTP/HTTPS/Tor message transport |

### Circular Dependency Resolution

Some services have circular dependencies (e.g., TransactionService needs SyncService
and vice versa). These are resolved via setter injection:

```php
// In ServiceContainer::wireCircularDependencies()

// Core circular dependencies
$this->services['TransactionService']->setSyncService($this->services['SyncService']);
$this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
$this->services['Rp2pService']->setTransactionService($this->services['TransactionService']);

// TransactionService facade receives its 5 specialized services
$this->services['TransactionService']->setBalanceService($this->services['BalanceService']);
$this->services['TransactionService']->setChainVerificationService($this->services['ChainVerificationService']);
$this->services['TransactionService']->setTransactionValidationService($this->services['TransactionValidationService']);
$this->services['TransactionService']->setTransactionProcessingService($this->services['TransactionProcessingService']);
$this->services['TransactionService']->setSendOperationService($this->services['SendOperationService']);

// Specialized services also have circular dependencies
$this->services['ChainVerificationService']->setSyncService($this->services['SyncService']);
$this->services['TransactionValidationService']->setSyncService($this->services['SyncService']);
$this->services['TransactionProcessingService']->setSyncService($this->services['SyncService']);
$this->services['SendOperationService']->setContactService($this->services['ContactService']);
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
    private ?SyncServiceInterface $syncService = null;

    public function setSyncService(SyncServiceInterface $syncService): void {
        $this->syncService = $syncService;
    }

    private function getSyncService(): SyncServiceInterface {
        if ($this->syncService === null) {
            throw new RuntimeException(
                'SyncService not injected. Call setSyncService() or ensure ' .
                'ServiceContainer::wireCircularDependencies() is called.'
            );
        }
        return $this->syncService;
    }
}
```

### Dependency Graph

```
                    +------------------+
                    | ServiceContainer |
                    +--------+---------+
                             |
      +----------------------+----------------------+
      |                      |                      |
+-----v------+       +-------v-------+      +-------v-------+
|  Sync      |       | Transaction   |      |   Contact     |
|  Service   |<----->|   Service     |<---->|   Service     |
+-----+------+       +-------+-------+      +-------+-------+
      |                      |                      |
      |              +-------+-------+              |
      |              |               |              |
+-----v------+ +-----v-----+ +------v-----+ +------v------+
|   Held     | | Balance   | |   Chain    | |   Message   |
| Transaction| | Service   | |Verification| |   Service   |
|  Service   | +-----------+ |  Service   | +-------------+
+------------+               +------------+

Legend:
  -----> Constructor injection
  <----> Setter injection (circular dependency)
```

---

## Circular Dependency Management

### Why Setter Injection Exists

Some services have dependencies that require setter injection due to initialization
order constraints. Most circular dependencies have been eliminated:

| Dependency | Pattern | Notes |
|------------|---------|-------|
| SyncService -> HeldTransactionService | Setter injection | Sync notifies held transaction service |

### How wireCircularDependencies() Works

`ServiceContainer::wireCircularDependencies()` is called after all services are
constructed to wire up setter-injected dependencies:

```php
public function wireCircularDependencies(): void {
    // Core sync-related dependencies - now use SyncTriggerInterface via proxy
    $this->services['TransactionService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['SyncService']->setHeldTransactionService($this->services['HeldTransactionService']);
    // Note: HeldTransactionService uses EventDispatcher for sync notifications (no setter injection)

    // Contact and message service dependencies - use SyncTriggerInterface via proxy
    $this->services['ContactService']->setSyncTrigger($this->getSyncServiceProxy());
    $this->services['MessageService']->setSyncTrigger($this->getSyncServiceProxy());

    // RP2P uses interface-based injection (breaks circular dependency)
    $this->services['Rp2pService']->setP2pTransactionSender($this->services['SendOperationService']);

    // Refactored service dependencies (still use direct SyncService for specialized needs)
    $this->services['ChainVerificationService']->setSyncService($this->services['SyncService']);
    $this->services['TransactionProcessingService']->setSyncService($this->services['SyncService']);
    $this->services['SendOperationService']->setContactService($this->services['ContactService']);
    // ... additional wiring
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

Processes queued P2P routing messages with fast polling for network propagation.

| Setting | Value |
|---------|-------|
| Min Interval | 100ms |
| Max Interval | 5000ms (5s) |
| Idle Interval | 2000ms (2s) |
| Log Interval | 60 seconds |
| Lockfile | `/tmp/p2pmessages_lock.pid` |

**Processing Loop:**
```php
protected function processMessages(): int {
    return $this->p2pService->processQueuedP2pMessages();
}
```

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
- Updates contact online status (online/offline/unknown)
- Validates transaction chain integrity (prev_txid matching)
- Triggers sync if chains don't match
- Respects `EIOU_CONTACT_STATUS_ENABLED` environment variable

### Watchdog Monitoring

The watchdog runs every 30 seconds and monitors processor health:

```
+------------------+
|    Watchdog      |
| (30s interval)   |
+------------------+
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
                |-- No: Restart (if < 10 restarts, > 60s cooldown)
```

**Watchdog Configuration:**

| Setting | Value |
|---------|-------|
| Check Interval | 30 seconds |
| Restart Cooldown | 60 seconds |
| Max Restarts | 10 per processor |

---

## Data Layer

### Database Tables

Each node maintains a MariaDB database with these primary tables:

| Table | Purpose |
|-------|---------|
| `contacts` | Known peers with public keys, addresses, status |
| `addresses` | Contact address variants (HTTP, HTTPS, Tor) |
| `balances` | Current balance with each contact |
| `transactions` | Transaction history and chain links |
| `p2p` | Outbound P2P routing messages |
| `rp2p` | Return P2P (response) messages |
| `message_delivery` | Delivery tracking with retry state |
| `dead_letter_queue` | Failed messages for manual review |
| `delivery_metrics` | Message delivery statistics |
| `held_transactions` | Transactions pending sync completion |
| `api_keys` | API authentication keys |
| `rate_limiter` | Rate limiting state |

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
    +-- Rp2pRepository
    +-- MessageDeliveryRepository
    +-- DeadLetterQueueRepository
    +-- HeldTransactionRepository
    +-- ApiKeyRepository
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
| Error Handling | Exceptions logged via SecureLogger |

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

---

## P2P Networking

### P2P Routing Overview

P2P routing enables transactions to reach recipients through intermediate nodes when
no direct connection exists.

```
      ALICE                   BOB                    CAROL                   EVE
   (Sender)              (Intermediary)          (Intermediary)          (Recipient)
      |                       |                       |                       |
      |   P2P Request         |                       |                       |
      |  level=500            |                       |                       |
      |---------------------->|                       |                       |
      |                       |   P2P Request         |                       |
      |                       |  level=499            |                       |
      |                       |---------------------->|                       |
      |                       |                       |   P2P Request         |
      |                       |                       |  level=498            |
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

### P2P Message Flow

**Outbound (P2pService):**

1. User initiates transaction to unknown recipient
2. P2pService creates P2P record with randomized level
3. P2pMessageProcessor picks up pending messages
4. Message sent to all accepted contacts (broadcast)
5. Each contact forwards to their contacts (level--)
6. Process continues until recipient found or level=0

**Inbound Response (Rp2pService):**

1. Recipient receives P2P request
2. Recipient sends RP2P response back along route
3. Intermediaries forward RP2P (reverse path)
4. Original sender receives acceptance
5. Direct transaction now possible

### Transport Modes

| Mode | URL Pattern | Use Case |
|------|-------------|----------|
| HTTP | `http://hostname` | Local testing only |
| HTTPS | `https://hostname` | Production with SSL |
| Tor | `http://xxx.onion` | Anonymous communication |

**Priority:** Tor > HTTPS > HTTP (security preference)

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
               v
         +----------+
         | accepted |
         +----+-----+
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
3. Generate or install SSL certificates
   - Check /ssl-certs/ for external certs
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
1. getLogger()           -> Initialize SecureLogger
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
5. If userconfig.json exists:
   - loadCurrentUser()   -> UserContext MUST load first
   - loadServiceContainer()
   - loadUtilityServiceContainer()
   - wireAllServices()   -> Wire circular dependencies
   - runTransactionRecovery() (CLI only)
```

**Important:** `UserContext` MUST initialize BEFORE `ServiceContainer`. Violating this
order causes runtime crashes.

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

### Encrypted Storage

| Item | Encryption | File |
|------|------------|------|
| Private Key | AES-256-GCM | `/etc/eiou/userconfig.json` |
| Auth Code | AES-256-GCM | `/etc/eiou/userconfig.json` |
| Mnemonic | AES-256-GCM | Displayed once, not stored |

### Transport Security

| Layer | Protection |
|-------|------------|
| HTTPS | TLS 1.2+ with auto-generated or custom certificates |
| Tor | Onion routing for IP anonymization |
| Message Signing | secp256k1 ECDSA signatures on all messages |

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
                    ┌─────────────────────────────────────────┐
                    │           ErrorHandler.php              │
                    │  (Global safety net - set_exception_    │
                    │   handler for truly uncaught errors)    │
                    └─────────────────────────────────────────┘
                                       ▲
                                       │ (only if not caught below)
                    ┌──────────────────┴──────────────────┐
                    │                                     │
        ┌───────────┴───────────┐         ┌──────────────┴──────────────┐
        │      Eiou.php         │         │      ApiController          │
        │    (CLI Entry)        │         │      (API Entry)            │
        ├───────────────────────┤         ├─────────────────────────────┤
        │ catch Validation →    │         │ catch ServiceException →    │
        │   format + exit(1)    │         │   use getMessage()          │
        │                       │         │   use getHttpStatus()       │
        │ catch Fatal →         │         │   use getErrorCode()        │
        │   format + exit(1)    │         │                             │
        │                       │         │ catch Exception →           │
        │ catch Recoverable →   │         │   generic 500 error         │
        │   format + exit(0)    │         │                             │
        └───────────────────────┘         └─────────────────────────────┘
                    ▲                                     ▲
                    │                                     │
        ┌───────────┴─────────────────────────────────────┴───────────┐
        │                    Service Layer                             │
        │  ContactService, MessageService, WalletService, etc.        │
        │                                                              │
        │  throw ValidationServiceException("Invalid name", ...)       │
        │  throw FatalServiceException("Wallet not found", ...)        │
        └──────────────────────────────────────────────────────────────┘
```

### ServiceException Hierarchy

The `ServiceException` classes (`/src/exceptions/`) provide structured error handling
for business logic errors, replacing direct `exit()` calls in service methods.

```
ServiceException (abstract)
    │
    ├── FatalServiceException
    │   └── Unrecoverable errors (missing wallet, unauthorized access)
    │   └── Exit code: 1
    │
    ├── RecoverableServiceException
    │   └── Retryable errors (network timeouts, temporary unavailability)
    │   └── Exit code: 0 (configurable)
    │
    └── ValidationServiceException
        └── Input validation errors (invalid address, invalid name)
        └── Exit code: 1
        └── Includes field name for targeted error display
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
| Formatters | `/etc/eiou/src/formatters/` |
| Utilities | `/etc/eiou/src/services/utilities/` |

---

*Document generated from source code analysis. For the latest information, refer to
the source files directly.*
