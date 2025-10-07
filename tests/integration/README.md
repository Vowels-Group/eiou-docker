# Integration Tests

## Note on Integration Testing

The integration tests in this directory are designed to test how Repository and Service classes work together. However, they require a real database connection or SQLite to run properly.

## Current Status

**Integration tests require:**
- SQLite PHP extension (for in-memory database)
- OR a configured MySQL/MariaDB test database

If neither is available, integration tests will show database connection errors.

## Running Integration Tests

### Option 1: With SQLite (Recommended)

```bash
# Install SQLite extension
sudo apt-get install php-sqlite3

# Run integration tests
php tests/integration/ServiceIntegrationTest.php
```

### Option 2: With Test Database

Configure a test database and update the DatabaseConnection to use it when in test mode.

## What Integration Tests Cover

1. **Contact Creation and Lookup Workflow**
   - Insert contact → Verify exists → Lookup by name → Lookup by address

2. **Contact Status Lifecycle**
   - Create pending → Accept → Block → Unblock

3. **Transaction Insertion and Retrieval**
   - Insert transaction → Retrieve by txid → Verify data

4. **Wallet Validation**
   - Validate complete wallet → Access all keys

5. **P2P Request Lifecycle**
   - Insert P2P → Queue → Update txids → Complete

6. **Multiple Contacts and Search**
   - Insert multiple → Search partial name → Get all addresses

7. **Transaction Statistics**
   - Insert multiple transactions → Calculate statistics

## Alternative: Unit Tests Cover Most Functionality

The unit tests (`tests/unit/repositories/*` and `tests/unit/services/*`) provide comprehensive coverage of all Repository and Service methods using mocked PDO. These tests:

- ✅ Run without database
- ✅ Test all methods
- ✅ Cover edge cases
- ✅ Are fast and repeatable

**Recommendation**: Focus on unit tests for regular testing. Use integration tests when you have a real database available for end-to-end validation.

## Creating Your Own Integration Tests

When creating integration tests:

1. Use `createTestDatabase()` to get an in-memory SQLite connection
2. Create test schema with proper table structure
3. Pass PDO directly to Repository constructors
4. Test complete workflows, not individual methods
5. Clean up test data after each test

Example:
```php
$pdo = createTestDatabase();
$repo = new ContactRepository($pdo);

// Create schema
$pdo->exec("CREATE TABLE contacts (...)");

// Run workflow test
$repo->insertContact(...);
$result = $repo->lookupByName(...);
assert($result !== null);
```
