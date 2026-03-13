# eIOU Docker Test Suite

> **Note:** This document covers **integration/shell tests** (E2E tests run via Docker).
> For **PHPUnit unit tests**, see [Testing Guide](../docs/TESTING.md).

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Quick Start](#quick-start)
4. [Test Organization](#test-organization)
5. [Running Tests](#running-tests)
6. [Test Dependencies](#test-dependencies)
7. [Environment Variables](#environment-variables)
8. [Adding New Tests](#adding-new-tests)
9. [Benchmarks](#benchmarks)
10. [Troubleshooting](#troubleshooting)
11. [Test Results](#test-results)
12. [CI/CD Integration](#cicd-integration)

## Overview

Automated test suite for validating eIOU Docker node deployments. The suite tests multi-node network topologies including contact management, transaction processing, message routing, chain synchronization, Tor/SSL connectivity, and routing performance.

### Test Types

| Type | Location | Command | Purpose |
|------|----------|---------|---------|
| **Integration tests** | `tests/` (this README) | `./run-all-tests.sh` | E2E testing of Docker deployments |
| **Unit tests** | `tests/Unit/` | `composer test` | PHPUnit tests for individual components |
| **Benchmarks** | `tests/` | `./benchmark-*.sh` | Routing performance and fee optimization measurement |

## Prerequisites

- Docker installed and running
- Docker Compose installed
- Sufficient system memory (varies by topology - see table below)
- Bash shell environment
- For WSL2 users: May require increased initialization timeouts

> **WARNING: Running the test suite will destroy containers and volumes matching the
> test topology names.** The build scripts remove and recreate containers along with
> their associated volumes (`<name>-mysql-data`, `<name>-files`, `<name>-backups`).
> Container names per topology:
> - `http4`: `httpA`, `httpB`, `httpC`, `httpD`
> - `http10`: `httpA` through `httpJ`
> - `http13`: `A0`, `A1`, `A11`, `A12`, `A2`, `A21`, `A22`, `A3`, `A31`, `A32`, `A4`, `A41`, `A42`
> - `collisions`: `A0` through `A12` (13 nodes, A12 isolated)
> - `collisionscluster`: `C0`, `N1`-`N8`, `E1`-`E8`, `S1`-`S8`, `W1`-`W8`, `MH`, `MH2`, `LN1`-`LN3`, `LS1`-`LS2`, `ISO`, and branch nodes (53 total)
>
> The Docker network `eiou-network` is also created if it does not exist. If you have
> any existing containers or volumes with these names, **they will be permanently
> deleted**. The `eiou/eiou` Docker image will also be rebuilt, overwriting any
> existing image with that tag.

## Quick Start

```bash
cd tests/
./run-all-tests.sh http4
```

This runs the full test suite against a 4-node linear topology using HTTP mode.

## Test Organization

```
tests/
├── run-all-tests.sh                  # Main test runner script
├── benchmark-bestfee.sh              # Best-fee quality benchmark (see Benchmarks)
├── benchmark-routing.sh              # Routing performance benchmark (see Benchmarks)
├── baseconfig/
│   ├── config.sh                     # Shared configuration and utility functions
│   └── testHelpers.sh                # Common test helper functions
├── buildfiles/
│   ├── http4.sh                      # 4-node linear topology
│   ├── http10.sh                     # 10-node linear topology
│   ├── http13.sh                     # 13-node hierarchical cluster
│   ├── collisions.sh                 # 13-node mesh with random fees + isolated node (A12)
│   └── collisionscluster.sh          # 53-node cluster with random fees + isolated node (ISO)
└── testfiles/
    ├── hostnameTest.sh               # HTTP/HTTPS prerequisite test
    ├── addContactsTest.sh            # Contact addition workflow
    ├── mutualContactTest.sh          # Mutual contact request auto-accept
    ├── sendMessageTest.sh            # Basic messaging
    ├── balanceTest.sh                # Balance operations
    ├── sendAllPeersTest.sh           # Broadcast to all peers
    ├── routingTest.sh                # Multi-hop message routing
    ├── contactListTest.sh            # Contact list operations
    ├── contactNameTest.sh            # Contact name management
    ├── transactionTestSuite.sh       # Transaction tests
    ├── negativeFinancialTest.sh      # Financial error scenarios (14+ test cases)
    ├── messageDeliveryTest.sh        # Message delivery verification
    ├── curlErrorHandlingTest.sh      # HTTP error handling
    ├── cliCommandsTest.sh            # CLI command tests
    ├── apiEndpointsTest.sh           # API endpoint tests
    ├── securityTestSuite.sh          # Security test suite
    ├── apiInputValidationTest.sh     # API input validation (15+ test cases)
    ├── syncTestSuite.sh              # Chain synchronization
    ├── chunkedSyncTest.sh            # Chunked chain sync
    ├── chainDropTestSuite.sh         # Chain drop agreement + backup recovery (13 sections)
    ├── gracefulShutdownTest.sh       # Graceful shutdown behavior
    ├── sigTermTest.sh                # SIGTERM signal handling
    ├── transactionRecoveryTest.sh    # Transaction recovery after failure
    ├── seedphraseTestSuite.sh        # Seed phrase restore, display, authcode
    ├── backupTestSuite.sh            # Backup and restore operations
    ├── processorLockfileTest.sh      # Processor lockfile management
    ├── pingTestSuite.sh              # Contact ping/status tests
    ├── serviceInterfaceTest.sh       # Service interface verification
    ├── serviceExceptionTest.sh       # Service exception handling
    ├── nodeIdentityTest.sh           # Node identity management
    ├── bestFeeRoutingTest.sh         # Best-fee route selection
    ├── cascadeCancelTest.sh          # Cascade cancellation via isolated node (A12/ISO)
    ├── maxLevelCancelTest.sh         # Max-level cancellation via isolated node (A12/ISO)
    ├── routeCancellationTest.sh      # Route cancellation handling
    ├── parallelBroadcastTest.sh      # Parallel broadcast delivery
    ├── performanceBaseline.sh        # Performance benchmarks
    ├── sslCertificateTest.sh         # SSL certificate validation
    ├── torTestSuite.sh               # Tor network tests
    └── circularDependencyCheck.sh    # PHP circular dependency checker (standalone)
```

## Running Tests

### Full Test Suite

```bash
./run-all-tests.sh <build> [mode] [subset]
```

**Arguments:**
- `build` - Topology to test (required): `http4`, `http10`, `http13`, `collisions`, `collisionscluster`
- `mode` - Transport mode (default: `http`): `http`, `https`, `tor`
- `subset` - Test subset (default: `all`): See subsets below

**Examples:**
```bash
./run-all-tests.sh http4                       # All tests, HTTP mode
./run-all-tests.sh http4 https                 # All tests, HTTPS mode
./run-all-tests.sh http4 http quick            # Quick validation tests
./run-all-tests.sh http4 http contacts         # Contact management tests
./run-all-tests.sh http10 tor all              # Full suite with Tor on 10-node topology
./run-all-tests.sh collisions http bestfee     # Best-fee routing tests on collision topology
```

### Available Topologies

| Topology | Nodes | Memory  | Description |
|----------|-------|---------|-------------|
| `http4`  | 4     | ~1.1GB  | Linear chain: A <-> B <-> C <-> D |
| `http10` | 10    | ~2.8GB  | Extended linear: A through J (max 5-hop routing by default) |
| `http13` | 13    | ~3.5GB  | Hierarchical cluster with central hub (A0) and 4 branches |
| `collisions` | 13 | ~3.5GB  | Mesh topology (A0-A11) with random fees and one isolated node (A12) for cancel testing |
| `collisionscluster` | 53 | ~14GB | 4-arm cluster with random fees, hub C0, mesh hubs, skip connections, cross-arm links, and one isolated node (ISO) for cancel testing |

The `collisions` and `collisionscluster` topologies assign random fee multipliers to each contact relationship. This creates varied routing costs across the network, which is essential for testing best-fee route selection and benchmarking routing performance. Both topologies include an isolated node with zero contacts (`A12` in collisions, `ISO` in collisionscluster) used for cascade cancel testing — sending to an unreachable node exercises the cancellation and rollback paths.

### Transport Modes

| Mode    | Description |
|---------|-------------|
| `http`  | Test containers with `http://` addresses |
| `https` | Test containers with `https://` addresses (SSL enabled) |
| `tor`   | Test containers with `.onion` addresses (Tor network) |

### Test Subsets

| Subset        | Description | Tests Included |
|---------------|-------------|----------------|
| `all`         | Complete test suite (default) | All tests in dependency order |
| `quick`       | Fast validation smoke tests | addContactsTest, sendMessageTest, balanceTest |
| `contacts`    | Contact management | addContactsTest, contactListTest, contactNameTest, pingTestSuite |
| `transactions`| Transaction operations | addContactsTest, balanceTest, transactionTestSuite, transactionRecoveryTest, negativeFinancialTest |
| `messaging`   | Message delivery and routing | addContactsTest, sendMessageTest, sendAllPeersTest, routingTest, messageDeliveryTest |
| `api`         | API endpoints and CLI | addContactsTest, curlErrorHandlingTest, cliCommandsTest, apiEndpointsTest, securityTestSuite, apiInputValidationTest, serviceExceptionTest |
| `sync`        | Chain synchronization | addContactsTest, sendMessageTest, pingTestSuite, syncTestSuite, chunkedSyncTest, chainDropTestSuite |
| `connections` | SSL and Tor connectivity | sslCertificateTest, torTestSuite |
| `system`      | System-level operations | gracefulShutdownTest, sigTermTest, seedphraseTestSuite, processorLockfileTest, serviceInterfaceTest, serviceExceptionTest, nodeIdentityTest, backupTestSuite |
| `performance` | Performance benchmarks | addContactsTest, performanceBaseline (DB queries, API response times, crypto operations) |
| `bestfee`     | Best-fee routing | addContactsTest, bestFeeRoutingTest, cascadeCancelTest, maxLevelCancelTest, routeCancellationTest, parallelBroadcastTest |
| `mutual`      | Mutual contact auto-accept | mutualContactTest (runs before addContactsTest for clean state) |

**Note:** Many subsets automatically include `addContactsTest` as a prerequisite since contacts must exist before other operations can be tested.

## Test Dependencies

Tests run in a specific order to ensure dependencies are met:

1. **Prerequisite test** - `hostnameTest` (HTTP/HTTPS) or `torAddressTest` (Tor mode)
2. **Connection tests** - SSL certificates, Tor connectivity
3. **Mutual contacts** - `mutualContactTest` (requires clean state, runs before contact setup)
4. **Contact setup** - `addContactsTest` (required for most subsequent tests)
5. **Core functionality** - Messaging, transactions, routing
6. **Advanced features** - Sync, recovery, API endpoints, security
7. **System tests** - Shutdown, signal handling, lockfiles, seedphrase, backups
8. **Service validation** - Interface checks, exception handling, node identity
9. **Best-fee routing** - Route selection, cancellation, parallel broadcast
10. **Performance** - Baseline benchmarks

The test runner handles this automatically - you do not need to manually manage test order.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `EIOU_INIT_TIMEOUT` | 120 | Container initialization timeout in seconds |
| `EIOU_CONTACT_STATUS_ENABLED` | false (during tests) | Contact status pinging (disabled to prevent interference with sync tests) |
| `EIOU_HOP_BUDGET_RANDOMIZED` | false (during tests) | Hop budget randomization (disabled for deterministic routing depth) |
| `TEST_TIMEOUT` | 30 | Default timeout for adaptive polling functions (seconds) |
| `TEST_POLL_INTERVAL` | 1 | Default polling interval for wait functions (seconds) |
| `SKIP_CLEANUP` | 0 | Set to `1` to preserve containers after test completion |
| `JUNIT_REPORT_PATH` | (unset) | Path to write JUnit XML report (e.g., `./test-results/integration.xml`) |

**WSL2/Slow Environment Tip:** If tests timeout waiting for containers or conditions, increase the timeouts:
```bash
EIOU_INIT_TIMEOUT=180 TEST_TIMEOUT=60 ./run-all-tests.sh http4
```

## Adding New Tests

### 1. Create Test Script

Create a new file in `testfiles/` following the header template:

```bash
#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Test Name ############################
# Brief description of what this test validates
#
# Verifies:
# - First verification point
# - Second verification point
#
# Prerequisites:
# - Required container state
# - Required prior tests (e.g., addContactsTest)
#########################################################################

# Test implementation here...

############################ Testing #############################

testname="myNewTest"
totaltests=5
passed=0
failure=0

# Run tests and update passed/failure counts
# ...

succesrate "${totaltests}" "${passed}" "${failure}" "'myNewTest'"

##################################################################
```

### 2. Use Test Conventions

Tests must set these variables for the runner to track results:
- `totaltests` - Total number of test cases
- `passed` - Number of passed tests
- `failure` - Number of failed tests

Use the `succesrate` function from `config.sh` for consistent output formatting.

### 3. Register in run-all-tests.sh

Add your test to the appropriate subset array(s) in `run-all-tests.sh`:

```bash
# Example: Add to TESTS_ALL and a subset
TESTS_ALL="
...
myNewTest
"

TESTS_API="
addContactsTest
myNewTest
"
```

### 4. Use Helper Functions

Common helpers from `testHelpers.sh`:

| Function | Purpose |
|----------|---------|
| `validate_test_prerequisites` | Verify containers are running |
| `get_container_pair` | Get sender/receiver for tests |
| `wait_for_condition` | Poll until condition met (configurable timeout/interval) |
| `wait_for_container_health` | Wait for container PHP readiness |
| `wait_for_container_initialized` | Wait for full container startup including MariaDB |
| `wait_for_file` | Wait for file to exist in container |
| `wait_for_process_stop` | Wait for process to terminate |
| `wait_for_process_start` | Wait for process to start |
| `wait_for_queue_processed` | Process message queues and wait |
| `wait_for_contact_status` | Wait for contact status to reach expected value |
| `wait_for_tx_count_reached` | Wait for transaction count threshold |
| `ensure_contacts` | Add bidirectional contacts |
| `check_tor_running` | Verify Tor service status |
| `verify_chain_integrity` | Validate transaction chain |

**Adaptive Polling:** All `wait_for_*` functions use adaptive polling instead of fixed sleeps. Configure timeouts via `TEST_TIMEOUT` and `TEST_POLL_INTERVAL` environment variables.

## Benchmarks

The benchmark scripts are standalone tools for measuring routing performance. Unlike the integration test suite, benchmarks are not run by `run-all-tests.sh` and do not contribute to pass/fail counts. They build their own topologies, run repeated measurements, and produce statistical summaries.

**Startup time:** Each iteration that rebuilds the topology requires all containers to fully initialize, including Tor hidden service creation. This takes several minutes per iteration. When running many iterations (especially with `rebuild` mode or `benchmark-bestfee.sh` which rebuilds every run), expect long total runtimes. Tor can also become unreliable when many nodes are created in rapid succession — if Tor initialization times out, the benchmark will fail on that iteration. Using `http` mode or the `shared` topology mode avoids repeated Tor startup overhead.

### benchmark-bestfee.sh

Measures the quality of best-fee route selection by running the `bestfee` test subset repeatedly with fresh random fees each time. Each run rebuilds the `collisions` topology so that fee multipliers are randomized, then compares fast mode (first route found) against best-fee mode (collects all routes, picks cheapest).

**Usage:**

```bash
./benchmark-bestfee.sh [runs] [mode]
```

| Argument | Default | Description |
|----------|---------|-------------|
| `runs` | 10 | Number of benchmark runs |
| `mode` | http | Transport mode: `http` or `tor` |

**Examples:**
```bash
./benchmark-bestfee.sh              # 10 runs, HTTP mode
./benchmark-bestfee.sh 20           # 20 runs, HTTP mode
./benchmark-bestfee.sh 5 tor        # 5 runs, Tor mode
```

**What it measures:**

For each run, the script captures:
- Timing for fast mode vs best-fee mode (seconds)
- Fee multiplier for each mode's chosen path
- Optimal fee multiplier (lowest possible across all routes)
- Actual paths taken by each mode

Results are categorized as:

| Category | Meaning |
|----------|---------|
| OPTIMAL | Best-fee mode found the cheapest possible route |
| BETTER | Best-fee found a cheaper route than fast mode, but not the absolute cheapest |
| SAME (optimal) | Both modes found the cheapest route |
| SAME (sub-optimal) | Both modes found the same route, but a cheaper one existed |
| WORSE | Fast mode found a cheaper route than best-fee mode |

**Output summary includes:**
- Route quality distribution (count per category)
- Average timing for each mode and overhead of best-fee vs fast
- Average fee multipliers (fast, best-fee, optimal)
- Average fee savings of best-fee over fast mode
- Per-run detail table with timing, fees, and paths

### benchmark-routing.sh

Systematic routing performance benchmark for the `collisionscluster` topology. Measures routing across all combinations of protocol, hop distance, and routing mode, producing detailed timing breakdowns.

**Usage:**

```bash
./benchmark-routing.sh [runs] [protocols] [topology] [send_mode]
```

| Argument | Default | Description |
|----------|---------|-------------|
| `runs` | 10 | Number of sends per condition |
| `protocols` | http,https,tor | Comma-separated list of protocols to test |
| `topology` | shared | `shared` (build once) or `rebuild` (fresh topology per protocol) |
| `send_mode` | serial | `serial` (one at a time) or `burst` (fire all at once) |

**Examples:**
```bash
./benchmark-routing.sh 10                              # Full benchmark, all protocols
./benchmark-routing.sh 3 http                           # Quick test, HTTP only
./benchmark-routing.sh 5 http,https                     # HTTP and HTTPS
./benchmark-routing.sh 10 http,https,tor rebuild        # Fresh topology per protocol
./benchmark-routing.sh 3 http shared burst              # Burst mode, HTTP only
```

**Test matrix:**

The benchmark tests every combination of:
- **Protocols**: http, https, tor (or a subset)
- **Hop distances**: 1-hop, 3-hop, 6-hop (from sender C0)
- **Routing modes**: fast (first route wins), bestfee (collect all, pick cheapest)

Total sends per run = protocols x 3 distances x 2 modes x runs.

**Timing breakdown:**

Each send produces four timing measurements derived from database timestamps:

```
|-- CLI overhead --|------- P2P search -------|---- settlement ----|-- poll jitter --|
|                  |                          |                    |                 |
eiou send     P2P created_at            P2P created_at       P2P completed_at   balance
(wall clock)  on SENDER                 on TARGET            on SENDER           detected
               |                          |                    |
               |<-------- p2p_time -------------------------------->|
               |<-- search_time --------->|<-- settle_time --->|
|<----------------------- wall_time ------------------------------------------>|
```

| Metric | Definition |
|--------|------------|
| `wall_time` | Wall clock from send command to balance change detected (includes CLI overhead and poll jitter) |
| `p2p_time` | P2P record created to completed on sender (full round-trip through the network) |
| `search_time` | P2P created on sender to P2P created on target (network propagation / route discovery) |
| `settle_time` | P2P created on target to P2P completed on sender (transaction chain settlement back) |

**Topology modes:**

| Mode | Behavior |
|------|----------|
| `shared` | Build the topology once and reuse it across all protocols. Same fee structure, faster overall. |
| `rebuild` | Build a fresh topology for each protocol. Different random fees each time, isolates protocol from fee structure. |

**Send modes:**

| Mode | Behavior |
|------|----------|
| `serial` | Send one transaction at a time, wait for completion, collect results, then repeat. Measures individual transaction latency. |
| `burst` | Fire all transactions for a condition at once, wait for all to complete, then collect results from the database. Keeps message processors warm (100ms polling) and measures throughput under load. |

**Output includes:**
- Per-condition summary table (protocol x distance x mode) with median/p95/max timing
- Per-run detail with path taken, fee multiplier, and all four timing metrics
- Fee optimality comparison against the best possible route
- Grand summary across all conditions

## Troubleshooting

### Container Initialization Timeout

**Symptom:** Tests fail with "Timeout waiting for initialization!"

**Solutions:**
1. Increase timeout: `EIOU_INIT_TIMEOUT=180 ./run-all-tests.sh http4`
2. Check Docker resource limits
3. Verify sufficient system memory for topology
4. On WSL2: Ensure adequate WSL memory allocation in `.wslconfig`

### No eIOU Containers Found

**Symptom:** "No eiou containers found!" error

**Solutions:**
1. Verify Docker is running: `docker ps`
2. Check build completed successfully
3. Look for build errors in output
4. Ensure Docker image built: `docker images | grep eiou`

### Contact Status Never Accepted

**Symptom:** Contact tests fail with status "pending"

**Solutions:**
1. Wait longer - contact processing may be slow
2. Check container logs: `docker logs httpA`
3. Verify network connectivity between containers
4. Ensure message processors started (check for "message processing started successfully" in logs)

### Tor Tests Skipped

**Symptom:** Tor tests show "SKIPPED (HTTP mode)"

**Explanation:** In HTTP mode, Tor tests are optional. To run Tor tests:
```bash
./run-all-tests.sh http4 tor connections
```

### Transaction Chain Sync Failures

**Symptom:** Sync tests fail with chain integrity errors

**Solutions:**
1. Ensure sufficient time between transactions
2. Check previous tests completed successfully
3. Verify contact status is "accepted" on both ends
4. Review transaction chain: use `get_chain_info` helper for debugging

### Build Failures

**Symptom:** "Build failed!" during topology creation

**Solutions:**
1. Check Dockerfile syntax
2. Verify base image requirements
3. Review Docker build output for specific errors
4. Ensure sufficient disk space for images

## Test Results

The runner displays a summary after completion:

```
================================================================
                    TEST SUITE SUMMARY
================================================================
Build:          http4
Mode:           http
Subset:         all
Total Tests:    42
Passed:         42
Failed:         0
Success Rate:   100.0%
================================================================

All tests passed successfully!
```

Exit codes:
- `0` - All tests passed
- `1` - One or more tests failed

### JUnit XML Reports

Set `JUNIT_REPORT_PATH` to generate a JUnit XML report for CI integration:

```bash
JUNIT_REPORT_PATH=./test-results/integration.xml ./run-all-tests.sh http4
```

The report includes per-test timing, pass/fail status, and failure messages.

## CI/CD Integration

For automated pipelines, use the exit code to gate deployments:

```bash
./run-all-tests.sh http4 http quick || exit 1
echo "Tests passed, proceeding with deployment..."
```

Recommended CI test order:
1. `quick` subset on PR (fast feedback)
2. `all` subset on merge to main (comprehensive validation)
3. `tor` mode tests periodically (network connectivity)
