# UserContext Migration Test Plan

## Overview

This document outlines the comprehensive testing strategy for the UserContext class migration from global `$user` array to object-oriented UserContext class.

## Test Organization

### Test Suite Structure

```
tests/
├── unit/
│   └── UserContextTest.php                    # Unit tests for all methods
├── Integration/
│   ├── UserContextIntegrationTest.php         # Integration with services/repos
│   └── UserContextMigrationTest.php           # Backward compatibility tests
├── Security/
│   └── UserContextSecurityTest.php            # Security and data encapsulation
├── Performance/
│   └── UserContextPerformanceTest.php         # Performance benchmarks
└── run_all_tests.php                          # Master test runner (updated)
```

## Test Coverage

### 1. Unit Tests (UserContextTest.php)
**File**: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/unit/UserContextTest.php`
**Coverage**: 100% of UserContext class methods

#### Test Categories:

**Constructor Tests** (2 tests)
- Empty data initialization
- Data initialization with values

**Key Getter Tests** (4 tests)
- Public key retrieval (with and without data)
- Private key retrieval (with and without data)

**Address Getter Tests** (6 tests)
- Hostname retrieval
- Tor address retrieval
- User addresses collection (both, hostname only, tor only, empty)
- Address ownership validation

**Configuration Getter Tests** (16 tests)
- Default fee (with value and default)
- Default currency (with value and default)
- Localhost only flag (with value and default)
- Max fee (with value and default)
- Max P2P level (with value and default)
- P2P expiration (with value and default)
- Debug mode (with value and default)
- Max output (with value and default)

**Database Configuration Tests** (6 tests)
- DB host, name, user, password getters
- Valid DB config validation (complete, incomplete, empty)

**Generic Get/Set/Has Tests** (6 tests)
- Generic getter with defaults
- Setter with method chaining
- Property existence checks

**Bridge Method Tests** (4 tests)
- fromGlobal() with complete data
- fromGlobal() with null/empty data
- toArray() data export
- updateGlobal() synchronization

**Advanced Method Tests** (1 test)
- withOverrides() immutable cloning

**Edge Case Tests** (6 tests)
- Type coercion for numeric fields
- Type coercion for boolean fields
- Null handling across all getters
- Empty string preservation
- Large dataset handling (1000 items)
- Array values in user data

**Total**: 51+ unit tests

### 2. Integration Tests (UserContextIntegrationTest.php)
**File**: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextIntegrationTest.php`

#### Test Categories:

**Database Repository Integration** (3 tests)
- Database connection using context config
- Transaction repository interaction
- Contact repository interaction

**Service Integration** (4 tests)
- Authentication service with keys
- Transaction service with fee calculation
- P2P service with expiration/levels
- Currency service validation

**Utility Integration** (3 tests)
- Address validator integration
- Debug logger conditional logging
- Output limiter functionality

**Schema/Config Integration** (2 tests)
- Config schema validation
- Dynamic config updates

**Multi-Context Integration** (2 tests)
- Multiple independent contexts
- Context cloning with overrides

**Error Handling Integration** (2 tests)
- Invalid DB config handling
- Missing address handling

**Cross-Feature Integration** (2 tests)
- Full transaction flow
- Full P2P message flow

**Total**: 18 integration tests

### 3. Migration Tests (UserContextMigrationTest.php)
**File**: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextMigrationTest.php`

#### Test Categories:

**Bridge Method Tests** (5 tests)
- fromGlobal() with various data states
- updateGlobal() synchronization
- Global reference preservation

**Gradual Migration Scenarios** (3 tests)
- Mixed global and context usage
- Partial migration with fallback
- Context modification with/without global sync

**Legacy Code Compatibility** (3 tests)
- Legacy array access patterns
- Legacy isset() patterns
- Legacy default value patterns

**Migration Path Tests** (3 tests)
- Phase 1: Read-only context usage
- Phase 2: Context with writes and sync
- Phase 3: Context-only (global irrelevant)

**Edge Cases in Migration** (3 tests)
- Null value preservation
- Array value preservation
- Special character handling

**Concurrent Access Tests** (2 tests)
- Concurrent context creation
- Global overwrite after context creation

**Data Consistency Tests** (2 tests)
- Bidirectional sync consistency
- toArray() structure preservation

**Total**: 21 migration tests

### 4. Security Tests (UserContextSecurityTest.php)
**File**: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Security/UserContextSecurityTest.php`

