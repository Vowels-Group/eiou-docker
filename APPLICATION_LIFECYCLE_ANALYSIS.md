# Application Lifecycle Architecture Analysis

## Executive Summary

This document analyzes the EIOU Docker application's lifecycle architecture, specifically addressing the question of whether the Application should be "always running" (persistent) versus spinning up and down with each task (ephemeral).

**Conclusion**: The current persistent daemon architecture is the **correct approach** for message processors. The Application singleton persists for the lifetime of each PHP daemon process. However, there is an optimization opportunity: transaction recovery currently runs on every Application instantiation, including HTTP API requests, which is inefficient.

---

## Current Architecture Overview

### Entry Points

The Application is accessed through several entry points:

1. **Message Processor Daemons** (Long-running, persistent)
   - `TransactionMessages.php` - Processes pending transactions
   - `P2pMessages.php` - Handles peer-to-peer messaging
   - `CleanupMessages.php` - Performs cleanup operations
   - `ContactStatusMessages.php` - Manages contact status polling

2. **HTTP API** (Request-response, ephemeral per request)
   - `Api.php` - RESTful API endpoint

### Application Singleton Pattern

```php
// files/src/core/Application.php
class Application {
    private static ?Application $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

The singleton pattern ensures only one Application instance exists **per PHP process**.

### PHP Execution Model

Understanding PHP's execution model is critical:

- Each HTTP request spawns a **new PHP-FPM process** (or reuses one from the pool)
- Each CLI script runs in its **own isolated PHP process**
- The singleton pattern only persists within a single process
- When a process ends, all its state (including singletons) is destroyed

---

## How Message Processors Actually Work

### Startup Flow

```bash
# From startup.sh (lines 641-658)
nohup php /etc/eiou/P2pMessages.php > /dev/null 2>&1 &
nohup php /etc/eiou/TransactionMessages.php > /dev/null 2>&1 &
nohup php /etc/eiou/CleanupMessages.php > /dev/null 2>&1 &
nohup php /etc/eiou/ContactStatusMessages.php > /dev/null 2>&1 &
```

Each processor is started as a **background daemon** that runs continuously.

### Processor Execution Pattern

```php
// TransactionMessages.php
$app = Application::getInstance();  // Called ONCE at startup
$processor = $app->getTransactionMessageProcessor();
$processor->run();  // Runs in an infinite loop
```

The `run()` method in `AbstractMessageProcessor` implements an **infinite loop** with adaptive polling:

```php
// AbstractMessageProcessor.php (simplified)
public function run(): void {
    $this->createLockfile();
    $this->running = true;

    while ($this->running) {
        $this->checkSignals();
        $processed = $this->processMessages();
        $this->adaptiveWait($processed);
    }

    $this->removeLockfile();
}
```

**Key Insight**: The Application singleton is created once and stays in memory for the entire lifetime of the daemon process. It does NOT "spin up each time" for message processing.

---

## Transaction Recovery Analysis

### When Recovery Runs

Transaction recovery is triggered in the Application constructor:

```php
// Application.php (lines 93-94)
// Run transaction recovery to handle any stuck transactions from previous crashes
$this->runTransactionRecovery();
```

This means recovery runs on **every Application instantiation**:

| Entry Point | Application Instantiation | Recovery Runs |
|-------------|---------------------------|---------------|
| Message processor startup | Once per daemon | Once |
| Processor restart (watchdog) | Once per restart | Once |
| HTTP API request | Once per request | Every request |

### The Identified Issue

**Problem**: Transaction recovery runs on every HTTP API request. This is inefficient because:

1. Unnecessary database queries on every API call
2. Potential race conditions if multiple requests try to recover the same transactions
3. Slower API response times
4. Recovery is only needed for daemon processors (which handle transactions)

---

## Persistent vs Ephemeral: Evaluation

### Arguments FOR Persistent Daemon Architecture (Current Design)

| Factor | Reasoning |
|--------|-----------|
| Transaction Recovery | Designed to handle crash scenarios - runs on daemon startup, recovers stuck transactions |
| Database Persistence | MariaDB data persists in Docker named volumes across restarts |
| Wallet/Key Security | User keys in `/etc/eiou/` MUST persist - ephemeral would mean lost funds |
| Transaction Chain Validation | `previous_txid` chain requires historical data |
| Graceful Shutdown | 45-second grace period allows orderly service termination |
| Watchdog Recovery | Automatic processor restart on crash (max 10 retries) |
| Memory Efficiency | Service instances cached in Application (`$processors`, `$utils`, `$services`) |

### Arguments AGAINST Ephemeral Architecture

| Factor | Problem |
|--------|---------|
| External State Store | Would need Redis/external DB for transaction state |
| Recovery Redesign | Entire recovery system would need rewrite |
| Chain Validation | Would break without local transaction history |
| Key Management | Would need external secure key storage (HSM, Vault, etc.) |
| Complexity | Significantly more complex deployment architecture |
| Latency | Cold start penalty on every invocation |

### Verdict

**The persistent daemon architecture is correct.** The Application should NOT be made ephemeral.

The user's concern about "Application spinning up each time" is likely observing:
1. Recovery log messages on daemon startup (intended behavior)
2. Or API requests triggering recovery (unintended, should be fixed)

---

## Recommendations

### 1. Gate Recovery to CLI/Daemon Only

The Application already has an `isCli()` method. Use it to prevent recovery on HTTP requests:

```php
// Proposed change in Application.php constructor
if ($this->isCli()) {
    // Run transaction recovery only for CLI/daemon processes
    $this->runTransactionRecovery();
}
```

### 2. Add Recovery Lock (Optional Enhancement)

Prevent concurrent recovery attempts if multiple daemons start simultaneously:

```php
private function runTransactionRecovery(): void {
    $lockFile = '/tmp/eiou_recovery.lock';

    // Acquire exclusive lock
    $fp = fopen($lockFile, 'c');
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Another process is running recovery
        fclose($fp);
        return;
    }

    try {
        $recoveryService = $this->services->getTransactionRecoveryService();
        $results = $recoveryService->recoverStuckTransactions();
        // ... logging ...
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
```

### 3. Document the Architecture

Add clear documentation about:
- The daemon-based architecture for message processors
- Why persistence is critical for financial integrity
- The watchdog's role in automatic recovery

---

## Summary

| Question | Answer |
|----------|--------|
| Should Application be always running? | **Yes** - for message processors (daemons) |
| Should Application spin up/down per task? | **No** - ephemeral would break financial integrity |
| Is the current architecture correct? | **Yes** - persistent daemons are the right approach |
| Is there room for improvement? | **Yes** - gate recovery to CLI processes only |

---

## Files Analyzed

- `files/src/core/Application.php` - Application singleton
- `files/root/TransactionMessages.php` - Transaction processor entry point
- `files/root/Api.php` - HTTP API entry point
- `files/src/processors/AbstractMessageProcessor.php` - Base processor class
- `files/src/services/TransactionRecoveryService.php` - Recovery service
- `startup.sh` - Container entrypoint with daemon startup
- `docker-compose-single.yml` - Docker configuration

---

*Analysis performed: 2026-01-20*
*Branch: claudeflow-ai-dev-260120-2150-app-lifecycle-analysis*
