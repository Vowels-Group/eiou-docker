<?php
/**
 * Unit Tests for ContactService
 *
 * Tests contact service functionality.
 * Note: Full integration tests require database mocking.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ContactService;
use Eiou\Core\Constants;

#[CoversClass(ContactService::class)]
class ContactServiceTest extends TestCase
{
    /**
     * Test contact status constants are defined correctly
     */
    public function testContactStatusConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::CONTACT_STATUS_PENDING);
        $this->assertEquals('accepted', Constants::CONTACT_STATUS_ACCEPTED);
        $this->assertEquals('blocked', Constants::CONTACT_STATUS_BLOCKED);
    }

    /**
     * Test contact name length constants
     */
    public function testContactNameLengthConstants(): void
    {
        $this->assertGreaterThan(0, Constants::CONTACT_MIN_NAME_LENGTH);
        $this->assertGreaterThan(Constants::CONTACT_MIN_NAME_LENGTH, Constants::CONTACT_MAX_NAME_LENGTH);
        $this->assertLessThanOrEqual(255, Constants::CONTACT_MAX_NAME_LENGTH);
    }

    /**
     * Test default contact settings constants
     */
    public function testDefaultContactSettingsConstants(): void
    {
        $this->assertIsFloat(Constants::CONTACT_DEFAULT_FEE_PERCENT);
        $this->assertGreaterThanOrEqual(0, Constants::CONTACT_DEFAULT_FEE_PERCENT);

        // Max fee can be int or float, just needs to be numeric and greater than default
        $this->assertIsNumeric(Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX);
        $this->assertGreaterThan(Constants::CONTACT_DEFAULT_FEE_PERCENT, Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX);

        $this->assertIsInt(Constants::CONTACT_DEFAULT_CREDIT_LIMIT);
        $this->assertGreaterThan(0, Constants::CONTACT_DEFAULT_CREDIT_LIMIT);
    }

    /**
     * Test online status constants
     */
    public function testOnlineStatusConstants(): void
    {
        $this->assertEquals('online', Constants::CONTACT_ONLINE_STATUS_ONLINE);
        $this->assertEquals('offline', Constants::CONTACT_ONLINE_STATUS_OFFLINE);
        $this->assertEquals('unknown', Constants::CONTACT_ONLINE_STATUS_UNKNOWN);
    }
}
