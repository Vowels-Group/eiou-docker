<?php
/**
 * Unit Tests for CliService
 *
 * Tests CLI service functionality including:
 * - Settings management (change, display)
 * - Help display
 * - User information display
 * - Pending contacts display
 * - Overview display
 * - Balance operations
 * - Transaction history
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\CliService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Core\UserContext;
use Eiou\Cli\CliOutputManager;

#[CoversClass(CliService::class)]
class CliServiceTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|CurrencyUtilityService $currencyUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|GeneralUtilityService $generalUtility;
    private MockObject|UserContext $userContext;
    private MockObject|CliOutputManager $outputManager;
    private CliService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->generalUtility = $this->createMock(GeneralUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->outputManager = $this->createMock(CliOutputManager::class);

        // Setup utility container to return mocked utilities
        $this->utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getGeneralUtility')
            ->willReturn($this->generalUtility);

        $this->service = new CliService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext
        );
    }

    // =========================================================================
    // displayCurrentSettings() Tests
    // =========================================================================

    /**
     * Test displayCurrentSettings outputs JSON when in JSON mode
     */
    public function testDisplayCurrentSettingsInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->userContext->method('getDefaultCurrency')
            ->willReturn('USD');
        $this->userContext->method('getMinimumFee')
            ->willReturn(10.0);
        $this->userContext->method('getDefaultFee')
            ->willReturn(1.0);
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getDefaultCreditLimit')
            ->willReturn(1000.0);
        $this->userContext->method('getMaxP2pLevel')
            ->willReturn(3);
        $this->userContext->method('getP2pExpirationTime')
            ->willReturn(300);
        $this->userContext->method('getMaxOutput')
            ->willReturn(50);
        $this->userContext->method('getDefaultTransportMode')
            ->willReturn('http');
        $this->userContext->method('getAutoRefreshEnabled')
            ->willReturn(true);
        $this->userContext->method('getAutoBackupEnabled')
            ->willReturn(false);

        $this->outputManager->expects($this->once())
            ->method('settings')
            ->with($this->callback(function ($settings) {
                return $settings['default_currency'] === 'USD'
                    && $settings['minimum_fee_amount'] === 10.0
                    && $settings['default_fee_percent'] === 1.0
                    && $settings['maximum_fee_percent'] === 5.0
                    && $settings['default_credit_limit'] === 1000.0
                    && $settings['max_p2p_level'] === 3
                    && $settings['p2p_expiration_seconds'] === 300
                    && $settings['max_output_lines'] === 50
                    && $settings['default_transport_mode'] === 'http'
                    && $settings['auto_refresh_enabled'] === true
                    && $settings['auto_backup_enabled'] === false;
            }));

        $this->service->displayCurrentSettings($this->outputManager);
    }

    /**
     * Test displayCurrentSettings outputs text when not in JSON mode
     */
    public function testDisplayCurrentSettingsInTextMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->userContext->method('getDefaultCurrency')
            ->willReturn('USD');
        $this->userContext->method('getMinimumFee')
            ->willReturn(10.0);
        $this->userContext->method('getDefaultFee')
            ->willReturn(1.0);
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getDefaultCreditLimit')
            ->willReturn(1000.0);
        $this->userContext->method('getMaxP2pLevel')
            ->willReturn(3);
        $this->userContext->method('getP2pExpirationTime')
            ->willReturn(300);
        $this->userContext->method('getMaxOutput')
            ->willReturn(50);
        $this->userContext->method('getDefaultTransportMode')
            ->willReturn('http');
        $this->userContext->method('getAutoRefreshEnabled')
            ->willReturn(true);
        $this->userContext->method('getAutoBackupEnabled')
            ->willReturn(false);

        // Should not call settings() in text mode
        $this->outputManager->expects($this->never())
            ->method('settings');

        ob_start();
        $this->service->displayCurrentSettings($this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Current Settings:', $output);
        $this->assertStringContainsString('USD', $output);
    }

    // =========================================================================
    // displayHelp() Tests
    // =========================================================================

    /**
     * Test displayHelp outputs all commands in JSON mode
     */
    public function testDisplayHelpOutputsAllCommandsInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('help')
            ->with(
                $this->callback(function ($commands) {
                    return isset($commands['info'])
                        && isset($commands['add'])
                        && isset($commands['send'])
                        && isset($commands['help']);
                }),
                null
            );

        $this->service->displayHelp(['eiou', 'help'], $this->outputManager);
    }

    /**
     * Test displayHelp outputs specific command help in JSON mode
     */
    public function testDisplayHelpOutputsSpecificCommandInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('help')
            ->with(
                $this->callback(function ($commands) {
                    return isset($commands['send']);
                }),
                'send'
            );

        $this->service->displayHelp(['eiou', 'help', 'send'], $this->outputManager);
    }

    /**
     * Test displayHelp outputs error for unknown command in JSON mode
     */
    public function testDisplayHelpOutputsErrorForUnknownCommandInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unknowncommand'),
                $this->anything(),
                404
            );

        $this->service->displayHelp(['eiou', 'help', 'unknowncommand'], $this->outputManager);
    }

    /**
     * Test displayHelp outputs text for all commands in text mode
     */
    public function testDisplayHelpOutputsTextInTextMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        ob_start();
        $this->service->displayHelp(['eiou', 'help'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Available commands:', $output);
        $this->assertStringContainsString('info', $output);
        $this->assertStringContainsString('send', $output);
    }

    // =========================================================================
    // displayPendingContacts() Tests
    // =========================================================================

    /**
     * Test displayPendingContacts with no pending contacts
     */
    public function testDisplayPendingContactsWithNoPendingContacts(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([]);

        ob_start();
        $this->service->displayPendingContacts(['eiou', 'pending'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No pending contact requests', $output);
    }

    /**
     * Test displayPendingContacts with incoming and outgoing requests
     */
    public function testDisplayPendingContactsWithRequests(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([
                ['http' => 'http://incoming.test', 'created_at' => '2025-01-01 10:00:00']
            ]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([
                ['name' => 'Outgoing Contact', 'http' => 'http://outgoing.test', 'created_at' => '2025-01-01 11:00:00']
            ]);

        ob_start();
        $this->service->displayPendingContacts(['eiou', 'pending'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Incoming Requests (1)', $output);
        $this->assertStringContainsString('Outgoing Requests (1)', $output);
        $this->assertStringContainsString('http://incoming.test', $output);
        $this->assertStringContainsString('Outgoing Contact', $output);
    }

    /**
     * Test displayPendingContacts in JSON mode
     */
    public function testDisplayPendingContactsInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([
                ['http' => 'http://incoming.test', 'pubkey_hash' => 'hash1', 'created_at' => '2025-01-01']
            ]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([]);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Pending contact requests retrieved',
                $this->callback(function ($data) {
                    return $data['incoming_count'] === 1
                        && $data['outgoing_count'] === 0
                        && $data['total_count'] === 1;
                }),
                $this->anything()
            );

        $this->service->displayPendingContacts(['eiou', 'pending'], $this->outputManager);
    }

    // =========================================================================
    // displayOverview() Tests
    // =========================================================================

    /**
     * Test displayOverview with empty data
     */
    public function testDisplayOverviewWithEmptyData(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn([]);
        $this->transactionRepository->method('getRecentTransactions')
            ->willReturn([]);
        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([]);

        ob_start();
        $this->service->displayOverview(['eiou', 'overview'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('WALLET OVERVIEW', $output);
        $this->assertStringContainsString('No balances available', $output);
        $this->assertStringContainsString('No recent transactions', $output);
    }

    /**
     * Test displayOverview with balances and transactions
     */
    public function testDisplayOverviewWithData(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 10000]
            ]);
        $this->transactionRepository->method('getRecentTransactions')
            ->willReturn([
                [
                    'date' => '2025-01-01 10:00:00',
                    'type' => 'send',
                    'counterparty' => 'http://test.com',
                    'amount' => 50.00,
                    'currency' => 'USD'
                ]
            ]);
        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([]);
        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test Contact');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->generalUtility->method('truncateAddress')
            ->willReturn('http://test.com');

        ob_start();
        $this->service->displayOverview(['eiou', 'overview'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('BALANCES', $output);
        $this->assertStringContainsString('USD', $output);
    }

    /**
     * Test displayOverview with custom transaction limit
     */
    public function testDisplayOverviewWithCustomLimit(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn([]);
        $this->transactionRepository->expects($this->once())
            ->method('getRecentTransactions')
            ->with(10)
            ->willReturn([]);
        $this->contactRepository->method('getPendingContactRequests')
            ->willReturn([]);
        $this->contactRepository->method('getUserPendingContactRequests')
            ->willReturn([]);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Overview retrieved',
                $this->callback(function ($data) {
                    return $data['transaction_limit'] === 10;
                }),
                $this->anything()
            );

        $this->service->displayOverview(['eiou', 'overview', '10'], $this->outputManager);
    }

    // =========================================================================
    // viewBalances() Tests
    // =========================================================================

    /**
     * Test viewBalances with no balances
     */
    public function testViewBalancesWithNoBalances(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn(null);
        $this->contactRepository->method('getAllContacts')
            ->willReturn([]);
        $this->userContext->method('getUserAddresses')
            ->willReturn(['http://me.test']);

        ob_start();
        $this->service->viewBalances(['eiou', 'viewbalances'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No balances available', $output);
    }

    /**
     * Test viewBalances with balances in JSON mode
     */
    public function testViewBalancesWithBalancesInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 5000]
            ]);
        $this->contactRepository->method('getAllContacts')
            ->willReturn([
                ['name' => 'Test', 'pubkey' => 'pubkey1', 'http' => 'http://test.com']
            ]);
        $this->balanceRepository->method('getContactBalancesCurrency')
            ->willReturn([
                ['currency' => 'USD', 'received' => 1000, 'sent' => 500]
            ]);
        $this->userContext->method('getUserAddresses')
            ->willReturn(['http://me.test']);

        $this->outputManager->expects($this->once())
            ->method('balances')
            ->with($this->callback(function ($data) {
                return isset($data['user'])
                    && isset($data['contacts']);
            }));

        $this->service->viewBalances(['eiou', 'viewbalances'], $this->outputManager);
    }

    // =========================================================================
    // viewTransactionHistory() Tests
    // =========================================================================

    /**
     * Test viewTransactionHistory calls displayHistory for sent and received
     */
    public function testViewTransactionHistoryCallsDisplayHistory(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->userContext->method('getMaxOutput')
            ->willReturn(50);
        $this->transactionRepository->method('getSentUserTransactions')
            ->willReturn([]);
        $this->transactionRepository->method('getReceivedUserTransactions')
            ->willReturn([]);

        ob_start();
        $this->service->viewTransactionHistory(['eiou', 'history'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No transaction history found for sent', $output);
        $this->assertStringContainsString('No transaction history found for received', $output);
    }

    // =========================================================================
    // displayHistory() Tests
    // =========================================================================

    /**
     * Test displayHistory with empty transactions
     */
    public function testDisplayHistoryWithEmptyTransactions(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        ob_start();
        $this->service->displayHistory([], 'sent', 10, $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No transaction history found for sent', $output);
    }

    /**
     * Test displayHistory with transactions in JSON mode
     */
    public function testDisplayHistoryWithTransactionsInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $transactions = [
            [
                'date' => '2025-01-01 10:00:00',
                'type' => 'send',
                'counterparty' => 'http://test.com',
                'amount' => 50.00,
                'currency' => 'USD'
            ]
        ];

        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test Contact');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->outputManager->expects($this->once())
            ->method('transactionHistory')
            ->with(
                $this->callback(function ($data) {
                    return count($data) === 1
                        && $data[0]['counterparty_name'] === 'Test Contact';
                }),
                'sent',
                1,
                1
            );

        $this->service->displayHistory($transactions, 'sent', 10, $this->outputManager);
    }

    /**
     * Test displayHistory respects display limit
     */
    public function testDisplayHistoryRespectsDisplayLimit(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $transactions = [
            ['date' => '2025-01-01', 'type' => 'send', 'counterparty' => 'http://1.com', 'amount' => 10, 'currency' => 'USD'],
            ['date' => '2025-01-02', 'type' => 'send', 'counterparty' => 'http://2.com', 'amount' => 20, 'currency' => 'USD'],
            ['date' => '2025-01-03', 'type' => 'send', 'counterparty' => 'http://3.com', 'amount' => 30, 'currency' => 'USD']
        ];

        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->generalUtility->method('truncateAddress')
            ->willReturnCallback(fn($addr, $len) => substr($addr, 0, $len));

        ob_start();
        $this->service->displayHistory($transactions, 'sent', 2, $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Displaying 2 out of 3', $output);
    }

    /**
     * Test displayHistory with 0 as unlimited display limit
     */
    public function testDisplayHistoryWithZeroAsUnlimited(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $transactions = [
            ['date' => '2025-01-01', 'type' => 'send', 'counterparty' => 'http://1.com', 'amount' => 10, 'currency' => 'USD'],
            ['date' => '2025-01-02', 'type' => 'send', 'counterparty' => 'http://2.com', 'amount' => 20, 'currency' => 'USD']
        ];

        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->generalUtility->method('truncateAddress')
            ->willReturnCallback(fn($addr, $len) => substr($addr, 0, $len));

        ob_start();
        $this->service->displayHistory($transactions, 'received', 0, $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Displaying 2 out of 2', $output);
    }

    // =========================================================================
    // viewBalanceQuery() Tests
    // =========================================================================

    /**
     * Test viewBalanceQuery formats output correctly
     */
    public function testViewBalanceQueryFormatsOutput(): void
    {
        $results = [
            ['date' => '2025-01-01', 'counterparty' => 'http://test.com', 'amount' => 50.00, 'currency' => 'USD']
        ];

        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test Contact');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->generalUtility->method('truncateAddress')
            ->willReturn('http://test.com');

        ob_start();
        $this->service->viewBalanceQuery('received', 'from', $results, 10);
        $output = ob_get_clean();

        $this->assertStringContainsString('Balance received from:', $output);
        $this->assertStringContainsString('Test Contact', $output);
        $this->assertStringContainsString('USD', $output);
    }

    /**
     * Test viewBalanceQuery respects display limit
     */
    public function testViewBalanceQueryRespectsDisplayLimit(): void
    {
        $results = [
            ['date' => '2025-01-01', 'counterparty' => 'http://1.com', 'amount' => 10, 'currency' => 'USD'],
            ['date' => '2025-01-02', 'counterparty' => 'http://2.com', 'amount' => 20, 'currency' => 'USD'],
            ['date' => '2025-01-03', 'counterparty' => 'http://3.com', 'amount' => 30, 'currency' => 'USD']
        ];

        $this->contactRepository->method('lookupNameByAddress')
            ->willReturn('Test');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->generalUtility->method('truncateAddress')
            ->willReturn('http://test.com');

        ob_start();
        $this->service->viewBalanceQuery('sent', 'to', $results, 2);
        $output = ob_get_clean();

        $this->assertStringContainsString('Displaying 2 out of 3', $output);
    }

    // =========================================================================
    // changeSettings() Tests
    // =========================================================================

    /**
     * Test changeSettings rejects invalid setting
     */
    public function testChangeSettingsRejectsInvalidSetting(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                'Setting provided does not exist. No changes made.',
                $this->anything(),
                400
            );

        $this->service->changeSettings(['eiou', 'changesettings', 'invalidsetting', 'value'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects interactive mode in JSON mode
     */
    public function testChangeSettingsRejectsInteractiveModeInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Interactive mode not supported'),
                $this->anything(),
                400
            );

        $this->service->changeSettings(['eiou', 'changesettings'], $this->outputManager);
    }

    // =========================================================================
    // displayUserInfo() Tests
    // =========================================================================

    /**
     * Test displayUserInfo outputs user information in text mode
     */
    public function testDisplayUserInfoOutputsTextInTextMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(false);

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test', 'tor' => 'me.onion']);
        $this->userContext->method('getPublicKey')
            ->willReturn('-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----');
        $this->userContext->method('getMaxOutput')
            ->willReturn(50);

        ob_start();
        $this->service->displayUserInfo(['eiou', 'info'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('User Information:', $output);
        $this->assertStringContainsString('Locators:', $output);
        $this->assertStringContainsString('http://me.test', $output);
        $this->assertStringContainsString('Public Key:', $output);
    }

    /**
     * Test displayUserInfo outputs user information in JSON mode
     */
    public function testDisplayUserInfoOutputsJsonInJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);
        $this->userContext->method('getPublicKey')
            ->willReturn('test-public-key');
        $this->userContext->method('getMaxOutput')
            ->willReturn(50);

        $this->outputManager->expects($this->once())
            ->method('userInfo')
            ->with($this->callback(function ($data) {
                return isset($data['locators'])
                    && isset($data['public_key'])
                    && $data['locators']['http'] === 'http://me.test';
            }));

        $this->service->displayUserInfo(['eiou', 'info'], $this->outputManager);
    }

    /**
     * Test displayUserInfo with detail flag shows balances
     */
    public function testDisplayUserInfoWithDetailShowsBalances(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);
        $this->userContext->method('getPublicKey')
            ->willReturn('test-public-key');
        $this->userContext->method('getMaxOutput')
            ->willReturn(50);

        $this->balanceRepository->method('getUserBalance')
            ->willReturn([
                ['currency' => 'USD', 'total_balance' => 5000]
            ]);
        $this->transactionRepository->method('getReceivedUserTransactions')
            ->willReturn([]);
        $this->transactionRepository->method('getSentUserTransactions')
            ->willReturn([]);

        $this->outputManager->expects($this->once())
            ->method('userInfo')
            ->with($this->callback(function ($data) {
                return isset($data['balances'])
                    && count($data['balances']) === 1
                    && $data['balances'][0]['currency'] === 'USD';
            }));

        $this->service->displayUserInfo(['eiou', 'info', 'detail'], $this->outputManager);
    }
}
