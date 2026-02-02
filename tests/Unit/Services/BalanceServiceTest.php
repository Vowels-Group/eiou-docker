<?php
/**
 * Unit Tests for BalanceService
 *
 * Tests balance service functionality including contact balance conversion,
 * user total balance calculation, and balance retrieval operations.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\BalanceService;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;

#[CoversClass(BalanceService::class)]
class BalanceServiceTest extends TestCase
{
    private BalanceRepository $balanceRepository;
    private TransactionContactRepository $transactionContactRepository;
    private AddressRepository $addressRepository;
    private CurrencyUtilityService $currencyUtility;
    private UserContext $userContext;
    private BalanceService $balanceService;

    protected function setUp(): void
    {
        // Create mock objects for all dependencies
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->transactionContactRepository = $this->createMock(TransactionContactRepository::class);
        $this->addressRepository = $this->createMock(AddressRepository::class);
        $this->currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);

        $this->balanceService = new BalanceService(
            $this->balanceRepository,
            $this->transactionContactRepository,
            $this->addressRepository,
            $this->currencyUtility,
            $this->userContext
        );
    }

    // =========================================================================
    // contactBalanceConversion() Tests
    // =========================================================================

    /**
     * Test contactBalanceConversion with empty contacts array
     */
    public function testContactBalanceConversionWithEmptyArrayReturnsEmptyArray(): void
    {
        $result = $this->balanceService->contactBalanceConversion([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test contactBalanceConversion with single contact
     */
    public function testContactBalanceConversionWithSingleContact(): void
    {
        $contact = [
            'pubkey' => 'test-pubkey-123',
            'name' => 'Test Contact',
            'fee_percent' => 100,
            'credit_limit' => 10000,
            'currency' => 'USD',
            'contact_id' => 'contact-1',
            'online_status' => 'online',
            'valid_chain' => true,
            'http' => 'http://test.example.com',
            'https' => 'https://test.example.com',
            'tor' => ''
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with('user-pubkey', ['test-pubkey-123'])
            ->willReturn(['test-pubkey-123' => 5000]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->with(['http://test.example.com', 'https://test.example.com'], 5)
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(3))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertEquals('Test Contact', $result[0]['name']);
        $this->assertEquals(50.0, $result[0]['balance']);
        $this->assertEquals(1.0, $result[0]['fee']);
        $this->assertEquals(100.0, $result[0]['credit_limit']);
        $this->assertEquals('USD', $result[0]['currency']);
        $this->assertEquals('test-pubkey-123', $result[0]['pubkey']);
        $this->assertEquals('contact-1', $result[0]['contact_id']);
        $this->assertEquals('online', $result[0]['online_status']);
        $this->assertTrue($result[0]['valid_chain']);
    }

    /**
     * Test contactBalanceConversion with multiple contacts
     */
    public function testContactBalanceConversionWithMultipleContacts(): void
    {
        $contacts = [
            [
                'pubkey' => 'pubkey-1',
                'name' => 'Contact One',
                'fee_percent' => 100,
                'credit_limit' => 5000,
                'currency' => 'USD',
                'contact_id' => 'c1',
                'online_status' => 'online',
                'valid_chain' => true,
                'http' => 'http://one.example.com',
                'https' => '',
                'tor' => ''
            ],
            [
                'pubkey' => 'pubkey-2',
                'name' => 'Contact Two',
                'fee_percent' => 200,
                'credit_limit' => 10000,
                'currency' => 'USD',
                'contact_id' => 'c2',
                'online_status' => 'offline',
                'valid_chain' => false,
                'http' => 'http://two.example.com',
                'https' => 'https://two.example.com',
                'tor' => ''
            ]
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with('user-pubkey', ['pubkey-1', 'pubkey-2'])
            ->willReturn([
                'pubkey-1' => 1000,
                'pubkey-2' => -500
            ]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->exactly(2))
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(6))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion($contacts);

        $this->assertCount(2, $result);

        // Verify first contact
        $this->assertEquals('Contact One', $result[0]['name']);
        $this->assertEquals(10.0, $result[0]['balance']);
        $this->assertEquals('online', $result[0]['online_status']);

        // Verify second contact
        $this->assertEquals('Contact Two', $result[1]['name']);
        $this->assertEquals(-5.0, $result[1]['balance']);
        $this->assertEquals('offline', $result[1]['online_status']);
    }

    /**
     * Test contactBalanceConversion with zero balance contact
     */
    public function testContactBalanceConversionWithZeroBalance(): void
    {
        $contact = [
            'pubkey' => 'zero-balance-pubkey',
            'name' => 'Zero Balance Contact',
            'fee_percent' => 0,
            'credit_limit' => 0,
            'currency' => 'USD',
            'contact_id' => 'zero-contact',
            'online_status' => 'unknown',
            'valid_chain' => null,
            'http' => 'http://zero.example.com',
            'https' => '',
            'tor' => ''
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['zero-balance-pubkey' => 0]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        // With zero values, convertCentsToDollars should not be called for balance/fee/credit_limit
        $this->currencyUtility->expects($this->never())
            ->method('convertCentsToDollars');

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['balance']);
        $this->assertEquals(0, $result[0]['fee']);
        $this->assertEquals(0, $result[0]['credit_limit']);
    }

    /**
     * Test contactBalanceConversion with missing pubkey in balance results
     */
    public function testContactBalanceConversionWithMissingBalanceDefaultsToZero(): void
    {
        $contact = [
            'pubkey' => 'missing-balance-pubkey',
            'name' => 'Missing Balance Contact',
            'fee_percent' => 50,
            'credit_limit' => 2500,
            'currency' => 'USD',
            'contact_id' => 'missing-c',
            'online_status' => 'online',
            'valid_chain' => true,
            'http' => 'http://missing.example.com',
            'https' => '',
            'tor' => ''
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        // Return empty array - pubkey not found
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn([]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(2))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['balance']);
    }

    /**
     * Test contactBalanceConversion with custom transaction limit
     */
    public function testContactBalanceConversionWithCustomTransactionLimit(): void
    {
        $contact = [
            'pubkey' => 'limit-pubkey',
            'name' => 'Limit Contact',
            'fee_percent' => 100,
            'credit_limit' => 5000,
            'currency' => 'USD',
            'contact_id' => 'limit-c',
            'online_status' => 'online',
            'valid_chain' => true,
            'http' => 'http://limit.example.com',
            'https' => '',
            'tor' => ''
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['limit-pubkey' => 1000]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Verify custom limit of 10 is passed
        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->with(['http://limit.example.com'], 10)
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(3))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion([$contact], 10);

        $this->assertCount(1, $result);
    }

    /**
     * Test contactBalanceConversion includes transactions in result
     */
    public function testContactBalanceConversionIncludesTransactions(): void
    {
        $contact = [
            'pubkey' => 'tx-pubkey',
            'name' => 'Transaction Contact',
            'fee_percent' => 100,
            'credit_limit' => 5000,
            'currency' => 'USD',
            'contact_id' => 'tx-c',
            'online_status' => 'online',
            'valid_chain' => true,
            'http' => 'http://tx.example.com',
            'https' => '',
            'tor' => ''
        ];

        $transactions = [
            ['txid' => 'tx1', 'amount' => 100, 'status' => 'completed'],
            ['txid' => 'tx2', 'amount' => 200, 'status' => 'completed']
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['tx-pubkey' => 3000]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn($transactions);

        $this->currencyUtility->expects($this->exactly(3))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]['transactions']);
        $this->assertEquals('tx1', $result[0]['transactions'][0]['txid']);
        $this->assertEquals('tx2', $result[0]['transactions'][1]['txid']);
    }

    /**
     * Test contactBalanceConversion handles contact with missing optional fields
     */
    public function testContactBalanceConversionWithMissingOptionalFields(): void
    {
        $contact = [
            'pubkey' => 'minimal-pubkey',
            'name' => 'Minimal Contact',
            'fee_percent' => 100,
            'credit_limit' => 5000,
            'currency' => 'USD',
            'http' => 'http://minimal.example.com',
            'https' => '',
            'tor' => ''
            // Missing: contact_id, online_status, valid_chain
        ];

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['minimal-pubkey' => 1000]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(3))
            ->method('convertCentsToDollars')
            ->willReturnCallback(function ($cents) {
                return $cents / 100;
            });

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertEquals('', $result[0]['contact_id']);
        $this->assertEquals('unknown', $result[0]['online_status']);
        $this->assertNull($result[0]['valid_chain']);
    }

    // =========================================================================
    // getUserTotalBalance() Tests
    // =========================================================================

    /**
     * Test getUserTotalBalance with null balances
     */
    public function testGetUserTotalBalanceWithNullReturnsZero(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn(null);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals("0.00", $result);
    }

    /**
     * Test getUserTotalBalance with empty array
     */
    public function testGetUserTotalBalanceWithEmptyArrayReturnsZero(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([]);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals("0.00", $result);
    }

    /**
     * Test getUserTotalBalance with single currency balance
     */
    public function testGetUserTotalBalanceWithSingleCurrency(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 5000]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(5000)
            ->willReturn(50.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(50.0, $result);
    }

    /**
     * Test getUserTotalBalance with multiple currency balances
     */
    public function testGetUserTotalBalanceWithMultipleCurrencies(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 5000],
                ['currency' => 'EUR', 'total_balance' => 3000]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(8000)
            ->willReturn(80.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(80.0, $result);
    }

    /**
     * Test getUserTotalBalance with zero balance
     */
    public function testGetUserTotalBalanceWithZeroBalance(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 0]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(0)
            ->willReturn(0.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(0.0, $result);
    }

    /**
     * Test getUserTotalBalance with negative balance
     */
    public function testGetUserTotalBalanceWithNegativeBalance(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => -2500]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(-2500)
            ->willReturn(-25.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(-25.0, $result);
    }

    /**
     * Test getUserTotalBalance with missing total_balance key defaults to zero
     */
    public function testGetUserTotalBalanceWithMissingTotalBalanceKey(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD']
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(0)
            ->willReturn(0.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(0.0, $result);
    }

    /**
     * Test getUserTotalBalance correctly sums multiple balances
     */
    public function testGetUserTotalBalanceCorrectlySumsBalances(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 1000],
                ['currency' => 'EUR', 'total_balance' => 2000],
                ['currency' => 'GBP', 'total_balance' => 3000]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertCentsToDollars')
            ->with(6000)
            ->willReturn(60.0);

        $result = $this->balanceService->getUserTotalBalance();

        $this->assertEquals(60.0, $result);
    }

    // =========================================================================
    // getContactBalance() Tests
    // =========================================================================

    /**
     * Test getContactBalance delegates to repository
     */
    public function testGetContactBalanceDelegatesToRepository(): void
    {
        $userPubkey = 'user-public-key';
        $contactPubkey = 'contact-public-key';
        $expectedBalance = 1500;

        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->with($userPubkey, $contactPubkey)
            ->willReturn($expectedBalance);

        $result = $this->balanceService->getContactBalance($userPubkey, $contactPubkey);

        $this->assertEquals($expectedBalance, $result);
    }

    /**
     * Test getContactBalance returns zero for non-existent contact
     */
    public function testGetContactBalanceReturnsZeroForNonExistent(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->with('user-key', 'nonexistent-contact')
            ->willReturn(0);

        $result = $this->balanceService->getContactBalance('user-key', 'nonexistent-contact');

        $this->assertEquals(0, $result);
    }

    /**
     * Test getContactBalance returns negative balance
     */
    public function testGetContactBalanceReturnsNegativeBalance(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->with('user-key', 'contact-key')
            ->willReturn(-5000);

        $result = $this->balanceService->getContactBalance('user-key', 'contact-key');

        $this->assertEquals(-5000, $result);
    }

    // =========================================================================
    // getAllContactBalances() Tests
    // =========================================================================

    /**
     * Test getAllContactBalances delegates to repository
     */
    public function testGetAllContactBalancesDelegatesToRepository(): void
    {
        $userPubkey = 'user-public-key';
        $contactPubkeys = ['contact-1', 'contact-2', 'contact-3'];
        $expectedBalances = [
            'contact-1' => 1000,
            'contact-2' => 2000,
            'contact-3' => -500
        ];

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with($userPubkey, $contactPubkeys)
            ->willReturn($expectedBalances);

        $result = $this->balanceService->getAllContactBalances($userPubkey, $contactPubkeys);

        $this->assertEquals($expectedBalances, $result);
    }

    /**
     * Test getAllContactBalances with empty pubkeys array
     */
    public function testGetAllContactBalancesWithEmptyArray(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with('user-key', [])
            ->willReturn([]);

        $result = $this->balanceService->getAllContactBalances('user-key', []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getAllContactBalances with single contact
     */
    public function testGetAllContactBalancesWithSingleContact(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with('user-key', ['single-contact'])
            ->willReturn(['single-contact' => 7500]);

        $result = $this->balanceService->getAllContactBalances('user-key', ['single-contact']);

        $this->assertCount(1, $result);
        $this->assertEquals(7500, $result['single-contact']);
    }

    /**
     * Test getAllContactBalances returns correct type
     */
    public function testGetAllContactBalancesReturnsArray(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn([]);

        $result = $this->balanceService->getAllContactBalances('user', []);

        $this->assertIsArray($result);
    }
}
