# EIOU Docker Test Suite Documentation

## Overview

The EIOU test suite provides comprehensive integration testing for the EIOU Docker containers. It supports multiple topologies and provides various utilities for debugging, logging, and test management.

## Quick Start

```bash
# Run pre-flight checks
./preflight-checks.sh http4

# Run full test suite
./run-all-tests.sh http4 http

# Clean up after tests
./cleanup.sh -f
```

## Test Topologies

| Topology | Containers | Memory | Disk | Use Case |
|----------|------------|--------|------|----------|
| http4 | 4 | 2GB | 5GB | Quick validation |
| http10 | 10 | 4GB | 15GB | Medium testing |
| http13 | 13 | 6GB | 20GB | Full testing |

## Test Files

The test suite consists of the following test files:

| File | Description |
|------|-------------|
| `hostnameTest.sh` | Validates container hostnames and addresses |
| `addContactsTest.sh` | Tests contact addition functionality |
| `sendMessageTest.sh` | Tests message delivery between nodes |
| `balanceTest.sh` | Tests balance operations and synchronization |
| `routingTest.sh` | Tests multi-hop message routing |
| `contactListTest.sh` | Tests contact listing functionality |
| `transactionHistoryTest.sh` | Tests transaction history retrieval |
| `messageDeliveryTest.sh` | Tests message delivery infrastructure |
| `cliTest.sh` | Tests CLI command functionality |
| `apiEndpointTest.sh` | Tests REST API endpoints |
| `syncTest.sh` | Tests balance synchronization |
| `seedPhraseRestoreTest.sh` | Tests wallet backup/restore |

## Utility Scripts

### Pre-flight Checks (`preflight-checks.sh`)

Validates the test environment before running tests:

```bash
# Basic check
./preflight-checks.sh

# Check for specific topology
./preflight-checks.sh http10
```

Validates:
- Docker daemon availability
- Docker version
- System resources (disk, memory)
- Required dependencies
- Network availability
- Stale containers

### Retry Framework (`retry-framework.sh`)

Source this file to use retry functionality in tests:

```bash
source ./retry-framework.sh

# Retry a command with exponential backoff
retry_with_backoff -a 5 -t 2 docker exec httpA eiou balance

# Wait for a condition
wait_for_condition "docker exec httpA test -f /etc/eiou/userconfig.json" 60 2 "config file"

# Wait for container to be healthy
wait_for_container_healthy httpA 120

# Wait for HTTP endpoint
wait_for_http_ready http://localhost:8080 30
```

### Timeout Configuration (`config-timeouts.sh`)

Provides topology-aware timeout values:

```bash
source ./config-timeouts.sh

# Auto-configure based on BUILD_NAME
export BUILD_NAME="http10"
configure_timeouts

# Or explicitly set topology
configure_timeouts 10

# Use timeouts in tests
sleep $TIMEOUT_MESSAGE
```

Available timeout variables:
- `TIMEOUT_INIT` - Container initialization
- `TIMEOUT_MESSAGE` - Message delivery
- `TIMEOUT_ROUTING` - Multi-hop routing
- `TIMEOUT_TRANSACTION` - Transaction processing
- `TIMEOUT_BALANCE_SYNC` - Balance synchronization
- `TIMEOUT_CONTACT_ADD` - Contact addition
- `TIMEOUT_CONTAINER_START` - Container startup
- `TIMEOUT_CONTAINER_HEALTHY` - Health check pass
- `TIMEOUT_TOR` - Tor connection establishment

### Log Aggregator (`log-aggregator.sh`)

Collects and organizes test logs:

```bash
source ./log-aggregator.sh

# Initialize logging
init_logging

# Log test results
log_test_result "balance_test" "PASSED" 150 "Balance verified"

# Capture container logs (at end of test)
capture_container_logs

# Generate final report
finalize_logging
```

Outputs:
- Container logs (stdout/stderr)
- PHP error logs
- Apache error logs
- Container inspect JSON
- System state information
- JSON test report

### Diagnostics (`diagnose.sh`)

Debug failing containers:

```bash
# List all test containers
./diagnose.sh

# Diagnose specific container
./diagnose.sh httpA
```

Shows:
- Container state
- Network configuration
- Service status
- File system check
- Database connectivity
- Recent logs
- Network connectivity

### Cleanup (`cleanup.sh`)

Clean up test environment:

```bash
# Interactive cleanup
./cleanup.sh

# Force cleanup (no prompts)
./cleanup.sh -f

# Keep images, only remove containers
./cleanup.sh -f -i

# Verbose output
./cleanup.sh -f -v
```

## Configuration

### Base Config (`baseconfig/config.sh`)

Contains shared configuration and utilities:
- Color codes for output
- Container name patterns
- Path variables for Docker exec
- Common utility functions

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `BUILD_NAME` | Topology name (http4, http10, http13) | http4 |
| `MODE` | Network mode (http, tor) | http |
| `LOG_DIR` | Log output directory | /tmp/eiou-test-logs-* |
| `MIN_DISK_GB` | Minimum disk space required | 5 |
| `MIN_MEM_MB` | Minimum memory required | 2048 |

## Adding New Tests

1. Create a new test file in `testfiles/`:

```bash
#!/bin/bash
# testfiles/myNewTest.sh
source ../baseconfig/config.sh
source ../retry-framework.sh
source ../config-timeouts.sh

echo "Running my new test..."

# Test implementation
```

2. Add the test to `run-all-tests.sh`:

```bash
# In the test execution section
run_test "myNewTest"
```

3. Follow the test pattern:
   - Use `retry_with_backoff` for unreliable operations
   - Use timeout variables from `config-timeouts.sh`
   - Log results using `log_test_result`
   - Return 0 for success, 1 for failure

## Best Practices

### Do

- Use configurable timeouts instead of hardcoded `sleep`
- Use retry logic for network-dependent operations
- Capture logs on failure for debugging
- Clean up resources after tests
- Run pre-flight checks before test suites

### Don't

- Use hardcoded container names (use variables)
- Ignore test failures (investigate flaky tests)
- Leave containers running after tests
- Skip pre-flight validation

## Troubleshooting

### Tests Fail Intermittently

1. Run pre-flight checks to verify resources
2. Increase timeouts for your topology
3. Use `diagnose.sh` to inspect failing container
4. Check logs in `/tmp/eiou-test-logs-*/`

### Containers Won't Start

1. Check Docker daemon: `docker info`
2. Check disk space: `df -h`
3. Check for stale containers: `docker ps -a`
4. Run cleanup: `./cleanup.sh -f`

### Network Issues

1. Check Docker network: `docker network ls`
2. Verify DNS: `docker exec httpA nslookup google.com`
3. Check container connectivity: `./diagnose.sh httpA`

## CI/CD Integration

The test suite can be integrated with GitHub Actions:

```yaml
- name: Pre-flight validation
  run: ./tests/preflight-checks.sh http4

- name: Run tests
  run: ./tests/run-all-tests.sh http4 http

- name: Upload logs on failure
  if: failure()
  uses: actions/upload-artifact@v3
  with:
    name: test-logs
    path: /tmp/eiou-test-logs-*/
```
