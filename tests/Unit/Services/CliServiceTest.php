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
use Eiou\Services\CliSettingsService;
use Eiou\Services\CliHelpService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
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

        // Inject sub-services for delegation (ARCH-04)
        $this->service->setSettingsService(new CliSettingsService($this->userContext));
        $this->service->setHelpService(new CliHelpService());
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
        // New settings getters
        $this->userContext->method('getHttpAddress')
            ->willReturn('http://alice');
        $this->userContext->method('getHttpsAddress')
            ->willReturn('https://alice');
        $this->userContext->method('getTrustedProxies')
            ->willReturn('');
        $this->userContext->method('getContactStatusEnabled')
            ->willReturn(true);
        $this->userContext->method('getContactStatusSyncOnPing')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropPropose')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(false);
        $this->userContext->method('getApiEnabled')
            ->willReturn(true);
        $this->userContext->method('getApiCorsAllowedOrigins')
            ->willReturn('');
        $this->userContext->method('getRateLimitEnabled')
            ->willReturn(true);
        $this->userContext->method('getBackupRetentionCount')
            ->willReturn(3);
        $this->userContext->method('getBackupCronHour')
            ->willReturn(0);
        $this->userContext->method('getBackupCronMinute')
            ->willReturn(0);
        $this->userContext->method('getLogLevel')
            ->willReturn('INFO');
        $this->userContext->method('getLogMaxEntries')
            ->willReturn(100);
        $this->userContext->method('getCleanupDeliveryRetentionDays')
            ->willReturn(30);
        $this->userContext->method('getCleanupDlqRetentionDays')
            ->willReturn(90);
        $this->userContext->method('getCleanupHeldTxRetentionDays')
            ->willReturn(7);
        $this->userContext->method('getCleanupRp2pRetentionDays')
            ->willReturn(30);
        $this->userContext->method('getCleanupMetricsRetentionDays')
            ->willReturn(90);
        $this->userContext->method('getP2pRateLimitPerMinute')
            ->willReturn(60);
        $this->userContext->method('getRateLimitMaxAttempts')
            ->willReturn(10);
        $this->userContext->method('getRateLimitWindowSeconds')
            ->willReturn(60);
        $this->userContext->method('getRateLimitBlockSeconds')
            ->willReturn(300);
        $this->userContext->method('getHttpTransportTimeoutSeconds')
            ->willReturn(15);
        $this->userContext->method('getTorTransportTimeoutSeconds')
            ->willReturn(30);
        $this->userContext->method('getDisplayDateFormat')
            ->willReturn('Y-m-d H:i:s.u');
        $this->userContext->method('getDisplayCurrencyDecimals')
            ->willReturn(2);
        $this->userContext->method('getDisplayRecentTransactionsLimit')
            ->willReturn(5);

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
                    && $settings['auto_backup_enabled'] === false
                    // New settings assertions
                    && $settings['contact_status_enabled'] === true
                    && $settings['contact_status_sync_on_ping'] === true
                    && $settings['auto_chain_drop_propose'] === true
                    && $settings['auto_chain_drop_accept'] === false
                    && $settings['api_enabled'] === true
                    && $settings['api_cors_allowed_origins'] === ''
                    && $settings['rate_limit_enabled'] === true
                    && $settings['backup_retention_count'] === 3
                    && $settings['backup_cron_hour'] === 0
                    && $settings['backup_cron_minute'] === 0
                    && $settings['log_level'] === 'INFO'
                    && $settings['log_max_entries'] === 100
                    && $settings['cleanup_delivery_retention_days'] === 30
                    && $settings['cleanup_dlq_retention_days'] === 90
                    && $settings['cleanup_held_tx_retention_days'] === 7
                    && $settings['cleanup_rp2p_retention_days'] === 30
                    && $settings['cleanup_metrics_retention_days'] === 90
                    && $settings['p2p_rate_limit_per_minute'] === 60
                    && $settings['rate_limit_max_attempts'] === 10
                    && $settings['rate_limit_window_seconds'] === 60
                    && $settings['rate_limit_block_seconds'] === 300
                    && $settings['http_transport_timeout_seconds'] === 15
                    && $settings['tor_transport_timeout_seconds'] === 30
                    && $settings['display_date_format'] === 'Y-m-d H:i:s.u'
                    && $settings['display_recent_transactions_limit'] === 5
                    && array_key_exists('display_decimals', $settings)
                    && array_key_exists('auto_reject_unknown_currency', $settings);
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
        // New settings getters
        $this->userContext->method('getHttpAddress')
            ->willReturn('http://alice');
        $this->userContext->method('getHttpsAddress')
            ->willReturn('https://alice');
        $this->userContext->method('getTrustedProxies')
            ->willReturn('');
        $this->userContext->method('getContactStatusEnabled')
            ->willReturn(true);
        $this->userContext->method('getContactStatusSyncOnPing')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropPropose')
            ->willReturn(true);
        $this->userContext->method('getAutoChainDropAccept')
            ->willReturn(false);
        $this->userContext->method('getApiEnabled')
            ->willReturn(true);
        $this->userContext->method('getApiCorsAllowedOrigins')
            ->willReturn('');
        $this->userContext->method('getRateLimitEnabled')
            ->willReturn(true);
        $this->userContext->method('getBackupRetentionCount')
            ->willReturn(3);
        $this->userContext->method('getBackupCronHour')
            ->willReturn(0);
        $this->userContext->method('getBackupCronMinute')
            ->willReturn(0);
        $this->userContext->method('getLogLevel')
            ->willReturn('INFO');
        $this->userContext->method('getLogMaxEntries')
            ->willReturn(100);
        $this->userContext->method('getCleanupDeliveryRetentionDays')
            ->willReturn(30);
        $this->userContext->method('getCleanupDlqRetentionDays')
            ->willReturn(90);
        $this->userContext->method('getCleanupHeldTxRetentionDays')
            ->willReturn(7);
        $this->userContext->method('getCleanupRp2pRetentionDays')
            ->willReturn(30);
        $this->userContext->method('getCleanupMetricsRetentionDays')
            ->willReturn(90);
        $this->userContext->method('getP2pRateLimitPerMinute')
            ->willReturn(60);
        $this->userContext->method('getRateLimitMaxAttempts')
            ->willReturn(10);
        $this->userContext->method('getRateLimitWindowSeconds')
            ->willReturn(60);
        $this->userContext->method('getRateLimitBlockSeconds')
            ->willReturn(300);
        $this->userContext->method('getHttpTransportTimeoutSeconds')
            ->willReturn(15);
        $this->userContext->method('getTorTransportTimeoutSeconds')
            ->willReturn(30);
        $this->userContext->method('getDisplayDateFormat')
            ->willReturn('Y-m-d H:i:s.u');
        $this->userContext->method('getDisplayCurrencyDecimals')
            ->willReturn(2);
        $this->userContext->method('getDisplayRecentTransactionsLimit')
            ->willReturn(5);

        // Should not call settings() in text mode
        $this->outputManager->expects($this->never())
            ->method('settings');

        ob_start();
        $this->service->displayCurrentSettings($this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Current Settings:', $output);
        $this->assertStringContainsString('USD', $output);
        // Verify new settings sections appear in text output
        $this->assertStringContainsString('Feature Toggles:', $output);
        $this->assertStringContainsString('Backup & Logging:', $output);
        $this->assertStringContainsString('Data Retention:', $output);
        $this->assertStringContainsString('Rate Limiting:', $output);
        $this->assertStringContainsString('Display:', $output);
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

    /**
     * Test changeSettings accepts valid boolean feature toggle
     */
    public function testChangeSettingsAcceptsValidBooleanToggle(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'contactStatusEnabled'
                        && $data['value'] === false;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'contactStatusEnabled', 'false'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid integer setting
     */
    public function testChangeSettingsAcceptsValidIntegerSetting(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'backupRetentionCount'
                        && $data['value'] === 5;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'backupRetentionCount', '5'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects invalid log level
     */
    public function testChangeSettingsRejectsInvalidLogLevel(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('logLevel', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'logLevel', 'INVALID'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid log level (case insensitive)
     */
    public function testChangeSettingsAcceptsValidLogLevel(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'logLevel'
                        && $data['value'] === 'WARNING';
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'logLevel', 'warning'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects out-of-range integer (backupCronHour > 23)
     */
    public function testChangeSettingsRejectsOutOfRangeInteger(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('backupCronHour', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'backupCronHour', '25'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid backup cron hour
     */
    public function testChangeSettingsAcceptsValidBackupCronHour(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'backupCronHour'
                        && $data['value'] === 14;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'backupCronHour', '14'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid HTTP timeout within range
     */
    public function testChangeSettingsAcceptsValidHttpTimeout(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'httpTransportTimeoutSeconds'
                        && $data['value'] === 30;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'httpTransportTimeoutSeconds', '30'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects HTTP timeout below minimum
     */
    public function testChangeSettingsRejectsHttpTimeoutBelowMinimum(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('httpTransportTimeoutSeconds', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'httpTransportTimeoutSeconds', '2'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects HTTP timeout above maximum
     */
    public function testChangeSettingsRejectsHttpTimeoutAboveMaximum(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('httpTransportTimeoutSeconds', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'httpTransportTimeoutSeconds', '200'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid date format
     */
    public function testChangeSettingsAcceptsValidDateFormat(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'displayDateFormat'
                        && $data['value'] === 'Y-m-d H:i';
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'displayDateFormat', 'Y-m-d H:i'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects empty date format
     */
    public function testChangeSettingsRejectsEmptyDateFormat(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('displayDateFormat', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'displayDateFormat', ''], $this->outputManager);
    }

    /**
     * Test changeSettings rejects removed currencyDecimals setting
     */
    public function testChangeSettingsRejectsCurrencyDecimalsAsUnknown(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                'Setting provided does not exist. No changes made.',
                $this->anything(),
                $this->anything()
            );

        $this->service->changeSettings(['eiou', 'changesettings', 'currencyDecimals', '{"USD":2}'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid data retention days
     */
    public function testChangeSettingsAcceptsValidRetentionDays(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'cleanupDeliveryRetentionDays'
                        && $data['value'] === 60;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'cleanupDeliveryRetentionDays', '60'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects zero retention days
     */
    public function testChangeSettingsRejectsZeroRetentionDays(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('cleanupDlqRetentionDays', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'cleanupDlqRetentionDays', '0'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid rate limit setting
     */
    public function testChangeSettingsAcceptsValidRateLimit(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'p2pRateLimitPerMinute'
                        && $data['value'] === 120;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'p2pRateLimitPerMinute', '120'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid Tor timeout
     */
    public function testChangeSettingsAcceptsValidTorTimeout(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torTransportTimeoutSeconds'
                        && $data['value'] === 60;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'torTransportTimeoutSeconds', '60'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts CORS origins string
     */
    public function testChangeSettingsAcceptsCorsOrigins(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'apiCorsAllowedOrigins'
                        && $data['value'] === 'https://example.com';
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'apiCorsAllowedOrigins', 'https://example.com'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid recent transactions limit
     */
    public function testChangeSettingsAcceptsRecentTransactionsLimit(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'displayRecentTransactionsLimit'
                        && $data['value'] === 10;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'displayRecentTransactionsLimit', '10'], $this->outputManager);
    }

    /**
     * Test changeSettings setting names are case-insensitive
     */
    public function testChangeSettingsIsCaseInsensitive(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'rateLimitEnabled'
                        && $data['value'] === false;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'RATELIMITENABLED', 'false'], $this->outputManager);
    }

    // =========================================================================
    // Tor Circuit Health Settings Tests
    // =========================================================================

    /**
     * Test changeSettings accepts valid torCircuitMaxFailures
     */
    public function testChangeSettingsAcceptsValidTorCircuitMaxFailures(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torCircuitMaxFailures'
                        && $data['value'] === 5;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'torCircuitMaxFailures', '5'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects out-of-range torCircuitMaxFailures
     */
    public function testChangeSettingsRejectsInvalidTorCircuitMaxFailures(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('torCircuitMaxFailures', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'torCircuitMaxFailures', '0'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid torCircuitCooldownSeconds
     */
    public function testChangeSettingsAcceptsValidTorCircuitCooldownSeconds(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torCircuitCooldownSeconds'
                        && $data['value'] === 600;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'torCircuitCooldownSeconds', '600'], $this->outputManager);
    }

    /**
     * Test changeSettings rejects out-of-range torCircuitCooldownSeconds
     */
    public function testChangeSettingsRejectsInvalidTorCircuitCooldownSeconds(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('validationError')
            ->with('torCircuitCooldownSeconds', $this->anything());

        $this->service->changeSettings(['eiou', 'changesettings', 'torCircuitCooldownSeconds', '10'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid torFailureTransportFallback
     */
    public function testChangeSettingsAcceptsValidTorFailureTransportFallback(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torFailureTransportFallback'
                        && $data['value'] === false;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'torFailureTransportFallback', 'false'], $this->outputManager);
    }

    /**
     * Test changeSettings accepts valid torFallbackRequireEncrypted
     */
    public function testChangeSettingsAcceptsValidTorFallbackRequireEncrypted(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torFallbackRequireEncrypted'
                        && $data['value'] === false;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'torFallbackRequireEncrypted', 'false'], $this->outputManager);
    }

    /**
     * Test changeSettings is case-insensitive for tor settings
     */
    public function testChangeSettingsTorSettingsCaseInsensitive(): void
    {
        $this->outputManager->method('isJsonMode')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'Setting updated successfully.',
                $this->callback(function ($data) {
                    return $data['setting'] === 'torCircuitMaxFailures'
                        && $data['value'] === 3;
                }),
                $this->anything()
            );

        @$this->service->changeSettings(['eiou', 'changesettings', 'TORCIRCUITMAXFAILURES', '3'], $this->outputManager);
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