#### Test Categories:

**Data Encapsulation Tests** (3 tests)
- Private property encapsulation
- Sensitive data not leaked
- toArray() secure usage warning

**Input Validation Tests** (3 tests)
- Malicious input handling (XSS)
- SQL injection prevention
- XSS prevention in output

**Access Control Tests** (3 tests)
- No direct internal state modification
- Controlled modification via set()
- withOverrides() immutability

**Global Variable Security** (3 tests)
- Global pollution prevention
- Explicit global update requirement
- Isolated context instances

**Sensitive Data Handling** (2 tests)
- Private key not logged in errors
- Database password encapsulation

**Data Validation Tests** (3 tests)
- Invalid fee value handling
- Negative fee value handling
- Excessive P2P level handling

**Memory Security Tests** (2 tests)
- Sensitive data not in memory dumps
- Context clearance and garbage collection

**Race Condition Tests** (1 test)
- Concurrent access safety

**Security Best Practices Tests** (3 tests)
- Minimum data exposure principle
- Secure config loading from environment
- No hardcoded secrets

**Total**: 23 security tests

### 5. Performance Tests (UserContextPerformanceTest.php)
**File**: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Performance/UserContextPerformanceTest.php`

#### Test Categories:

**Memory Usage Tests** (3 tests)
- Global array vs Context memory comparison
- Multiple contexts memory usage (1000 instances)
- Memory leak prevention

**Execution Time Tests** (5 tests)
- Read performance: global vs context (10,000 iterations)
- Write performance: global vs context (10,000 iterations)
- fromGlobal() performance (10,000 iterations)
- toArray() performance (10,000 iterations)
- withOverrides() performance (10,000 iterations)

**Scalability Tests** (2 tests)
- Large dataset performance (1000 fields)
- Concurrent read performance (10 threads × 1000 reads)

**Real-World Scenario Tests** (2 tests)
- Typical application usage (100 iterations)
- Transaction processing scenario (1000 transactions)

**Comparison Summary** (1 test)
- Comprehensive performance comparison with verdict

**Total**: 13 performance tests

## Test Execution

### Running Individual Test Suites

```bash
# Unit tests only
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/unit/UserContextTest.php

# Integration tests
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextIntegrationTest.php

# Migration tests
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextMigrationTest.php

# Security tests
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Security/UserContextSecurityTest.php

