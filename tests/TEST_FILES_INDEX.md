# Test Files Index

## Complete List of Test Files

### Unit Tests - Repositories (56 tests total)

#### ContactRepositoryTest.php (21 tests)
**Location**: `/home/adrien/Github/eiou/tests/unit/repositories/ContactRepositoryTest.php`

Tests:
1. Insert contact with valid data
2. Contact exists check
3. Accept contact updates status
4. Block contact
5. Unblock contact
6. Delete contact
7. Lookup by name
8. Lookup by address
9. Get all addresses
10. Get all addresses with exclusion
11. Search contacts without term
12. Search contacts with term
13. Is accepted contact
14. Is not blocked
15. Get credit limit
16. Update status
17. Get pending requests
18. Add pending contact
19. Get contact by address
20. Update fields with empty array
21. Update contact fields

#### TransactionRepositoryTest.php (21 tests)
**Location**: `/home/adrien/Github/eiou/tests/unit/repositories/TransactionRepositoryTest.php`

Tests:
1. Calculate total sent
2. Calculate total sent by user
3. Calculate total received
4. Calculate total received by user
5. Is completed by memo
6. Is completed by txid
7. Existing previous txid
8. Existing txid
9. Get previous txid
10. Get by memo
11. Get by txid
12. Insert standard transaction
13. Insert P2P transaction
14. Get pending transactions
15. Update status by memo
16. Update status by txid
17. Get by status
18. Get by status with limit
19. Get transactions between addresses
20. Get transactions between addresses with limit
21. Get statistics

#### P2pRepositoryTest.php (14 tests)
**Location**: `/home/adrien/Github/eiou/tests/unit/repositories/P2pRepositoryTest.php`

Tests:
1. Insert P2P request
2. Get by hash
3. Is completed by hash
4. Update status
5. Update incoming txid
6. Update outgoing txid
7. Get credit in P2P
8. Get queued messages
9. Get expiring messages
10. Get by status
11. Get statistics
12. Update destination address
13. Update fee amount
14. Delete old expired

### Unit Tests - Services (15 tests total)

#### WalletServiceTest.php (15 tests)
**Location**: `/home/adrien/Github/eiou/tests/unit/services/WalletServiceTest.php`

Tests:
1. Get public key
2. Get private key
3. Get auth code
4. Get Tor address
5. Get hostname
6. Has keys returns true
7. Has keys false when public missing
8. Has keys false when private missing
9. Validate wallet returns valid
10. Validate incomplete wallet
11. Get public key null when not set
12. Validate detects missing public key
13. Validate detects missing private key
14. Validate detects missing auth code
15. Validate detects missing network address

### Integration Tests (7 tests - requires SQLite)

#### ServiceIntegrationTest.php
**Location**: `/home/adrien/Github/eiou/tests/integration/ServiceIntegrationTest.php`

Tests:
1. Contact creation and lookup workflow
2. Contact status lifecycle
3. Transaction insertion and retrieval
4. Wallet validation with complete data
5. P2P request lifecycle
6. Multiple contacts and search
7. Transaction statistics calculation

**Note**: Requires SQLite PHP extension to run

## Test Runners & Documentation

### Main Test Runner
**File**: `/home/adrien/Github/eiou/tests/RUN_ALL_TESTS.sh`
**Purpose**: Runs all 4 unit test suites (71 tests)
**Usage**: `bash tests/RUN_ALL_TESTS.sh`

### Documentation Files

1. **TEST_DOCUMENTATION.md**
   - Location: `/home/adrien/Github/eiou/tests/TEST_DOCUMENTATION.md`
   - Complete guide to testing framework
   - How to run tests
   - How to add new tests
   - Assertion methods

2. **TESTING_SUMMARY.md**
   - Location: `/home/adrien/Github/eiou/docs/TESTING_SUMMARY.md`
   - Overview of all tests
   - Statistics and coverage
   - Performance metrics

3. **QUICK_START.md**
   - Location: `/home/adrien/Github/eiou/tests/QUICK_START.md`
   - Quick reference guide
   - How to run tests
   - Troubleshooting

4. **Integration README**
   - Location: `/home/adrien/Github/eiou/tests/integration/README.md`
   - Integration test setup
   - SQLite requirements
   - Alternative testing approaches

## Summary Statistics

| Category | Count | Location |
|----------|-------|----------|
| Repository Unit Tests | 56 | tests/unit/repositories/ |
| Service Unit Tests | 15 | tests/unit/services/ |
| Integration Tests | 7 | tests/integration/ |
| **Total Tests** | **78** | - |
| **Unit Tests (no DB required)** | **71** | - |
| Test Runners | 1 | tests/RUN_ALL_TESTS.sh |
| Documentation Files | 4 | tests/ and docs/ |

## Running Tests

### All Unit Tests (Recommended)
```bash
bash tests/RUN_ALL_TESTS.sh
```

### Individual Test Files
```bash
php tests/unit/repositories/ContactRepositoryTest.php
php tests/unit/repositories/TransactionRepositoryTest.php
php tests/unit/repositories/P2pRepositoryTest.php
php tests/unit/services/WalletServiceTest.php
```

### Integration Tests (requires SQLite)
```bash
php tests/integration/ServiceIntegrationTest.php
```

## Test File Locations - Quick Reference

```
/home/adrien/Github/eiou/
├── tests/
│   ├── unit/
│   │   ├── repositories/
│   │   │   ├── ContactRepositoryTest.php      (21 tests)
│   │   │   ├── TransactionRepositoryTest.php  (21 tests)
│   │   │   └── P2pRepositoryTest.php         (14 tests)
│   │   └── services/
│   │       └── WalletServiceTest.php         (15 tests)
│   ├── integration/
│   │   ├── ServiceIntegrationTest.php        (7 tests)
│   │   └── README.md
│   ├── RUN_ALL_TESTS.sh
│   ├── TEST_DOCUMENTATION.md
│   ├── QUICK_START.md
│   └── TEST_FILES_INDEX.md (this file)
└── docs/
    └── TESTING_SUMMARY.md
```

---

**Total**: 78 tests across 5 test files + comprehensive documentation
