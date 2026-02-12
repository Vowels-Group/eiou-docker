<?php
/**
 * Unit Tests for ContactManagementService
 *
 * Tests contact disambiguation and update validation.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ContactManagementService;
use Eiou\Cli\CliOutputManager;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Utils\InputValidator;
use Eiou\Core\UserContext;
use Eiou\Core\ErrorCodes;

#[CoversClass(ContactManagementService::class)]
class ContactManagementServiceTest extends TestCase
{
    private ContactRepository $contactRepo;
    private AddressRepository $addressRepo;
    private BalanceRepository $balanceRepo;
    private UtilityServiceContainer $utilityContainer;
    private TransportUtilityService $transportUtility;
    private InputValidator $inputValidator;
    private UserContext $currentUser;
    private ContactManagementService $service;

    protected function setUp(): void
    {
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->addressRepo = $this->createMock(AddressRepository::class);
        $this->balanceRepo = $this->createMock(BalanceRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->inputValidator = new InputValidator();
        $this->currentUser = $this->createMock(UserContext::class);

        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        $this->service = new ContactManagementService(
            $this->contactRepo,
            $this->addressRepo,
            $this->balanceRepo,
            $this->utilityContainer,
            $this->inputValidator,
            $this->currentUser
        );
    }

    // =========================================================================
    // lookupContactInfoWithDisambiguation() Tests
    // =========================================================================

    /**
     * Test disambiguation returns null and outputs error in JSON mode when multiple matches
     */
    public function testDisambiguationReturnsErrorInJsonModeWithMultipleMatches(): void
    {
        $matches = [
            ['pubkey' => 'key1', 'name' => 'John', 'status' => 'accepted', 'http' => 'http://node1'],
            ['pubkey' => 'key2', 'name' => 'John', 'status' => 'accepted', 'http' => 'http://node2'],
        ];

        $this->contactRepo->method('lookupAllByName')
            ->with('John')
            ->willReturn($matches);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);
        $output->method('isJsonMode')->willReturn(true);

        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Multiple contacts found'),
                ErrorCodes::MULTIPLE_MATCHES,
                409,
                $this->callback(function ($data) {
                    return isset($data['multiple_matches'])
                        && isset($data['count'])
                        && $data['count'] === 2;
                })
            );

        $result = $this->service->lookupContactInfoWithDisambiguation('John', $output);

        $this->assertNull($result);
    }

    /**
     * Test disambiguation with single match returns contact info normally
     */
    public function testDisambiguationWithSingleMatchReturnsContactInfo(): void
    {
        $match = [
            ['pubkey' => 'key1', 'name' => 'Alice', 'pubkey_hash' => 'hash1',
             'status' => 'accepted', 'http' => 'http://alice.example.com'],
        ];

        $this->contactRepo->method('lookupAllByName')
            ->with('Alice')
            ->willReturn($match);

        // lookupContactInfo will be called - mock lookupByName for the internal call
        $this->contactRepo->method('lookupByName')
            ->with('Alice')
            ->willReturn($match[0]);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);

        $result = $this->service->lookupContactInfoWithDisambiguation('Alice', $output);

        $this->assertNotNull($result);
        $this->assertEquals('Alice', $result['receiverName']);
        $this->assertEquals('key1', $result['receiverPublicKey']);
    }

    /**
     * Test disambiguation with no name matches falls through to address lookup
     */
    public function testDisambiguationWithNoNameMatchesFallsThrough(): void
    {
        $this->contactRepo->method('lookupAllByName')
            ->willReturn([]);

        $this->contactRepo->method('lookupByName')
            ->willReturn(null);

        $this->transportUtility->method('determineTransportType')
            ->with('http://unknown.example.com')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn(null);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);

        $result = $this->service->lookupContactInfoWithDisambiguation('http://unknown.example.com', $output);

        $this->assertNull($result);
    }

    // =========================================================================
    // updateContact() Name Validation Tests
    // =========================================================================

    /**
     * Test updateContact rejects invalid name characters
     */
    public function testUpdateContactRejectsInvalidNameCharacters(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid name'),
                ErrorCodes::INVALID_NAME,
                400
            );

        // argv: [0]=eiou, [1]=update, [2]=address, [3]=name, [4]=value
        $argv = ['eiou', 'update', 'http://test.example.com', 'name', '!!invalid!!'];

        $this->service->updateContact($argv, $output);
    }

    /**
     * Test updateContact accepts valid name with spaces
     */
    public function testUpdateContactAcceptsValidNameWithSpaces(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $this->contactRepo->method('updateContactFields')
            ->willReturn(true);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('updated'),
                $this->callback(function ($data) {
                    return $data['name'] === 'John Doe';
                })
            );

        $argv = ['eiou', 'update', 'http://test.example.com', 'name', 'John Doe'];

        $this->service->updateContact($argv, $output);
    }

    /**
     * Test updateContact validates name in 'all' field mode
     */
    public function testUpdateContactValidatesNameInAllFieldMode(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid name'),
                ErrorCodes::INVALID_NAME,
                400
            );

        // argv: [0]=eiou, [1]=update, [2]=address, [3]=all, [4]=name, [5]=fee, [6]=credit
        $argv = ['eiou', 'update', 'http://test.example.com', 'all', '<script>alert(1)</script>', '0.5', '100'];

        $this->service->updateContact($argv, $output);
    }
}
