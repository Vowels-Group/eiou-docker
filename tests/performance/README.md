# Docker Cache Performance Testing Suite

## Overview

This comprehensive testing suite validates the Docker cache implementation for issue #130, ensuring that all acceptance criteria are met:

- ✅ Page load time reduced by >70%
- ✅ Docker API calls reduced by >80%
- ✅ Cache hit rate >60% after warmup
- ✅ No stale data after state changes
- ✅ Acceptable memory usage (<50MB growth)
- ✅ Works with all container topologies

## Test Components

### 1. Baseline Performance Measurement (`baseline_performance.php`)

Establishes baseline performance metrics without caching:

```bash
# Test single node
php baseline_performance.php single

# Test 4-node topology
php baseline_performance.php 4line

# Test all topologies
for topo in single 4line 10line cluster; do
    php baseline_performance.php $topo
done
```

**Metrics Collected:**
- Average response time per Docker command
- P95 and P99 latency percentiles
- Total API calls made
- Throughput (calls/second)

### 2. Cache Testing Framework (`cache_test_framework.php`)

Comprehensive test suite for cache implementation:

```bash
# Run all tests
php cache_test_framework.php all single

# Run specific test suite
php cache_test_framework.php functional single
php cache_test_framework.php performance 4line
php cache_test_framework.php invalidation 10line
php cache_test_framework.php concurrent cluster
php cache_test_framework.php memory single
php cache_test_framework.php stress single
```

**Test Suites:**

- **Functional**: Basic cache operations (set, get, clear, expire)
- **Performance**: Response time and API call reduction
- **Invalidation**: TTL expiry, manual invalidation, pattern matching
- **Concurrent**: Multi-threaded access and race conditions
- **Memory**: Memory growth and cleanup
- **Stress**: High load and long-running stability

### 3. Real-time Cache Monitor (`cache_monitor.php`)

Live dashboard showing cache performance:

```bash
# Start monitor with 1-second updates
php cache_monitor.php 1

# JSON output mode for logging
php cache_monitor.php 1 > monitor.json

# CSV output for analysis
php cache_monitor.php 1 | tee monitor.csv
```

**Dashboard Features:**
- Real-time hit/miss rates
- Performance rates (ops/sec)
- Hit rate trend graph
- Active alerts for thresholds
- Memory usage tracking

### 4. Performance Comparison Report (`performance_comparison_report.php`)

Generates comprehensive comparison reports:

```bash
# Generate HTML report (opens in browser)
php performance_comparison_report.php all html

# Generate Markdown report
php performance_comparison_report.php single markdown

# Generate JSON for further processing
php performance_comparison_report.php 4line json
```

**Report Includes:**
- Executive summary
- Acceptance criteria validation
- Performance improvement metrics
- Topology-specific results
- Recommendations

### 5. All-in-One Test Runner (`run_all_tests.sh`)

Orchestrates complete testing workflow:

```bash
# Run all tests for all topologies
./run_all_tests.sh

# Test specific topology
./run_all_tests.sh 4line

# Skip baseline if already run
./run_all_tests.sh single skip-baseline
```

## Quick Start

### 1. Prerequisites

```bash
# Ensure Docker is running
docker info

# Start containers for your topology
docker-compose -f docker-compose-4line.yml up -d --build

# Wait for containers to be ready
sleep 10
```

### 2. Run Complete Test Suite

```bash
cd tests/performance
./run_all_tests.sh 4line
```

This will:
1. Check Docker environment
2. Run baseline performance tests
3. Execute all cache test suites
4. Start real-time monitoring
5. Generate comparison reports
6. Open HTML report in browser

### 3. Review Results

Check the generated report at:
```
reports/performance_report_YYYY-MM-DD_HH-MM-SS.html
```

## Acceptance Criteria Validation

The test suite validates all criteria from issue #130:

| Criterion | Target | Test Method |
|-----------|--------|-------------|
| Page Load Time | >70% reduction | Compare baseline vs cached response times |
| API Calls | >80% reduction | Count actual Docker exec calls |
| Cache Hit Rate | >60% after warmup | Monitor cache statistics |
| No Stale Data | Zero tolerance | Test invalidation after state changes |
| Memory Usage | <50MB growth | Track memory during stress tests |
| Topology Support | All topologies | Test each configuration |

## Performance Metrics

### Expected Results (with proper cache implementation)

**Single Node:**
- Baseline: ~50ms average response
- Cached: ~5ms average response
- Improvement: ~90%

**4-Node Topology:**
- Baseline: ~200ms for all containers
- Cached: ~20ms for all containers
- Improvement: ~90%

**10-Node Topology:**
- Baseline: ~500ms for all containers
- Cached: ~50ms for all containers
- Improvement: ~90%

### Key Performance Indicators

1. **Response Time Reduction**
   - Measure: Average time per request
   - Target: >70% reduction
   - Test: `performance` suite

2. **API Call Reduction**
   - Measure: Number of Docker exec calls
   - Target: >80% reduction
   - Test: `performance` suite

3. **Cache Efficiency**
   - Measure: Hit rate after warmup
   - Target: >60%
   - Test: `cache_monitor.php`

4. **Memory Efficiency**
   - Measure: Memory growth under load
   - Target: <50MB
   - Test: `memory` suite

5. **Concurrent Safety**
   - Measure: Error rate under concurrent access
   - Target: 0 errors
   - Test: `concurrent` suite

## Troubleshooting

### Common Issues

1. **Docker not running**
   ```bash
   sudo systemctl start docker
   ```

2. **Containers not started**
   ```bash
   docker-compose -f docker-compose-4line.yml up -d
   ```

3. **Permission denied**
   ```bash
   chmod +x run_all_tests.sh
   ```

4. **PHP extensions missing**
   ```bash
   # For concurrent tests
   sudo apt-get install php-pcntl
   ```

5. **Out of memory**
   - Reduce test iterations in config
   - Increase PHP memory limit
   - Stop other containers

## Interpreting Results

### Success Indicators

✅ **All tests passing:**
- 100% functional tests pass
- Performance improvements >70%
- No memory leaks
- No race conditions
- All topologies work

### Failure Indicators

❌ **Investigation needed:**
- Hit rate <30% (cache not working)
- No performance improvement (cache bypass)
- Memory growth >100MB (memory leak)
- Concurrent test failures (race conditions)
- Invalidation failures (stale data risk)

## Integration with CI/CD

Add to GitHub Actions:

```yaml
- name: Run Performance Tests
  run: |
    cd tests/performance
    ./run_all_tests.sh single

- name: Upload Reports
  uses: actions/upload-artifact@v2
  with:
    name: performance-reports
    path: reports/*.html
```

## Contact

For issues or questions about the testing suite:
- Review issue #130 for requirements
- Check test output logs in `logs/`
- Review generated reports in `reports/`

---

**Note:** These tests require the actual DockerCache implementation to be in place at `src/services/DockerCache.php`. The stub implementation is provided for testing the test framework itself.