# Performance benchmarks
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Performance/UserContextPerformanceTest.php
```

### Running All Tests

```bash
# Run complete test suite including UserContext tests
php /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/run_all_tests.php
```

### Running Specific Test Categories

```bash
# Unit tests only
cd /home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests
for file in unit/*Test.php; do php "$file"; done

# Integration tests only
for file in Integration/*Test.php; do php "$file"; done

# Security tests only
for file in Security/*Test.php; do php "$file"; done

# Performance tests only
for file in Performance/*Test.php; do php "$file"; done
```

## Test Dependencies

### Required Files
- `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/src/context/UserContext.php` (class under test)
- `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/walletTests/bootstrap.php` (test framework)
- `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/walletTests/SimpleTest.php` (test runner)

### Test Database
- All tests use SQLite in-memory database
- Created via `createTestDatabase()` helper
- No external database dependencies

### PHP Requirements
- PHP 7.4 or higher
- PDO extension
- SQLite extension

## Success Criteria

### Coverage Goals
- **Unit Test Coverage**: 100% of UserContext methods
- **Integration Coverage**: All major service interactions
- **Migration Coverage**: All backward compatibility scenarios
- **Security Coverage**: All security concerns addressed
- **Performance**: Context operations < 5x slower than array access

### Test Quality Metrics
- **Test Isolation**: Each test has setUp/tearDown
- **Test Independence**: No dependencies between tests
- **Clear Assertions**: Each test has specific, meaningful assertions
- **Edge Cases**: Comprehensive edge case coverage
- **Real-World Scenarios**: Tests reflect actual usage patterns

## Expected Results

### Unit Tests
- **Status**: All tests should PASS
- **Duration**: < 1 second
- **Coverage**: 100% of UserContext class

### Integration Tests
- **Status**: All tests should PASS
- **Duration**: < 2 seconds
- **Coverage**: All service/repository interactions

### Migration Tests
- **Status**: All tests should PASS
- **Duration**: < 1 second
- **Coverage**: All migration scenarios

### Security Tests
- **Status**: All tests should PASS
- **Duration**: < 1 second
- **Coverage**: All security concerns

### Performance Tests
- **Status**: All tests should PASS with acceptable performance
- **Duration**: 5-10 seconds (includes benchmarks)
- **Benchmarks**:
  - Memory overhead: < 10x array size
  - Read operations: < 5x slower than array access
  - Write operations: < 5x slower than array access
  - 1000 contexts: < 10MB memory

## Migration Strategy Validation

### Phase 1: Read-Only Migration
**Tests**: Migration test suite validates read-only usage
- Create context from global
- Read via context getters
- Global remains unchanged

### Phase 2: Read-Write Migration
**Tests**: Migration test suite validates bidirectional sync
- Create context from global
- Modify via context setters
- Sync back to global
- Legacy code still works

### Phase 3: Context-Only
**Tests**: Migration test suite validates context-only usage
- Context used exclusively
- Global becomes irrelevant
- All application code uses context

## Known Limitations

### Performance Trade-offs
- **Method Calls**: Slightly slower than array access (expected)
- **Memory Overhead**: Small overhead for object structure (acceptable)
- **Object Creation**: fromGlobal() creates new instances (by design)

### Design Decisions
- **No Automatic Sync**: Context doesn't auto-sync to global (explicit control)
- **No Validation**: Context stores data as-is (validation is application's responsibility)
- **No Thread Safety**: PHP is single-threaded per request (not a concern)

## Maintenance

### Adding New Tests
1. Create test method in appropriate test class
2. Follow naming convention: `test[Feature][Scenario]()`
3. Use setUp() and tearDown() for isolation
4. Register test in bottom section for standalone execution

### Updating Tests
- When UserContext adds features, add corresponding tests
- Keep test documentation in sync with implementation
- Update coverage metrics in run_all_tests.php

### Test Review Checklist
- [ ] All tests pass independently
- [ ] All tests pass in full suite
- [ ] setUp() and tearDown() properly isolate tests
- [ ] Assertions have meaningful failure messages
- [ ] Edge cases are covered
- [ ] Performance benchmarks show acceptable results

## Troubleshooting

### Test Failures
1. Check error messages for specific assertion failures
2. Verify UserContext.php is loaded correctly
3. Ensure bootstrap.php is included
4. Check PHP version compatibility

### Performance Issues
1. Reduce iterations for faster testing
2. Run performance tests separately from unit tests
3. Profile with Xdebug if needed

### Integration Issues
1. Verify test database creation
2. Check PDO/SQLite extension availability
3. Ensure file paths are correct

## Summary

**Total Test Count**: 126+ tests across 5 test suites

**Test Distribution**:
- Unit Tests: 51 tests (40%)
- Integration Tests: 18 tests (14%)
- Migration Tests: 21 tests (17%)
- Security Tests: 23 tests (18%)
- Performance Tests: 13 tests (11%)

**Files Created**:
1. `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/unit/UserContextTest.php`
2. `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextIntegrationTest.php`
3. `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Integration/UserContextMigrationTest.php`
4. `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Security/UserContextSecurityTest.php`
5. `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/Performance/UserContextPerformanceTest.php`

**Master Test Runner Updated**:
- `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/run_all_tests.php` (now includes all UserContext tests)

## Next Steps

1. **Run Tests**: Execute full test suite to verify all tests pass
2. **Review Results**: Analyze performance benchmarks and coverage
3. **Begin Migration**: Use Phase 1 approach (read-only) initially
4. **Monitor**: Track any issues during migration
5. **Complete Migration**: Progress through Phase 2 and Phase 3
6. **Cleanup**: Remove global $user usage after full migration

## Contact

For questions or issues with the test suite, refer to:
- UserContext class: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/src/context/UserContext.php`
- Test framework: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/walletTests/SimpleTest.php`
- Master test runner: `/home/adrien/Github/ai-eiou-dev-claude-flow/github/eiou/tests/run_all_tests.php`
