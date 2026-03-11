<?php
/**
 * Unit Tests for Application
 *
 * Tests the Application singleton class that manages global state.
 * Due to the singleton nature and heavy file system dependencies,
 * these tests focus on public API behavior where possible.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Eiou\Core\Application;
use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Services\ServiceContainer;
use ReflectionClass;
use PDO;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    /**
     * Reset the singleton instance after each test
     */
    protected function tearDown(): void
    {
        // Reset the singleton via reflection
        $reflection = new ReflectionClass(Application::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    /**
     * Test getInstance returns Application instance
     * Note: This test may fail if config files don't exist outside Docker
     */
    public function testGetInstanceReturnsSameInstance(): void
    {
        // Skip if not in Docker environment where config files exist
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $instance1 = Application::getInstance();
        $instance2 = Application::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test Application is a singleton
     */
    public function testApplicationIsSingleton(): void
    {
        $reflection = new ReflectionClass(Application::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * Test __clone is private
     */
    public function testCloneIsPrivate(): void
    {
        $reflection = new ReflectionClass(Application::class);
        $cloneMethod = $reflection->getMethod('__clone');

        $this->assertTrue($cloneMethod->isPrivate());
    }

    /**
     * Test __wakeup throws exception
     */
    public function testWakeupThrowsException(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $app = Application::getInstance();
        // Simulate wakeup call
        $reflection = new ReflectionClass($app);
        $wakeupMethod = $reflection->getMethod('__wakeup');
        $wakeupMethod->invoke($app);
    }

    /**
     * Test currentPdoLoaded returns bool
     */
    public function testCurrentPdoLoadedReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->currentPdoLoaded();

        $this->assertIsBool($result);
    }

    /**
     * Test currentDatabaseLoaded returns bool
     */
    public function testCurrentDatabaseLoadedReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->currentDatabaseLoaded();

        $this->assertIsBool($result);
    }

    /**
     * Test currentUserLoaded returns bool
     */
    public function testCurrentUserLoadedReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->currentUserLoaded();

        $this->assertIsBool($result);
    }

    /**
     * Test isCli returns correct value
     */
    public function testIsCliReturnsCorrectValue(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->isCli();

        // In PHPUnit we're always in CLI mode
        $this->assertTrue($result);
    }

    /**
     * Test isDevelopment returns bool
     */
    public function testIsDevelopmentReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->isDevelopment();

        $this->assertIsBool($result);
    }

    /**
     * Test isDebug returns bool
     */
    public function testIsDebugReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->isDebug();

        $this->assertIsBool($result);
    }

    /**
     * Test getRootPath returns string
     */
    public function testGetRootPathReturnsString(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->getRootPath();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test getConfigPath returns string
     */
    public function testGetConfigPathReturnsString(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->getConfigPath();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test getLogger returns Logger instance
     */
    public function testGetLoggerReturnsLogger(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->getLogger();

        $this->assertInstanceOf(Logger::class, $result);
    }

    /**
     * Test getLogger returns same instance on multiple calls
     */
    public function testGetLoggerReturnsSameInstance(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $logger1 = $app->getLogger();
        $logger2 = $app->getLogger();

        $this->assertSame($logger1, $logger2);
    }

    /**
     * Test getInputValidator returns InputValidator instance
     */
    public function testGetInputValidatorReturnsInputValidator(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->getInputValidator();

        $this->assertInstanceOf(InputValidator::class, $result);
    }

    /**
     * Test getInputValidator returns same instance on multiple calls
     */
    public function testGetInputValidatorReturnsSameInstance(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $validator1 = $app->getInputValidator();
        $validator2 = $app->getInputValidator();

        $this->assertSame($validator1, $validator2);
    }

    /**
     * Test getSecurity returns Security instance
     */
    public function testGetSecurityReturnsSecurity(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->getSecurity();

        $this->assertInstanceOf(Security::class, $result);
    }

    /**
     * Test getSecurity returns same instance on multiple calls
     */
    public function testGetSecurityReturnsSameInstance(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $security1 = $app->getSecurity();
        $security2 = $app->getSecurity();

        $this->assertSame($security1, $security2);
    }

    /**
     * Test loggerLoaded returns bool
     */
    public function testLoggerLoadedReturnsBool(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $result = $app->loggerLoaded();

        $this->assertIsBool($result);
    }

    /**
     * Test loggerLoaded returns true after getLogger
     */
    public function testLoggerLoadedReturnsTrueAfterGetLogger(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $app->getLogger();
        $result = $app->loggerLoaded();

        $this->assertTrue($result);
    }

    /**
     * Test setDatabase sets PDO connection
     */
    public function testSetDatabaseSetsPdo(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();
        $mockPdo = $this->createMock(PDO::class);

        $app->setDatabase($mockPdo);

        $this->assertTrue($app->currentPdoLoaded());
    }

    /**
     * Test getPublicKey returns null when user not loaded
     */
    public function testGetPublicKeyReturnsNullWhenUserNotLoaded(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();

        // If user is not loaded, should return null
        if (!$app->currentUserLoaded()) {
            $this->assertNull($app->getPublicKey());
        } else {
            // If user is loaded, should return string or null
            $publicKey = $app->getPublicKey();
            $this->assertTrue(is_string($publicKey) || is_null($publicKey));
        }
    }

    /**
     * Test getPublicKeyHash returns null when user not loaded
     */
    public function testGetPublicKeyHashReturnsNullWhenUserNotLoaded(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();

        // If user is not loaded, should return null
        if (!$app->currentUserLoaded()) {
            $this->assertNull($app->getPublicKeyHash());
        } else {
            // If user is loaded, should return string or null
            $hash = $app->getPublicKeyHash();
            $this->assertTrue(is_string($hash) || is_null($hash));
        }
    }

    /**
     * Test processors array exists
     */
    public function testProcessorsArrayExists(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();

        $this->assertIsArray($app->processors);
    }

    /**
     * Test utils array exists
     */
    public function testUtilsArrayExists(): void
    {
        if (!file_exists('/app/eiou/')) {
            $this->markTestSkipped('Test requires EIOU Docker environment');
        }

        $app = Application::getInstance();

        $this->assertIsArray($app->utils);
    }

    /**
     * Test registerService delegates to ServiceContainer
     */
    public function testRegisterServiceDelegatesToServiceContainer(): void
    {
        if (!file_exists('/etc/eiou/config/userconfig.json')) {
            $this->markTestSkipped('Test requires EIOU Docker environment with user config');
        }

        $app = Application::getInstance();

        // This should not throw an exception
        $app->registerService('test_service', new \stdClass());

        $this->assertTrue(true); // If we got here, the call worked
    }

    /**
     * Test getService delegates to ServiceContainer
     */
    public function testGetServiceDelegatesToServiceContainer(): void
    {
        if (!file_exists('/etc/eiou/config/userconfig.json')) {
            $this->markTestSkipped('Test requires EIOU Docker environment with user config');
        }

        $app = Application::getInstance();

        $result = $app->getService('nonexistent_service');

        // Should return null for non-existent service
        $this->assertNull($result);
    }

    /**
     * Test services property exists when user is loaded
     */
    public function testServicesPropertyExists(): void
    {
        if (!file_exists('/etc/eiou/config/userconfig.json')) {
            $this->markTestSkipped('Test requires EIOU Docker environment with user config');
        }

        $app = Application::getInstance();

        if ($app->currentUserLoaded()) {
            $this->assertNotNull($app->services);
        }
    }

    /**
     * Test utilityServices property exists when user is loaded
     */
    public function testUtilityServicesPropertyExists(): void
    {
        if (!file_exists('/etc/eiou/config/userconfig.json')) {
            $this->markTestSkipped('Test requires EIOU Docker environment with user config');
        }

        $app = Application::getInstance();

        if ($app->currentUserLoaded()) {
            $this->assertNotNull($app->utilityServices);
        }
    }
}
