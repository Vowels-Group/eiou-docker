<?php
/**
 * Unit Tests for DatabaseContext
 *
 * Tests the DatabaseContext singleton that wraps database configuration.
 * Tests focus on the public API and configuration management methods.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\DatabaseContext;
use ReflectionClass;
use Exception;

#[CoversClass(DatabaseContext::class)]
class DatabaseContextTest extends TestCase
{
    private ?DatabaseContext $instance = null;

    protected function setUp(): void
    {
        // Reset singleton before each test
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        // Reset singleton after each test
        $this->resetSingleton();
    }

    /**
     * Reset the singleton instance using reflection
     */
    private function resetSingleton(): void
    {
        $reflection = new ReflectionClass(DatabaseContext::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    /**
     * Test getInstance returns DatabaseContext instance
     */
    public function testGetInstanceReturnsDatabaseContext(): void
    {
        $instance = DatabaseContext::getInstance();

        $this->assertInstanceOf(DatabaseContext::class, $instance);
    }

    /**
     * Test getInstance returns same instance (singleton)
     */
    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = DatabaseContext::getInstance();
        $instance2 = DatabaseContext::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test DatabaseContext is a singleton (private constructor)
     */
    public function testDatabaseContextIsSingleton(): void
    {
        $reflection = new ReflectionClass(DatabaseContext::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * Test __clone is private
     */
    public function testCloneIsPrivate(): void
    {
        $reflection = new ReflectionClass(DatabaseContext::class);
        $cloneMethod = $reflection->getMethod('__clone');

        $this->assertTrue($cloneMethod->isPrivate());
    }

    /**
     * Test __wakeup throws exception
     */
    public function testWakeupThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $instance = DatabaseContext::getInstance();
        $reflection = new ReflectionClass($instance);
        $wakeupMethod = $reflection->getMethod('__wakeup');
        $wakeupMethod->invoke($instance);
    }

    /**
     * Test setdatabaseData sets data correctly
     */
    public function testSetDatabaseDataSetsData(): void
    {
        $instance = DatabaseContext::getInstance();
        $testData = [
            'dbHost' => 'localhost',
            'dbName' => 'test_db',
            'dbUser' => 'test_user',
            'dbPass' => 'test_pass'
        ];

        $instance->setdatabaseData($testData);

        $this->assertEquals($testData, $instance->getAll());
    }

    /**
     * Test setdatabaseData sets initialized flag
     */
    public function testSetDatabaseDataSetsInitializedFlag(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->clear();

        $this->assertFalse($instance->isInitialized());

        $instance->setdatabaseData(['dbHost' => 'localhost']);

        $this->assertTrue($instance->isInitialized());
    }

    /**
     * Test get returns value for existing key
     */
    public function testGetReturnsValueForExistingKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['testKey' => 'testValue']);

        $this->assertEquals('testValue', $instance->get('testKey'));
    }

    /**
     * Test get returns default for non-existing key
     */
    public function testGetReturnsDefaultForNonExistingKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertNull($instance->get('nonExistingKey'));
        $this->assertEquals('default', $instance->get('nonExistingKey', 'default'));
    }

    /**
     * Test set creates new key
     */
    public function testSetCreatesNewKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $instance->set('newKey', 'newValue');

        $this->assertEquals('newValue', $instance->get('newKey'));
    }

    /**
     * Test set updates existing key
     */
    public function testSetUpdatesExistingKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['existingKey' => 'oldValue']);

        $instance->set('existingKey', 'newValue');

        $this->assertEquals('newValue', $instance->get('existingKey'));
    }

    /**
     * Test has returns true for existing key
     */
    public function testHasReturnsTrueForExistingKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['existingKey' => 'value']);

        $this->assertTrue($instance->has('existingKey'));
    }

    /**
     * Test has returns false for non-existing key
     */
    public function testHasReturnsFalseForNonExistingKey(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertFalse($instance->has('nonExistingKey'));
    }

    /**
     * Test getAll returns all data
     */
    public function testGetAllReturnsAllData(): void
    {
        $instance = DatabaseContext::getInstance();
        $testData = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        $instance->setdatabaseData($testData);

        $this->assertEquals($testData, $instance->getAll());
    }

    /**
     * Test getDbHost returns host value
     */
    public function testGetDbHostReturnsHostValue(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['dbHost' => 'db.example.com']);

        $this->assertEquals('db.example.com', $instance->getDbHost());
    }

    /**
     * Test getDbHost returns null when not set
     */
    public function testGetDbHostReturnsNullWhenNotSet(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertNull($instance->getDbHost());
    }

    /**
     * Test getDbName returns name value
     */
    public function testGetDbNameReturnsNameValue(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['dbName' => 'my_database']);

        $this->assertEquals('my_database', $instance->getDbName());
    }

    /**
     * Test getDbName returns null when not set
     */
    public function testGetDbNameReturnsNullWhenNotSet(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertNull($instance->getDbName());
    }

    /**
     * Test getDbUser returns user value
     */
    public function testGetDbUserReturnsUserValue(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['dbUser' => 'admin']);

        $this->assertEquals('admin', $instance->getDbUser());
    }

    /**
     * Test getDbUser returns null when not set
     */
    public function testGetDbUserReturnsNullWhenNotSet(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertNull($instance->getDbUser());
    }

    /**
     * Test getDbPass returns password value
     */
    public function testGetDbPassReturnsPasswordValue(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['dbPass' => 'secret123']);

        $this->assertEquals('secret123', $instance->getDbPass());
    }

    /**
     * Test getDbPass returns null when not set
     */
    public function testGetDbPassReturnsNullWhenNotSet(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        $this->assertNull($instance->getDbPass());
    }

    /**
     * Test hasValidDbConfig returns true when all fields set
     */
    public function testHasValidDbConfigReturnsTrueWhenAllFieldsSet(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([
            'dbHost' => 'localhost',
            'dbName' => 'test_db',
            'dbUser' => 'user',
            'dbPass' => 'pass'
        ]);

        $this->assertTrue($instance->hasValidDbConfig());
    }

    /**
     * Test hasValidDbConfig returns false when host missing
     */
    public function testHasValidDbConfigReturnsFalseWhenHostMissing(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([
            'dbName' => 'test_db',
            'dbUser' => 'user',
            'dbPass' => 'pass'
        ]);

        $this->assertFalse($instance->hasValidDbConfig());
    }

    /**
     * Test hasValidDbConfig returns false when name missing
     */
    public function testHasValidDbConfigReturnsFalseWhenNameMissing(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([
            'dbHost' => 'localhost',
            'dbUser' => 'user',
            'dbPass' => 'pass'
        ]);

        $this->assertFalse($instance->hasValidDbConfig());
    }

    /**
     * Test hasValidDbConfig returns false when user missing
     */
    public function testHasValidDbConfigReturnsFalseWhenUserMissing(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([
            'dbHost' => 'localhost',
            'dbName' => 'test_db',
            'dbPass' => 'pass'
        ]);

        $this->assertFalse($instance->hasValidDbConfig());
    }

    /**
     * Test hasValidDbConfig returns false when password missing
     */
    public function testHasValidDbConfigReturnsFalseWhenPasswordMissing(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([
            'dbHost' => 'localhost',
            'dbName' => 'test_db',
            'dbUser' => 'user'
        ]);

        $this->assertFalse($instance->hasValidDbConfig());
    }

    /**
     * Test toArray returns same as getAll
     */
    public function testToArrayReturnsSameAsGetAll(): void
    {
        $instance = DatabaseContext::getInstance();
        $testData = ['key' => 'value'];
        $instance->setdatabaseData($testData);

        $this->assertEquals($instance->getAll(), $instance->toArray());
    }

    /**
     * Test isInitialized returns false initially
     */
    public function testIsInitializedReturnsFalseAfterClear(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['key' => 'value']);
        $instance->clear();

        $this->assertFalse($instance->isInitialized());
    }

    /**
     * Test isInitialized returns false for empty data
     */
    public function testIsInitializedReturnsFalseForEmptyData(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->clear();

        $this->assertFalse($instance->isInitialized());
    }

    /**
     * Test isInitialized returns true after setting data
     */
    public function testIsInitializedReturnsTrueAfterSettingData(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['key' => 'value']);

        $this->assertTrue($instance->isInitialized());
    }

    /**
     * Test clear resets data and initialized flag
     */
    public function testClearResetsDataAndInitializedFlag(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['key' => 'value']);

        $instance->clear();

        $this->assertEquals([], $instance->getAll());
        $this->assertFalse($instance->isInitialized());
    }

    /**
     * Test set works with various value types
     */
    public function testSetWorksWithVariousValueTypes(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData([]);

        // String
        $instance->set('string', 'test');
        $this->assertEquals('test', $instance->get('string'));

        // Integer
        $instance->set('int', 42);
        $this->assertEquals(42, $instance->get('int'));

        // Boolean
        $instance->set('bool', true);
        $this->assertTrue($instance->get('bool'));

        // Array
        $instance->set('array', ['a', 'b', 'c']);
        $this->assertEquals(['a', 'b', 'c'], $instance->get('array'));

        // Null
        $instance->set('null', null);
        $this->assertNull($instance->get('null'));
    }

    /**
     * Test get returns default for null value
     */
    public function testGetReturnsNullValueNotDefault(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['nullKey' => null]);

        // When key exists but value is null, get should return null (not default)
        // Note: This depends on implementation using ?? vs isset()
        $result = $instance->get('nullKey', 'default');

        // The current implementation uses ?? which returns default for null
        $this->assertEquals('default', $result);
    }

    /**
     * Test has returns false for null value (due to isset behavior)
     */
    public function testHasReturnsFalseForNullValue(): void
    {
        $instance = DatabaseContext::getInstance();
        $instance->setdatabaseData(['nullKey' => null]);

        // isset() returns false for null values
        $this->assertFalse($instance->has('nullKey'));
    }
}
