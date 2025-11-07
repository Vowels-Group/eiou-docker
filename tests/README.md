# EIOU GUI Modernization Test Suite

Comprehensive test suite for Issue #137 - GUI Architecture & Performance Modernization.

## Overview

This test suite validates the GUI modernization improvements including:
- AJAX operations and async loading
- Toast notifications and error handling
- Server-Sent Events (SSE) for real-time updates
- API caching and optimization
- Performance improvements (page load, API calls, memory)
- Cross-browser compatibility (especially Tor Browser)

## Test Categories

### 1. JavaScript Unit Tests (`gui/`)

Tests toast notification system, error handling, and retry logic.

**File:** `gui/test-toast.html`

**What it tests:**
- Toast creation and display (success, error, warning, info)
- XSS protection and HTML escaping
- Auto-dismiss timers
- Multiple toast stacking
- User-friendly error messages
- Transient error detection
- Retry logic with exponential backoff

**How to run:**
```bash
# Open in browser
firefox tests/gui/test-toast.html

# Or use Python HTTP server
cd tests/gui
python3 -m http.server 8000
# Then open http://localhost:8000/test-toast.html
```

**Expected results:**
- 15/15 tests pass
- All toast types display correctly
- No JavaScript errors in console
- XSS protection verified

### 2. API Caching Tests (`api/`)

Tests cache hit/miss rates, TTL expiration, and data integrity.

**File:** `api/test-caching.php`

**What it tests:**
- Cache initialization and basic operations
- TTL expiration (time-to-live)
- Cache invalidation
- Hit rate measurement (target: >50%)
- Memory usage tracking
- Concurrent access handling
- Large data caching
- Data type integrity

**How to run:**
```bash
cd tests/api
php test-caching.php
```

**Expected results:**
```
✅ PASS: Cache initialization - Cache object created
✅ PASS: Cache set/get - Value stored and retrieved correctly
✅ PASS: Cache TTL expiration - Value expired after TTL
...
Total:   15
Passed:  15 ✅
Failed:  0 ❌
```

**Performance targets:**
- Hit rate: ≥50%
- Memory usage: <80MB
- 1000 operations: <0.5s

### 3. Performance Benchmarks (`performance/`)

Measures page load times, API calls, memory usage, and improvements.

**File:** `performance/benchmark.php`

**What it tests:**
- Legacy vs optimized page load comparison
- API call reduction (20+ → 2-3 calls)
- Memory usage optimization
- Batch API requests
- Parallel request handling
- Cache hit ratio impact
- SSE latency
- Load testing (100 requests)

**How to run:**
```bash
# Local simulation
cd tests/performance
php benchmark.php

# Test against Docker container
php benchmark.php --docker
```

**Expected results:**
```
✅ PASS: Legacy page load baseline - Slow as expected: 1.000s
✅ PASS: Optimized page load - Fast: 0.150s
✅ PASS: Performance improvement - 85.0% faster
✅ PASS: API call reduction - 85.0% fewer calls
```

