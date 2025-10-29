# PR Validation Checklist for ServiceWrappers Removal

## Pre-Submission Validation Commands

Run these commands in order before creating a Pull Request:

### 1. Check for Wrapper Function Usage
```bash
# This should return no results
grep -r "sendP2pEiou\|sendP2pRequest\|sendP2pRequestFromFailedDirectTransaction\|synchContact" src/ \
  --exclude="ServiceWrappers.php" \
  --exclude-dir="services"
```

### 2. Run Local PHP Syntax Check
```bash
# Check all PHP files for syntax errors
find src/ -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

### 3. Run Integration Test (Outside Docker)
```bash
# Test service container locally
php tests/integration/test-service-container.php
```

### 4. Docker Startup Validation
```bash
# Run complete startup validation suite
./tests/docker/startup-validation.sh
```
Expected output: "ALL VALIDATION TESTS PASSED"

### 5. Docker Runtime Tests
```bash
# Run runtime behavior tests
./tests/docker/runtime-tests.sh
```
Expected output: "ALL RUNTIME TESTS PASSED"

### 6. 4-Node Topology Test
```bash
# Test multi-node communication
docker-compose -f docker-compose-4line.yml up -d --build
sleep 20

# Check all nodes are running
docker ps | grep eiou

# Check for errors
docker-compose -f docker-compose-4line.yml logs | grep -i "fatal\|error\|exception" | grep -v "error_log"

# Cleanup
docker-compose -f docker-compose-4line.yml down -v
```

### 7. Memory Stability Test (5 minutes)
```bash
# Start single node
docker-compose -f docker-compose-single.yml up -d --build

# Record initial memory
docker stats --no-stream alice

# Wait 5 minutes
sleep 300

# Check final memory
docker stats --no-stream alice

# Memory should not increase by more than 100MB
# Cleanup
docker-compose -f docker-compose-single.yml down -v
```

## Manual Verification Checklist

### Code Changes
- [ ] All calls to `sendP2pEiou()` replaced with `ServiceContainer::getInstance()->getTransactionService()->sendP2pEiou()`
- [ ] All calls to `sendP2pRequest()` replaced with `ServiceContainer::getInstance()->getP2pService()->sendP2pRequest()`
- [ ] All calls to `sendP2pRequestFromFailedDirectTransaction()` replaced with service call
- [ ] All calls to `synchContact()` replaced with `ServiceContainer::getInstance()->getSynchService()->synchSingleContact()`
- [ ] ServiceWrappers.php updated to remove these functions
- [ ] No other wrapper functions accidentally removed

### Testing
- [ ] Integration test passes (test-service-container.php)
- [ ] Docker startup validation passes
- [ ] Docker runtime tests pass
- [ ] 4-node topology works
- [ ] 5-minute stability test shows no memory leaks
- [ ] No PHP errors or warnings in logs
- [ ] Container doesn't crash on startup

### Documentation
- [ ] Code comments updated where needed
- [ ] This PR includes test results in description
- [ ] Issue #113 referenced in PR

### Git Hygiene
- [ ] Commits are clean and well-described
- [ ] No unnecessary files included
- [ ] Branch is up to date with main

## Quick Test Script

Save this as `test-all.sh` and run before PR:

```bash
#!/bin/bash
set -e

echo "Running PR validation tests..."

# 1. Check for wrapper usage
echo "Checking for wrapper function usage..."
USAGE=$(grep -r "sendP2pEiou\|sendP2pRequest\|sendP2pRequestFromFailedDirectTransaction\|synchContact" src/ \
  --exclude="ServiceWrappers.php" --exclude-dir="services" | wc -l)

if [ "$USAGE" -gt 0 ]; then
    echo "ERROR: Found $USAGE instances of wrapper functions still in use"
    exit 1
fi

# 2. Run docker tests
echo "Running Docker startup validation..."
./tests/docker/startup-validation.sh

echo "Running Docker runtime tests..."
./tests/docker/runtime-tests.sh

echo ""
echo "✓ All tests passed! Ready for PR submission."
```

## PR Description Template

```markdown
## Summary
Removes ServiceWrappers functions and replaces with direct service calls as per issue #113.

## Changes
- Removed `sendP2pEiou()` wrapper function
- Removed `sendP2pRequest()` wrapper function
- Removed `sendP2pRequestFromFailedDirectTransaction()` wrapper function
- Removed `synchContact()` wrapper function
- Updated all calling code to use direct service calls via ServiceContainer

## Testing Performed

### Automated Tests
- [x] Integration test: `test-service-container.php` - All 15 tests passed
- [x] Docker startup validation: All 10 tests passed
- [x] Docker runtime tests: All 10 tests passed

### Manual Testing
- [x] Single node startup - No errors
- [x] 4-node topology - All nodes communicate
- [x] 5-minute stability test - Memory stable
- [x] No PHP errors or warnings in logs

### Test Output
```
=== ServiceContainer Integration Tests ===
Tests passed: 15
Tests failed: 0

=== Startup Validation ===
Tests Passed: 10
Tests Failed: 0

=== Runtime Tests ===
Tests Passed: 10
Tests Failed: 0
```

## Backward Compatibility
- All functionality remains identical
- No API changes
- No database schema changes
- Services maintain same method signatures

Fixes #113
```

## Emergency Rollback Plan

If issues are discovered after merge:

1. **Revert the PR**
   ```bash
   git revert <merge-commit-hash>
   git push origin main
   ```

2. **Re-add wrapper functions temporarily**
   ```bash
   git checkout <commit-before-removal>
   cp src/services/ServiceWrappers.php /tmp/
   git checkout main
   cp /tmp/ServiceWrappers.php src/services/
   git commit -m "Restore ServiceWrappers temporarily"
   ```

3. **Fix and re-test**
   - Address discovered issues
   - Run full test suite again
   - Create new PR with fixes