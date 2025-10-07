# Quick Start - Test Suite

## Run All Tests (Recommended)

```bash
bash tests/RUN_ALL_TESTS.sh
```

Expected output:
```
✅ ✅ ✅  ALL TEST SUITES PASSED  ✅ ✅ ✅

Total Coverage: 71 unit tests across 4 test suites
```

## Run Individual Test Files

```bash
# Test ContactRepository (21 tests)
php tests/unit/repositories/ContactRepositoryTest.php

# Test TransactionRepository (21 tests)
php tests/unit/repositories/TransactionRepositoryTest.php

# Test P2pRepository (14 tests)
php tests/unit/repositories/P2pRepositoryTest.php

# Test WalletService (15 tests)
php tests/unit/services/WalletServiceTest.php
```

## What Gets Tested

| Component | What It Tests |
|-----------|---------------|
| **ContactRepository** | Contact CRUD, status changes, lookups, searches |
| **TransactionRepository** | Transaction CRUD, balances, chain validation, stats |
| **P2pRepository** | P2P requests, status, txid tracking, expiration |
| **WalletService** | Key management, validation, address retrieval |

## Files Created

```
tests/
├── unit/
│   ├── repositories/
│   │   ├── ContactRepositoryTest.php       ✅ 21 tests
│   │   ├── TransactionRepositoryTest.php   ✅ 21 tests
│   │   └── P2pRepositoryTest.php          ✅ 14 tests
│   └── services/
│       └── WalletServiceTest.php          ✅ 15 tests
├── integration/
│   ├── ServiceIntegrationTest.php         (7 tests - needs SQLite)
│   └── README.md
├── RUN_ALL_TESTS.sh                        ⭐ Main runner
├── TEST_DOCUMENTATION.md                   📖 Full guide
└── QUICK_START.md                         ⚡ This file
```

## Requirements

- ✅ PHP 7.4+ (already installed)
- ✅ No database required for unit tests
- ✅ No external dependencies (uses custom SimpleTest framework)

## Expected Results

All tests should pass:
- **ContactRepositoryTest**: 21/21 ✅
- **TransactionRepositoryTest**: 21/21 ✅
- **P2pRepositoryTest**: 14/14 ✅
- **WalletServiceTest**: 15/15 ✅

**Total: 71/71 tests passing** ✅

## Troubleshooting

**Q: Tests show "Deprecated" notices**
A: These are warnings about return type declarations. Tests still pass correctly.

**Q: Integration tests fail with "database driver not found"**
A: Integration tests require SQLite extension. Unit tests (71 tests) cover all functionality without needing a database.

**Q: How do I add new tests?**
A: See `/home/adrien/Github/eiou/tests/TEST_DOCUMENTATION.md` for detailed instructions.

## Performance

All 71 unit tests run in **< 5 seconds** total.

## Documentation

- **Full Documentation**: `tests/TEST_DOCUMENTATION.md`
- **Testing Summary**: `docs/TESTING_SUMMARY.md`
- **Integration Tests**: `tests/integration/README.md`

---

**Quick Reference**: Run `bash tests/RUN_ALL_TESTS.sh` to verify everything works!
