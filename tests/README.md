# EIOU Docker Test Suite

> **Note:** This document covers **integration/shell tests** (E2E tests run via Docker).
> For **PHPUnit unit tests**, see [Testing Guide](../docs/TESTING.md).

## Overview

Automated test suite for validating EIOU Docker node deployments. The suite tests multi-node network topologies including contact management, transaction processing, message routing, chain synchronization, and Tor/SSL connectivity.

### Test Types

| Type | Location | Command | Purpose |
|------|----------|---------|---------|
| **Integration tests** | `tests/` (this README) | `./run-all-tests.sh` | E2E testing of Docker deployments |
| **Unit tests** | `tests/Unit/` | `composer test` | PHPUnit tests for individual components |

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
├── run-all-tests.sh          # Main test runner script
├── baseconfig/
│   ├── config.sh             # Shared configuration and utility functions
│   └── testHelpers.sh        # Common test helper functions
├── buildfiles/
│   ├── http4.sh              # 4-node linear topology
│   ├── http10.sh             # 10-node linear topology
│   └── http13.sh             # 13-node hierarchical cluster
└── testfiles/
    ├── hostnameTest.sh           # HTTP/HTTPS prerequisite test
    ├── addContactsTest.sh        # Contact addition workflow
    ├── sendMessageTest.sh        # Basic messaging
    ├── balanceTest.sh            # Balance operations
    ├── transactionTestSuite.sh   # Transaction tests
    ├── syncTestSuite.sh          # Chain synchronization
    ├── torTestSuite.sh           # Tor network tests
    ├── sslCertificateTest.sh     # SSL certificate validation
    ├── chainDropTestSuite.sh     # Chain drop agreement + backup recovery (13 sections)
    ├── apiInputValidationTest.sh # API input validation (15+ test cases)
    ├── negativeFinancialTest.sh  # Financial error scenarios (14+ test cases)
    ├── performanceBaseline.sh    # Performance benchmarks
    └── ... (additional test files)
```

## Running Tests

### Full Test Suite

```bash
./run-all-tests.sh <build> [mode] [subset]
```

**Arguments:**
- `build` - Topology to test (required): `http4`, `http10`, `http13`
- `mode` - Transport mode (default: `http`): `http`, `https`, `tor`
- `subset` - Test subset (default: `all`): See subsets below

**Examples:**
```bash
./run-all-tests.sh http4              # All tests, HTTP mode
./run-all-tests.sh http4 https        # All tests, HTTPS mode
./run-all-tests.sh http4 http quick   # Quick validation tests
./run-all-tests.sh http4 http contacts # Contact management tests
./run-all-tests.sh http10 tor all     # Full suite with Tor on 10-node topology
```

### Available Topologies

| Topology | Nodes | Memory  | Description |
|----------|-------|---------|-------------|
| `http4`  | 4     | ~1.1GB  | Linear chain: A <-> B <-> C <-> D |
| `http10` | 10    | ~2.8GB  | Extended linear: A through J (max 5-hop routing by default) |
| `http13` | 13    | ~3.5GB  | Hierarchical cluster with central hub (A0) and 4 branches |

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
| `contacts`    | Contact management | addContactsTest, contactListTest, pingTestSuite |
| `transactions`| Transaction operations | addContactsTest, balanceTest, transactionTestSuite, transactionRecoveryTest |
| `messaging`   | Message delivery and routing | addContactsTest, sendMessageTest, sendAllPeersTest, routingTest, messageDeliveryTest |
| `api`         | API endpoints and CLI | addContactsTest, curlErrorHandlingTest, cliCommandsTest, apiEndpointsTest, apiInputValidationTest |
| `sync`        | Chain synchronization | addContactsTest, sendMessageTest, syncTestSuite |
| `connections` | SSL and Tor connectivity | sslCertificateTest, torTestSuite |
| `system`      | System-level operations | gracefulShutdownTest, seedphraseTestSuite, processorLockfileTest |
| `performance` | Performance benchmarks | performanceBaseline (DB queries, API response times, crypto operations) |

**Note:** Many subsets automatically include `addContactsTest` as a prerequisite since contacts must exist before other operations can be tested.

## Test Dependencies

Tests run in a specific order to ensure dependencies are met:

1. **Prerequisite test** - `hostnameTest` (HTTP/HTTPS) or `torAddressTest` (Tor mode)
2. **Connection tests** - SSL certificates, Tor connectivity
3. **Contact setup** - `addContactsTest` (required for most subsequent tests)
4. **Core functionality** - Messaging, transactions, routing
5. **Advanced features** - Sync, recovery, API endpoints
6. **System tests** - Shutdown, lockfiles, seedphrase handling

The test runner handles this automatically - you do not need to manually manage test order.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `EIOU_INIT_TIMEOUT` | 90 | Container initialization timeout in seconds |
| `EIOU_CONTACT_STATUS_ENABLED` | false (during tests) | Contact status pinging (disabled to prevent interference with sync tests) |
| `TEST_TIMEOUT` | 30 | Default timeout for adaptive polling functions (seconds) |
| `TEST_POLL_INTERVAL` | 1 | Default polling interval for wait functions (seconds) |

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

## Troubleshooting

### Container Initialization Timeout

**Symptom:** Tests fail with "Timeout waiting for initialization!"

**Solutions:**
1. Increase timeout: `EIOU_INIT_TIMEOUT=180 ./run-all-tests.sh http4`
2. Check Docker resource limits
3. Verify sufficient system memory for topology
4. On WSL2: Ensure adequate WSL memory allocation in `.wslconfig`

### No EIOU Containers Found

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
