<?php
/**
 * Migration tests for UserContext class
 * Tests backward compatibility with global $user and gradual migration scenarios
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

// Load the UserContext class
require_once dirname(__DIR__, 2) . '/src/context/UserContext.php';

use EIOU\Context\UserContext;

class UserContextMigrationTest extends TestCase {

    private $originalUser;

    public function setUp() {
        parent::setUp();

        // Save original global $user state
        global $user;
        $this->originalUser = $user ?? null;
    }

    public function tearDown() {
        parent::tearDown();

        // Restore original global $user state
        global $user;
        $user = $this->originalUser;
    }

    // ==================== Bridge Method Tests ====================

    public function testFromGlobalWithCompleteUserData() {
        global $user;
        $user = [
            'public' => 'global_public_key',
            'private' => 'global_private_key',
            'hostname' => 'global.host:8080',
            'torAddress' => 'global123.onion',
            'defaultFee' => 2.0,
            'defaultCurrency' => 'EUR'
        ];

        $context = UserContext::fromGlobal();

        $this->assertNotNull($context, "Should create context from global");
        $this->assertEquals('global_public_key', $context->getPublicKey(), "Public key should match global");
        $this->assertEquals('global.host:8080', $context->getHostname(), "Hostname should match global");
        $this->assertEquals(2.0, $context->getDefaultFee(), "Fee should match global");
    }

    public function testFromGlobalWithEmptyUser() {
        global $user;
        $user = [];

        $context = UserContext::fromGlobal();

        $this->assertNotNull($context, "Should create empty context");
        $this->assertEquals([], $context->toArray(), "Context should be empty");
        $this->assertNull($context->getPublicKey(), "Public key should be null");
    }

    public function testFromGlobalWithUndefinedUser() {
        global $user;
        unset($user);

        $context = UserContext::fromGlobal();

        $this->assertNotNull($context, "Should create context even when global undefined");
        $this->assertEquals([], $context->toArray(), "Context should be empty");
    }

    public function testUpdateGlobalFromContext() {
        global $user;
        $user = ['old' => 'data'];

        $contextData = [
            'public' => 'new_public_key',
            'private' => 'new_private_key',
            'hostname' => 'new.host:8080'
        ];

        $context = new UserContext($contextData);
        $context->updateGlobal();

        $this->assertEquals($contextData, $user, "Global should be updated with context data");
        $this->assertEquals('new_public_key', $user['public'], "Global public key should match");
    }

    public function testUpdateGlobalPreservesReference() {
        global $user;
        $user = ['initial' => 'value'];

        $context = new UserContext(['new' => 'value']);
        $context->updateGlobal();

        $this->assertFalse(isset($user['initial']), "Old global data should be replaced");
        $this->assertTrue(isset($user['new']), "New global data should be present");
    }

    // ==================== Gradual Migration Scenarios ====================

    public function testMixedGlobalAndContextUsage() {
        global $user;
        $user = [
            'public' => 'mixed_public',
            'private' => 'mixed_private',
            'hostname' => 'mixed.host:8080'
        ];

        // Legacy code reads from global
        $legacyPublicKey = $user['public'];
        $this->assertEquals('mixed_public', $legacyPublicKey, "Legacy code should read from global");

        // New code uses context
        $context = UserContext::fromGlobal();
        $newPublicKey = $context->getPublicKey();
        $this->assertEquals('mixed_public', $newPublicKey, "Context should read same data");

        // Both should return same values
        $this->assertEquals($legacyPublicKey, $newPublicKey, "Legacy and context should match");
    }

    public function testPartialMigrationWithGlobalFallback() {
        global $user;
        $user = [
            'public' => 'fallback_public',
            'private' => 'fallback_private',
            'customField' => 'custom_value'
        ];

        // Migrate to context
        $context = UserContext::fromGlobal();

        // New code uses context methods
        $publicKey = $context->getPublicKey();
        $this->assertEquals('fallback_public', $publicKey, "Context should have public key");

        // Legacy code still uses global directly
        $legacyCustomField = $user['customField'] ?? null;
        $this->assertEquals('custom_value', $legacyCustomField, "Legacy code still accesses global");

        // Context can also access custom fields
        $contextCustomField = $context->get('customField');
        $this->assertEquals('custom_value', $contextCustomField, "Context should access custom fields");
    }

    public function testContextModificationWithoutGlobalUpdate() {
        global $user;
        $user = ['public' => 'original_public', 'defaultFee' => 1.0];

        // Create context from global
        $context = UserContext::fromGlobal();

        // Modify context without updating global
        $context->set('defaultFee', 5.0);
        $context->set('newField', 'new_value');

        // Global should remain unchanged
        $this->assertEquals(1.0, $user['defaultFee'], "Global fee should be unchanged");
        $this->assertFalse(isset($user['newField']), "Global should not have new field");

        // Context should have changes
        $this->assertEquals(5.0, $context->getDefaultFee(), "Context fee should be updated");
        $this->assertEquals('new_value', $context->get('newField'), "Context should have new field");
    }

    public function testContextModificationWithGlobalSync() {
        global $user;
        $user = ['public' => 'sync_public', 'defaultFee' => 1.0];

        // Create context and modify
        $context = UserContext::fromGlobal();
        $context->set('defaultFee', 3.0);
        $context->set('newField', 'synced_value');

        // Sync back to global
        $context->updateGlobal();

        // Global should be updated
        $this->assertEquals(3.0, $user['defaultFee'], "Global fee should be synced");
        $this->assertEquals('synced_value', $user['newField'], "Global should have new field");
    }

    // ==================== Legacy Code Compatibility ====================

    public function testLegacyArrayAccessPattern() {
        global $user;
        $user = [
            'public' => 'legacy_public',
            'private' => 'legacy_private',
            'hostname' => 'legacy.host:8080'
        ];

        // Legacy pattern: direct array access
        $legacyPublic = $user['public'] ?? null;
        $legacyHostname = $user['hostname'] ?? null;

        // New pattern: context object
        $context = UserContext::fromGlobal();
        $contextPublic = $context->getPublicKey();
        $contextHostname = $context->getHostname();

        // Both patterns should work
        $this->assertEquals($legacyPublic, $contextPublic, "Legacy and context public key should match");
        $this->assertEquals($legacyHostname, $contextHostname, "Legacy and context hostname should match");
    }

    public function testLegacyIssetPattern() {
        global $user;
        $user = ['public' => 'isset_test_public'];

        // Legacy pattern: isset check
        $legacyHasPublic = isset($user['public']);
        $legacyHasPrivate = isset($user['private']);

        // New pattern: has method
        $context = UserContext::fromGlobal();
        $contextHasPublic = $context->has('public');
        $contextHasPrivate = $context->has('private');

        // Both patterns should produce same results
        $this->assertEquals($legacyHasPublic, $contextHasPublic, "isset and has should match for public");
        $this->assertEquals($legacyHasPrivate, $contextHasPrivate, "isset and has should match for private");
    }

    public function testLegacyDefaultValuePattern() {
        global $user;
        $user = ['public' => 'default_test_public'];

        // Legacy pattern: null coalescing with default
        $legacyFee = $user['defaultFee'] ?? 0.1;
        $legacyCurrency = $user['defaultCurrency'] ?? 'USD';

        // New pattern: getter with default
        $context = UserContext::fromGlobal();
        $contextFee = $context->getDefaultFee();
        $contextCurrency = $context->getDefaultCurrency();

        // Both patterns should produce same defaults
        $this->assertEquals($legacyFee, $contextFee, "Default fee should match");
        $this->assertEquals($legacyCurrency, $contextCurrency, "Default currency should match");
    }

    // ==================== Migration Path Tests ====================

    public function testPhase1MigrationReadOnly() {
        // Phase 1: Create context from global, read-only usage
        global $user;
        $user = [
            'public' => 'phase1_public',
            'private' => 'phase1_private',
            'defaultFee' => 1.5
        ];

        // Application creates context at startup
        $context = UserContext::fromGlobal();

        // Application reads from context
        $publicKey = $context->getPublicKey();
        $fee = $context->getDefaultFee();

        $this->assertEquals('phase1_public', $publicKey, "Should read from context");
        $this->assertEquals(1.5, $fee, "Should read config from context");

        // Global remains unchanged
        $this->assertEquals($user['public'], $publicKey, "Global should still be accessible");
    }

    public function testPhase2MigrationWithWrites() {
        // Phase 2: Use context for reads and writes, sync to global
        global $user;
        $user = ['public' => 'phase2_public', 'defaultFee' => 1.0];

        $context = UserContext::fromGlobal();

        // Application modifies context
        $context->set('defaultFee', 2.0);
        $context->set('debug', true);

        // Sync changes back to global for legacy code
        $context->updateGlobal();

        // Both context and global should have updates
        $this->assertEquals(2.0, $context->getDefaultFee(), "Context should be updated");
        $this->assertEquals(2.0, $user['defaultFee'], "Global should be synced");
        $this->assertTrue($user['debug'], "Global should have new field");
    }

    public function testPhase3MigrationContextOnly() {
        // Phase 3: Use context exclusively, global becomes irrelevant
        global $user;
        $user = ['public' => 'phase3_public'];

        // Create context (possibly from config file instead of global)
        $context = UserContext::fromGlobal();

        // Application only uses context
        $context->set('defaultFee', 3.0);
        $context->set('debug', false);

        // No sync to global needed
        // Global may be out of sync, but that's okay
        $this->assertEquals(3.0, $context->getDefaultFee(), "Context should be updated");
        $this->assertFalse(isset($user['defaultFee']) || $user['defaultFee'] === 3.0, "Global may be out of sync");
    }

    // ==================== Edge Cases in Migration ====================

    public function testMigrationWithNullValues() {
        global $user;
        $user = [
            'public' => null,
            'private' => null,
            'hostname' => null
        ];

        $context = UserContext::fromGlobal();

        // Context should preserve null values
        $this->assertNull($context->getPublicKey(), "Null public key should be preserved");
        $this->assertNull($context->getPrivateKey(), "Null private key should be preserved");
        $this->assertNull($context->getHostname(), "Null hostname should be preserved");
    }

    public function testMigrationWithArrayValues() {
        global $user;
        $user = [
            'public' => 'array_test_public',
            'metadata' => ['created' => '2024-01-01', 'version' => 2],
            'tags' => ['production', 'critical']
        ];

        $context = UserContext::fromGlobal();

        // Context should preserve array values
        $metadata = $context->get('metadata');
        $this->assertEquals('2024-01-01', $metadata['created'], "Array values should be preserved");

        $tags = $context->get('tags');
        $this->assertEquals(2, count($tags), "Array lists should be preserved");
    }

    public function testMigrationWithSpecialCharacters() {
        global $user;
        $user = [
            'public' => 'key_with_"quotes"',
            'hostname' => 'host:8080/path?query=value',
            'custom' => "multi\nline\nvalue"
        ];

        $context = UserContext::fromGlobal();

        // Context should handle special characters
        $this->assertEquals('key_with_"quotes"', $context->getPublicKey(), "Quotes should be preserved");
        $this->assertEquals('host:8080/path?query=value', $context->getHostname(), "URLs should be preserved");
        $this->assertTrue(strpos($context->get('custom'), "\n") !== false, "Newlines should be preserved");
    }

    // ==================== Concurrent Access Tests ====================

    public function testConcurrentContextCreation() {
        global $user;
        $user = ['public' => 'concurrent_public', 'defaultFee' => 1.0];

        // Simulate multiple contexts created from same global
        $context1 = UserContext::fromGlobal();
        $context2 = UserContext::fromGlobal();

        // Both should have same initial data
        $this->assertEquals($context1->getPublicKey(), $context2->getPublicKey(), "Contexts should have same data");

        // Modifications should be independent
        $context1->set('defaultFee', 2.0);
        $context2->set('defaultFee', 3.0);

        $this->assertEquals(2.0, $context1->getDefaultFee(), "Context 1 should have its own fee");
        $this->assertEquals(3.0, $context2->getDefaultFee(), "Context 2 should have its own fee");
    }

    public function testGlobalOverwriteAfterContextCreation() {
        global $user;
        $user = ['public' => 'original_public'];

        $context = UserContext::fromGlobal();

        // Global is overwritten by external code
        $user = ['public' => 'new_public', 'private' => 'new_private'];

        // Context should retain original data
        $this->assertEquals('original_public', $context->getPublicKey(), "Context should be independent");

        // Can create new context from updated global
        $newContext = UserContext::fromGlobal();
        $this->assertEquals('new_public', $newContext->getPublicKey(), "New context should use new global");
    }

    // ==================== Data Consistency Tests ====================

    public function testBidirectionalSyncConsistency() {
        global $user;
        $user = ['public' => 'sync_public', 'defaultFee' => 1.0];

        // Create context from global
        $context = UserContext::fromGlobal();

        // Verify initial sync
        $this->assertEquals($user['public'], $context->getPublicKey(), "Initial sync should match");

        // Modify context and sync back
        $context->set('defaultFee', 2.0);
        $context->updateGlobal();

        // Create new context from updated global
        $newContext = UserContext::fromGlobal();

        // New context should have updated data
        $this->assertEquals(2.0, $newContext->getDefaultFee(), "Bidirectional sync should work");
    }

    public function testToArrayPreservesStructure() {
        global $user;
        $user = [
            'public' => 'structure_public',
            'nested' => ['level1' => ['level2' => 'value']],
            'list' => [1, 2, 3]
        ];

        $context = UserContext::fromGlobal();
        $array = $context->toArray();

        // Structure should be preserved
        $this->assertEquals($user, $array, "toArray should preserve exact structure");
        $this->assertEquals('value', $array['nested']['level1']['level2'], "Nested values should be preserved");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new UserContextMigrationTest();

    SimpleTest::test('From global with complete user data', function() use ($test) {
        $test->setUp();
        $test->testFromGlobalWithCompleteUserData();
        $test->tearDown();
    });

    SimpleTest::test('From global with empty user', function() use ($test) {
        $test->setUp();
        $test->testFromGlobalWithEmptyUser();
        $test->tearDown();
    });

    SimpleTest::test('Update global from context', function() use ($test) {
        $test->setUp();
        $test->testUpdateGlobalFromContext();
        $test->tearDown();
    });

    SimpleTest::test('Mixed global and context usage', function() use ($test) {
        $test->setUp();
        $test->testMixedGlobalAndContextUsage();
        $test->tearDown();
    });

    SimpleTest::test('Context modification without global update', function() use ($test) {
        $test->setUp();
        $test->testContextModificationWithoutGlobalUpdate();
        $test->tearDown();
    });

    SimpleTest::test('Context modification with global sync', function() use ($test) {
        $test->setUp();
        $test->testContextModificationWithGlobalSync();
        $test->tearDown();
    });

    SimpleTest::test('Legacy array access pattern', function() use ($test) {
        $test->setUp();
        $test->testLegacyArrayAccessPattern();
        $test->tearDown();
    });

    SimpleTest::test('Phase 1 migration (read-only)', function() use ($test) {
        $test->setUp();
        $test->testPhase1MigrationReadOnly();
        $test->tearDown();
    });

    SimpleTest::test('Phase 2 migration (with writes)', function() use ($test) {
        $test->setUp();
        $test->testPhase2MigrationWithWrites();
        $test->tearDown();
    });

    SimpleTest::test('Phase 3 migration (context only)', function() use ($test) {
        $test->setUp();
        $test->testPhase3MigrationContextOnly();
        $test->tearDown();
    });

    SimpleTest::test('Concurrent context creation', function() use ($test) {
        $test->setUp();
        $test->testConcurrentContextCreation();
        $test->tearDown();
    });

    SimpleTest::test('Bidirectional sync consistency', function() use ($test) {
        $test->setUp();
        $test->testBidirectionalSyncConsistency();
        $test->tearDown();
    });

    SimpleTest::run();
}
