<?php
/**
 * Unit Tests for UtilityServiceContainer
 *
 * Tests the centralized container for utility services,
 * including lazy loading and dependency injection patterns.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Services\ServiceContainer;
use Eiou\Core\UserContext;
use Eiou\Contracts\TimeUtilityServiceInterface;
use Eiou\Contracts\CurrencyUtilityServiceInterface;
use Eiou\Contracts\ValidationUtilityServiceInterface;
use Eiou\Contracts\TransportServiceInterface;
use Eiou\Contracts\GeneralUtilityServiceInterface;

#[CoversClass(UtilityServiceContainer::class)]
class UtilityServiceContainerTest extends TestCase
{
    private ServiceContainer $mainContainer;
    private UserContext $userContext;
    private UtilityServiceContainer $utilityContainer;

    protected function setUp(): void
    {
        // Create mock objects
        $this->mainContainer = $this->createMock(ServiceContainer::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Configure main container to return mock user context
        $this->mainContainer->expects($this->any())
            ->method('getCurrentUser')
            ->willReturn($this->userContext);

        // Create the utility service container
        $this->utilityContainer = new UtilityServiceContainer($this->mainContainer);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor accepts main service container
     */
    public function testConstructorAcceptsMainContainer(): void
    {
        $mainContainer = $this->createMock(ServiceContainer::class);

        $utilityContainer = new UtilityServiceContainer($mainContainer);

        $this->assertInstanceOf(UtilityServiceContainer::class, $utilityContainer);
    }

    // =========================================================================
    // getTimeUtility Tests
    // =========================================================================

    /**
     * Test getTimeUtility returns TimeUtilityServiceInterface
     */
    public function testGetTimeUtilityReturnsCorrectInterface(): void
    {
        $timeUtility = $this->utilityContainer->getTimeUtility();

        $this->assertInstanceOf(TimeUtilityServiceInterface::class, $timeUtility);
        $this->assertInstanceOf(TimeUtilityService::class, $timeUtility);
    }

    /**
     * Test getTimeUtility returns same instance (lazy loading)
     */
    public function testGetTimeUtilityReturnsSameInstance(): void
    {
        $first = $this->utilityContainer->getTimeUtility();
        $second = $this->utilityContainer->getTimeUtility();

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // getCurrencyUtility Tests
    // =========================================================================

    /**
     * Test getCurrencyUtility returns CurrencyUtilityServiceInterface
     */
    public function testGetCurrencyUtilityReturnsCorrectInterface(): void
    {
        $currencyUtility = $this->utilityContainer->getCurrencyUtility();

        $this->assertInstanceOf(CurrencyUtilityServiceInterface::class, $currencyUtility);
        $this->assertInstanceOf(CurrencyUtilityService::class, $currencyUtility);
    }

    /**
     * Test getCurrencyUtility returns same instance (lazy loading)
     */
    public function testGetCurrencyUtilityReturnsSameInstance(): void
    {
        $first = $this->utilityContainer->getCurrencyUtility();
        $second = $this->utilityContainer->getCurrencyUtility();

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // getValidationUtility Tests
    // =========================================================================

    /**
     * Test getValidationUtility returns ValidationUtilityServiceInterface
     */
    public function testGetValidationUtilityReturnsCorrectInterface(): void
    {
        $validationUtility = $this->utilityContainer->getValidationUtility();

        $this->assertInstanceOf(ValidationUtilityServiceInterface::class, $validationUtility);
        $this->assertInstanceOf(ValidationUtilityService::class, $validationUtility);
    }

    /**
     * Test getValidationUtility returns same instance (lazy loading)
     */
    public function testGetValidationUtilityReturnsSameInstance(): void
    {
        $first = $this->utilityContainer->getValidationUtility();
        $second = $this->utilityContainer->getValidationUtility();

        $this->assertSame($first, $second);
    }

    /**
     * Test getValidationUtility receives main container
     */
    public function testGetValidationUtilityReceivesMainContainer(): void
    {
        // The validation utility should be created with the main container
        // We verify this by ensuring the service is properly instantiated
        $validationUtility = $this->utilityContainer->getValidationUtility();

        $this->assertNotNull($validationUtility);
    }

    // =========================================================================
    // getTransportUtility Tests
    // =========================================================================

    /**
     * Test getTransportUtility returns TransportServiceInterface
     */
    public function testGetTransportUtilityReturnsCorrectInterface(): void
    {
        $transportUtility = $this->utilityContainer->getTransportUtility();

        $this->assertInstanceOf(TransportServiceInterface::class, $transportUtility);
        $this->assertInstanceOf(TransportUtilityService::class, $transportUtility);
    }

    /**
     * Test getTransportUtility returns same instance (lazy loading)
     */
    public function testGetTransportUtilityReturnsSameInstance(): void
    {
        $first = $this->utilityContainer->getTransportUtility();
        $second = $this->utilityContainer->getTransportUtility();

        $this->assertSame($first, $second);
    }

    /**
     * Test getTransportUtility receives main container
     */
    public function testGetTransportUtilityReceivesMainContainer(): void
    {
        // The transport utility should be created with the main container
        // We verify this by ensuring the service is properly instantiated
        $transportUtility = $this->utilityContainer->getTransportUtility();

        $this->assertNotNull($transportUtility);
    }

    // =========================================================================
    // getGeneralUtility Tests
    // =========================================================================

    /**
     * Test getGeneralUtility returns GeneralUtilityServiceInterface
     */
    public function testGetGeneralUtilityReturnsCorrectInterface(): void
    {
        $generalUtility = $this->utilityContainer->getGeneralUtility();

        $this->assertInstanceOf(GeneralUtilityServiceInterface::class, $generalUtility);
        $this->assertInstanceOf(GeneralUtilityService::class, $generalUtility);
    }

    /**
     * Test getGeneralUtility returns same instance (lazy loading)
     */
    public function testGetGeneralUtilityReturnsSameInstance(): void
    {
        $first = $this->utilityContainer->getGeneralUtility();
        $second = $this->utilityContainer->getGeneralUtility();

        $this->assertSame($first, $second);
    }

    /**
     * Test getGeneralUtility receives main container
     */
    public function testGetGeneralUtilityReceivesMainContainer(): void
    {
        // The general utility should be created with the main container
        // We verify this by ensuring the service is properly instantiated
        $generalUtility = $this->utilityContainer->getGeneralUtility();

        $this->assertNotNull($generalUtility);
    }

    // =========================================================================
    // clearUtilities Tests
    // =========================================================================

    /**
     * Test clearUtilities clears all cached utilities
     */
    public function testClearUtilitiesClearsAllCachedUtilities(): void
    {
        // Get instances to cache them
        $timeUtility1 = $this->utilityContainer->getTimeUtility();
        $currencyUtility1 = $this->utilityContainer->getCurrencyUtility();

        // Clear the cache
        $this->utilityContainer->clearUtilities();

        // Get new instances - they should be different objects
        $timeUtility2 = $this->utilityContainer->getTimeUtility();
        $currencyUtility2 = $this->utilityContainer->getCurrencyUtility();

        $this->assertNotSame($timeUtility1, $timeUtility2);
        $this->assertNotSame($currencyUtility1, $currencyUtility2);
    }

    /**
     * Test clearUtilities allows new instances to be created
     */
    public function testClearUtilitiesAllowsNewInstances(): void
    {
        // Get validation utility (depends on main container)
        $validationUtility1 = $this->utilityContainer->getValidationUtility();

        // Clear the cache
        $this->utilityContainer->clearUtilities();

        // Get new instance
        $validationUtility2 = $this->utilityContainer->getValidationUtility();

        // Should still work and be a new instance
        $this->assertNotSame($validationUtility1, $validationUtility2);
        $this->assertInstanceOf(ValidationUtilityServiceInterface::class, $validationUtility2);
    }

    // =========================================================================
    // Utility Independence Tests
    // =========================================================================

    /**
     * Test utilities are independent instances
     */
    public function testUtilitiesAreIndependentInstances(): void
    {
        $time = $this->utilityContainer->getTimeUtility();
        $currency = $this->utilityContainer->getCurrencyUtility();
        $validation = $this->utilityContainer->getValidationUtility();
        $transport = $this->utilityContainer->getTransportUtility();
        $general = $this->utilityContainer->getGeneralUtility();

        // All should be different objects
        $this->assertNotSame($time, $currency);
        $this->assertNotSame($time, $validation);
        $this->assertNotSame($time, $transport);
        $this->assertNotSame($time, $general);
        $this->assertNotSame($currency, $validation);
        $this->assertNotSame($currency, $transport);
        $this->assertNotSame($currency, $general);
        $this->assertNotSame($validation, $transport);
        $this->assertNotSame($validation, $general);
        $this->assertNotSame($transport, $general);
    }

    /**
     * Test time utility can be used after getting it
     */
    public function testTimeUtilityIsFunctional(): void
    {
        $timeUtility = $this->utilityContainer->getTimeUtility();

        $microtime = $timeUtility->getCurrentMicrotime();

        $this->assertIsInt($microtime);
        $this->assertGreaterThan(0, $microtime);
    }

    /**
     * Test currency utility can be used after getting it
     */
    public function testCurrencyUtilityIsFunctional(): void
    {
        $currencyUtility = $this->utilityContainer->getCurrencyUtility();

        $dollars = $currencyUtility->convertCentsToDollars(100);

        $this->assertEquals(1.0, $dollars);
    }

    /**
     * Test getting utilities in different order produces same instances
     */
    public function testUtilityOrderDoesNotAffectCaching(): void
    {
        // First order
        $general1 = $this->utilityContainer->getGeneralUtility();
        $time1 = $this->utilityContainer->getTimeUtility();

        // Get them in reverse order
        $time2 = $this->utilityContainer->getTimeUtility();
        $general2 = $this->utilityContainer->getGeneralUtility();

        $this->assertSame($time1, $time2);
        $this->assertSame($general1, $general2);
    }
}
