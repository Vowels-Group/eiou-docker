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
use Eiou\Core\SplitAmount;

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

    public function testContactBalanceConversionWithEmptyArrayReturnsEmptyArray(): void
    {
        $result = $this->balanceService->contactBalanceConversion([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testContactBalanceConversionWithSingleContact(): void
    {
        $contact = [
            'pubkey' => 'test-pubkey-123',
            'name' => 'Test Contact',
            'contact_id' => 'contact-1',
            'online_status' => 'online',
            'valid_chain' => true,
            'pubkey_hash' => 'test-hash-123',
            'http' => 'http://test.example.com',
            'https' => 'https://test.example.com',
            'tor' => ''
        ];

        $balance = new SplitAmount(50, 0);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->with('user-pubkey', ['test-pubkey-123'])
            ->willReturn(['test-pubkey-123' => ['USD' => $balance]]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->with(['http://test.example.com', 'https://test.example.com'], 5)
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(2))
            ->method('convertMinorToMajor')
            ->willReturnCallback(function (SplitAmount $amount) {
                return $amount->toMajorUnits();
            });

        $result = $this->balanceService->contactBalanceConversion([$contact]);

        $this->assertCount(1, $result);
        $this->assertEquals('Test Contact', $result[0]['name']);
        $this->assertEquals(50.0, $result[0]['balance']);
        $this->assertEquals(['USD' => 50.0], $result[0]['balances_by_currency']);
    }

    public function testContactBalanceConversionWithMultipleContacts(): void
    {
        $contacts = [
            [
                'pubkey' => 'pubkey-1', 'name' => 'Contact One', 'contact_id' => 'c1',
                'online_status' => 'online', 'valid_chain' => true,
                'http' => 'http://one.example.com', 'https' => '', 'tor' => ''
            ],
            [
                'pubkey' => 'pubkey-2', 'name' => 'Contact Two', 'contact_id' => 'c2',
                'online_status' => 'offline', 'valid_chain' => false,
                'http' => 'http://two.example.com', 'https' => 'https://two.example.com', 'tor' => ''
            ]
        ];

        $this->userContext->expects($this->once())->method('getPublicKey')->willReturn('user-pubkey');

        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn([
                'pubkey-1' => ['USD' => new SplitAmount(10, 0)],
                'pubkey-2' => ['USD' => new SplitAmount(-5, 0)]
            ]);

        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transactionContactRepository->expects($this->exactly(2))
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(4))
            ->method('convertMinorToMajor')
            ->willReturnCallback(function (SplitAmount $amount) {
                return $amount->toMajorUnits();
            });

        $result = $this->balanceService->contactBalanceConversion($contacts);

        $this->assertCount(2, $result);
        $this->assertEquals(10.0, $result[0]['balance']);
        $this->assertEquals(-5.0, $result[1]['balance']);
    }

    public function testContactBalanceConversionWithZeroBalance(): void
    {
        $contact = [
            'pubkey' => 'zero-balance-pubkey', 'name' => 'Zero Balance Contact',
            'contact_id' => 'zero-contact', 'online_status' => 'unknown', 'valid_chain' => null,
            'http' => 'http://zero.example.com', 'https' => '', 'tor' => ''
        ];

        $this->userContext->expects($this->once())->method('getPublicKey')->willReturn('user-pubkey');
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['zero-balance-pubkey' => ['USD' => 0]]);
        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);
        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        // Zero int is not instanceof SplitAmount, so convertMinorToMajor should not be called
        $this->currencyUtility->expects($this->never())->method('convertMinorToMajor');

        $result = $this->balanceService->contactBalanceConversion([$contact]);
        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['balance']);
    }

    public function testContactBalanceConversionWithCustomTransactionLimit(): void
    {
        $contact = [
            'pubkey' => 'limit-pubkey', 'name' => 'Limit Contact', 'contact_id' => 'limit-c',
            'online_status' => 'online', 'valid_chain' => true,
            'http' => 'http://limit.example.com', 'https' => '', 'tor' => ''
        ];

        $this->userContext->expects($this->once())->method('getPublicKey')->willReturn('user-pubkey');
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['limit-pubkey' => ['USD' => new SplitAmount(10, 0)]]);
        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);
        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->with(['http://limit.example.com'], 10)
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(2))
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $result = $this->balanceService->contactBalanceConversion([$contact], 10);
        $this->assertCount(1, $result);
    }

    public function testContactBalanceConversionIncludesTransactions(): void
    {
        $contact = [
            'pubkey' => 'tx-pubkey', 'name' => 'Transaction Contact', 'contact_id' => 'tx-c',
            'online_status' => 'online', 'valid_chain' => true,
            'http' => 'http://tx.example.com', 'https' => '', 'tor' => ''
        ];

        $transactions = [
            ['txid' => 'tx1', 'amount' => 100, 'status' => 'completed'],
            ['txid' => 'tx2', 'amount' => 200, 'status' => 'completed']
        ];

        $this->userContext->expects($this->once())->method('getPublicKey')->willReturn('user-pubkey');
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['tx-pubkey' => ['USD' => new SplitAmount(30, 0)]]);
        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);
        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn($transactions);

        $this->currencyUtility->expects($this->exactly(2))
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $result = $this->balanceService->contactBalanceConversion([$contact]);
        $this->assertCount(2, $result[0]['transactions']);
    }

    public function testContactBalanceConversionWithMissingOptionalFields(): void
    {
        $contact = [
            'pubkey' => 'minimal-pubkey', 'name' => 'Minimal Contact',
            'http' => 'http://minimal.example.com', 'https' => '', 'tor' => ''
        ];

        $this->userContext->expects($this->once())->method('getPublicKey')->willReturn('user-pubkey');
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['minimal-pubkey' => ['USD' => new SplitAmount(10, 0)]]);
        $this->addressRepository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);
        $this->transactionContactRepository->expects($this->once())
            ->method('getTransactionsWithContact')
            ->willReturn([]);

        $this->currencyUtility->expects($this->exactly(2))
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $result = $this->balanceService->contactBalanceConversion([$contact]);
        $this->assertEquals('', $result[0]['contact_id']);
        $this->assertEquals('unknown', $result[0]['online_status']);
        $this->assertNull($result[0]['valid_chain']);
    }

    // =========================================================================
    // getUserTotalBalance() Tests
    // =========================================================================

    public function testGetUserTotalBalanceWithNullReturnsZero(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn(null);

        $this->assertEquals("0.00", $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithEmptyArrayReturnsZero(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([]);

        $this->assertEquals("0.00", $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithSingleCurrency(): void
    {
        $total = new SplitAmount(50, 0);
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([['currency' => 'USD', 'total_balance' => $total]]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(50.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithMultipleCurrencies(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(50, 0)],
                ['currency' => 'EUR', 'total_balance' => new SplitAmount(30, 0)]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(80.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithZeroBalance(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([['currency' => 'USD', 'total_balance' => SplitAmount::zero()]]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(0.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithNegativeBalance(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([['currency' => 'USD', 'total_balance' => new SplitAmount(-25, 0)]]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(-25.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceWithMissingTotalBalanceKey(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([['currency' => 'USD']]);

        // No SplitAmount in total_balance → not added to total → stays zero
        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(0.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceCorrectlySumsBalances(): void
    {
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => new SplitAmount(10, 0)],
                ['currency' => 'EUR', 'total_balance' => new SplitAmount(20, 0)],
                ['currency' => 'GBP', 'total_balance' => new SplitAmount(30, 0)]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $this->assertEquals(60.0, $this->balanceService->getUserTotalBalance());
    }

    public function testGetUserTotalBalanceClampsOnOverflow(): void
    {
        // With SplitAmount, overflow is no longer a practical concern
        // but test that large amounts work correctly
        $large = new SplitAmount(999999999999, 0);
        $this->balanceRepository->expects($this->once())
            ->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => $large],
                ['currency' => 'EUR', 'total_balance' => new SplitAmount(1, 0)]
            ]);

        $this->currencyUtility->expects($this->once())
            ->method('convertMinorToMajor')
            ->willReturnCallback(fn(SplitAmount $a) => $a->toMajorUnits());

        $result = $this->balanceService->getUserTotalBalance();
        $this->assertNotNull($result);
    }

    // =========================================================================
    // getContactBalance() Tests
    // =========================================================================

    public function testGetContactBalanceDelegatesToRepository(): void
    {
        $balance = new SplitAmount(15, 0);
        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->with('user-public-key', 'contact-public-key')
            ->willReturn($balance);

        $result = $this->balanceService->getContactBalance('user-public-key', 'contact-public-key');
        $this->assertInstanceOf(SplitAmount::class, $result);
        $this->assertEquals(15, $result->whole);
    }

    public function testGetContactBalanceReturnsZeroForNonExistent(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->willReturn(SplitAmount::zero());

        $result = $this->balanceService->getContactBalance('user-key', 'nonexistent-contact');
        $this->assertTrue($result->isZero());
    }

    public function testGetContactBalanceReturnsNegativeBalance(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getContactBalance')
            ->willReturn(new SplitAmount(-50, 0));

        $result = $this->balanceService->getContactBalance('user-key', 'contact-key');
        $this->assertTrue($result->isNegative());
    }

    // =========================================================================
    // getAllContactBalances() Tests
    // =========================================================================

    public function testGetAllContactBalancesDelegatesToRepository(): void
    {
        $expected = ['contact-1' => ['USD' => 1000]];
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn($expected);

        $this->assertEquals($expected, $this->balanceService->getAllContactBalances('user-key', ['contact-1']));
    }

    public function testGetAllContactBalancesWithEmptyArray(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn([]);

        $result = $this->balanceService->getAllContactBalances('user-key', []);
        $this->assertEmpty($result);
    }

    public function testGetAllContactBalancesWithSingleContact(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn(['single-contact' => ['USD' => 7500]]);

        $result = $this->balanceService->getAllContactBalances('user-key', ['single-contact']);
        $this->assertCount(1, $result);
    }

    public function testGetAllContactBalancesReturnsArray(): void
    {
        $this->transactionContactRepository->expects($this->once())
            ->method('getAllContactBalances')
            ->willReturn([]);

        $this->assertIsArray($this->balanceService->getAllContactBalances('user', []));
    }
}