**Performance targets (from Issue #137):**
- Page load: <0.5s (was 2-3s)
- API calls: ≤3/page (was 20+)
- Memory: <80MB (was 150MB)
- SSE latency: <2s

### 4. Integration Tests (`integration/`)

End-to-end tests of complete user workflows and container health.

**File:** `integration/test-gui-flow.sh`

**What it tests:**
- Docker container status and stability
- Homepage and asset loading
- Page load performance
- Container error logs
- PHP and database connectivity
- Concurrent request handling
- Memory usage in container
- Error page handling
- Load testing (100 requests)
- Container restart recovery

**How to run:**
```bash
# Start Docker container first
docker compose -f docker-compose-single.yml up -d

# Run tests
cd tests/integration
./test-gui-flow.sh

# Skip restart test (destructive)
SKIP_RESTART_TEST=1 ./test-gui-flow.sh
```

**Expected results:**
```
==========================================
TEST SUMMARY
==========================================
Total tests: 15
Passed: 14 ✅
Failed: 0 ❌
Skipped: 1 ⏭️
Duration: 25s
```

**Success criteria:**
- All containers running
- Page load <0.5s
- No errors in logs
- Database connectivity working
- Memory usage <100MB

### 5. Browser Compatibility (`browser/`)

Tests cross-browser compatibility focusing on Tor Browser support.

**File:** `browser/compatibility-test.html`

**What it tests:**
- Browser detection
- JavaScript feature support (Fetch, Promise, async/await)
- Event handling APIs
- CSS features (Flexbox, Grid, Variables)
- Performance APIs
- Privacy-focused browser compatibility

**How to run:**
```bash
# Open in different browsers
firefox tests/browser/compatibility-test.html
chromium tests/browser/compatibility-test.html
tor-browser tests/browser/compatibility-test.html
```

**Expected results:**
- All core features supported (Fetch, Promise, DOM APIs)
- Graceful degradation for optional features
- No external dependencies (Tor Browser compatible)
- All tests pass in Firefox, Chrome, Edge, Safari
- Special compatibility notice for Tor Browser

**Browser support:**
- ✅ Firefox 78+
- ✅ Chrome 80+
- ✅ Edge 80+
- ✅ Safari 13+
- ✅ Tor Browser (all versions)

## Quick Start

### Run All Tests

```bash
# 1. Start Docker container
cd /home/admin/eiou/ai-dev/github/eiou-docker
docker compose -f docker-compose-single.yml up -d

# 2. Run API caching tests
php tests/api/test-caching.php

# 3. Run performance benchmarks
php tests/performance/benchmark.php --docker

# 4. Run integration tests
./tests/integration/test-gui-flow.sh

# 5. Open browser tests
firefox tests/gui/test-toast.html
firefox tests/browser/compatibility-test.html
```

### Test Configuration

Main configuration file: `tests/test-config.php`

**Key settings:**
```php
define('DOCKER_COMPOSE_FILE', 'docker-compose-single.yml');
define('DOCKER_SERVICE_NAME', 'alice');
define('DOCKER_BASE_URL', 'http://localhost:8080');

// Performance thresholds (from Issue #137)
define('PERF_PAGE_LOAD_MAX', 0.5);        // Max 0.5s page load
define('PERF_API_CALLS_MAX', 3);          // Max 3 API calls per page
define('PERF_MEMORY_MAX', 80 * 1024 * 1024); // Max 80MB memory
define('PERF_SSE_LATENCY_MAX', 2.0);      // Max 2s SSE latency
```

## Test Results

Test results are automatically saved to JSON files:

- `tests/test-results.json` - Overall test results
- `tests/performance/performance-metrics.json` - Detailed performance data
- `tests/integration/integration-test-results.json` - Integration test results

**Example result:**
```json
{
  "summary": {
    "total": 15,
    "passed": 15,
    "failed": 0,
    "skipped": 0,
    "duration": 0.245
  },
  "tests": [
    {
      "status": "PASS",
      "name": "Cache initialization",
      "message": "Cache object created",
      "time": 0.001
    }
  ]
}
```

## CI/CD Integration

### GitHub Actions

Add to `.github/workflows/test-gui.yml`:

```yaml
name: GUI Modernization Tests

on:
  pull_request:
    paths:
      - 'src/gui/**'
      - 'tests/**'
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Start Docker Container
        run: docker compose -f docker-compose-single.yml up -d

      - name: Wait for Container
        run: sleep 10

      - name: Run API Caching Tests
        run: php tests/api/test-caching.php

      - name: Run Performance Benchmarks
        run: php tests/performance/benchmark.php --docker

      - name: Run Integration Tests
        run: ./tests/integration/test-gui-flow.sh

      - name: Upload Test Results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: test-results
          path: |
            tests/test-results.json
            tests/performance/performance-metrics.json
            tests/integration/integration-test-results.json
```

### Pre-commit Hook

Add to `.git/hooks/pre-commit`:

```bash
#!/bin/bash
# Run quick tests before commit

echo "Running pre-commit tests..."

# Check if GUI files changed
if git diff --cached --name-only | grep -q "^src/gui/"; then
    echo "GUI files changed, running tests..."

    # Run PHP tests
    php tests/api/test-caching.php || exit 1

    echo "Tests passed!"
fi
```

## Success Metrics

Based on Issue #137 targets:

| Metric | Before | Target | Test Validation |
|--------|--------|--------|-----------------|
| Page load time | 2-3s | <0.5s | ✅ Performance benchmarks |
| API calls/page | 20+ | 2-3 | ✅ Integration tests |
| Memory usage | 150MB | <80MB | ✅ Performance tracking |
| UI blocking | Yes | No | ✅ Manual browser tests |
| Manual refresh | Required | Optional | ✅ SSE latency tests |
| Cache hit rate | 0% | >80% | ✅ API caching tests |

## Troubleshooting

### Docker Container Not Running

```bash
# Check container status
docker ps -a | grep alice

# View logs
docker compose -f docker-compose-single.yml logs

# Restart container
docker compose -f docker-compose-single.yml restart
```

### Tests Failing

```bash
# Check PHP version
php --version  # Should be 7.4+

# Check required extensions
php -m | grep -E "pdo|curl|json"

# Verify file permissions
chmod +x tests/integration/test-gui-flow.sh
chmod +x tests/api/test-caching.php
chmod +x tests/performance/benchmark.php
```

### Browser Tests Not Working

```bash
# Start local HTTP server
cd tests/gui
python3 -m http.server 8000

# Or use PHP
php -S localhost:8000
```

### Performance Tests Too Slow

- Increase timeout values in `test-config.php`
- Check Docker resource allocation
- Verify no other containers consuming resources

## Contributing

When adding new tests:

1. Use the test framework in `test-config.php`
2. Follow existing test naming conventions
3. Add test documentation to this README
4. Update success metrics if targets change
5. Ensure tests work in CI/CD environment

## License

Copyright 2025 EIOU

## Related Documentation

- [Issue #137](https://github.com/eiou-org/eiou/issues/137) - GUI Modernization
- [CLAUDE.md](/home/admin/eiou/ai-dev/CLAUDE.md) - Development workflow
- [Pull Request Guidelines](/home/admin/eiou/ai-dev/CLAUDE.md#pull-request-quality-standards)

## Questions?

For questions or issues with the test suite:
1. Check this README first
2. Review test output and logs
3. Open an issue referencing Issue #137
4. Tag with `testing` label
