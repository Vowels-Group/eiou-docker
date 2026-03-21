<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Utils\SecureSeedphraseDisplay;
use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\CliServiceInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\GeneralUtilityService;
use Eiou\Core\UserContext;

/**
 * Cli Service
 *
 * Handles all business logic for cli management.
 *
 * Facade that delegates to focused sub-services (ARCH-04):
 * - CliSettingsService: Settings management and display
 * - CliHelpService: Help command display
 * - CliP2pApprovalService: P2P transaction approval workflow
 * - CliDlqService: Dead letter queue management
 *
 * Retained in this class:
 * - User Information (displayUserInfo, displayPendingContacts, displayOverview)
 * - Balance Operations (viewBalances, viewBalanceQuery)
 * - Transaction History (viewTransactionHistory, displayHistory)
 */
class CliService implements CliServiceInterface {

    // =========================================================================
    // PROPERTIES & CONSTRUCTOR
    // =========================================================================

    /**
     * @var ContactRepository Contact Repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var CurrencyUtilityService Currency utility service 
     */
    private CurrencyUtilityService $currencyUtility;

    /**
     * @var TransportUtilityService Transport utility service 
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var GeneralUtilityService General utility service 
     */
    private GeneralUtilityService $generalUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var RepositoryFactory|null Repository factory for lazy repository access
     */
    private ?RepositoryFactory $repositoryFactory = null;

    /**
     * @var ContactCreditRepository|null Contact credit repository (optional, setter-injected)
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * @var P2pRepository|null P2P repository for fee earnings display (optional, setter-injected)
     */
    private ?P2pRepository $p2pRepository = null;

    /**
     * @var CliP2pApprovalService|null P2P approval sub-service (optional, setter-injected)
     */
    private ?CliP2pApprovalService $p2pApprovalService = null;

    /**
     * @var CliDlqService|null DLQ management sub-service (optional, setter-injected)
     */
    private ?CliDlqService $dlqService = null;

    /**
     * @var CliSettingsService|null Settings management sub-service (optional, setter-injected)
     */
    private ?CliSettingsService $settingsService = null;

    /**
     * @var CliHelpService|null Help display sub-service (optional, setter-injected)
     */
    private ?CliHelpService $helpService = null;

    /**
     * Constructor
     * 
     * @param ContactRepository $contactRepository Contact Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?RepositoryFactory $repositoryFactory = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currentUser = $currentUser;
        $this->currencyUtility = $utilityContainer->getCurrencyUtility();
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->generalUtility = $utilityContainer->getGeneralUtility();
        if ($repositoryFactory !== null) {
            $this->repositoryFactory = $repositoryFactory;
            $this->contactCreditRepository = $repositoryFactory->get(\Eiou\Database\ContactCreditRepository::class);
            $this->p2pRepository = $repositoryFactory->get(\Eiou\Database\P2pRepository::class);
        }
    }

    /**
     * Set the P2P approval sub-service (optional, for CLI p2p commands)
     */
    public function setP2pApprovalService(CliP2pApprovalService $p2pApprovalService): void {
        $this->p2pApprovalService = $p2pApprovalService;
    }

    /**
     * Set the DLQ management sub-service (optional, for CLI dlq commands)
     */
    public function setDlqService(CliDlqService $dlqService): void {
        $this->dlqService = $dlqService;
    }

    /**
     * Set the settings management sub-service (for CLI settings commands)
     */
    public function setSettingsService(CliSettingsService $settingsService): void {
        $this->settingsService = $settingsService;
    }

    /**
     * Set the help display sub-service (for CLI help command)
     */
    public function setHelpService(CliHelpService $helpService): void {
        $this->helpService = $helpService;
    }

    // =========================================================================
    // SETTINGS MANAGEMENT (delegated to CliSettingsService)
    // =========================================================================

    /**
     * Handler for (CLI) input changes to user settings
     *
     * @param array $argv The (CLI) input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function changeSettings(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->settingsService === null) {
            $output->error('Settings service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->settingsService->changeSettings($argv, $output);
    }

    /**
     * Display current settings of user in the CLI
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayCurrentSettings(?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->settingsService === null) {
            $output->error('Settings service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->settingsService->displayCurrentSettings($output);
    }

    // =========================================================================
    // HELP DISPLAY (delegated to CliHelpService)
    // =========================================================================

    /**
     * Display available commands to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayHelp(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->helpService === null) {
            $output->error('Help service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->helpService->displayHelp($argv, $output);
    }

    /**
     * Display user information to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayUserInfo(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

       // Define limit of output displayed (0 = unlimited)
        if(isset($argv[3]) && is_numeric($argv[3]) && intval($argv[3]) >= 0){
            $displayLimit = intval($argv[3]);
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

        // Check for --show-auth flag (security: authcode is redacted by default)
        $showAuth = in_array('--show-auth', $argv, true);

        // Locators array
        $locators = $this->currentUser->getUserLocaters();

        // Handle authcode securely - never expose directly in output
        // SECURITY: Authentication code is stored in a secure temp file to prevent exposure in logs
        $authcodeInfo = ['status' => '[REDACTED]'];
        if ($showAuth) {
            $authcode = $this->currentUser->getAuthCode();
            if ($authcode) {
                $displayResult = SecureSeedphraseDisplay::displayAuthcode($authcode);
                if ($displayResult['success']) {
                    $authcodeInfo = [
                        'status' => 'stored_securely',
                        'method' => $displayResult['method'],
                        'filepath' => $displayResult['filepath'] ?? null,
                        'ttl_seconds' => $displayResult['ttl'] ?? null,
                        'message' => $displayResult['message']
                    ];
                } else {
                    $authcodeInfo = [
                        'status' => 'display_failed',
                        'reason' => $displayResult['reason'] ?? 'Unknown error'
                    ];
                }
            } else {
                $authcodeInfo = ['status' => 'not_available'];
            }
        }

        // Get total fee earnings per currency
        $totalEarnings = [];
        if ($this->p2pRepository !== null) {
            try {
                $earningsRows = $this->p2pRepository->getUserTotalEarningsByCurrency();
                foreach ($earningsRows as $row) {
                    $totalEarnings[] = [
                        'currency' => $row['currency'],
                        'total_earnings' => number_format($row['total_amount']->toMajorUnits(), Constants::getDisplayDecimals($row['currency']))
                    ];
                }
            } catch (\Exception $e) {
                // Non-critical — skip earnings display
            }
        }

        // Get total available credit per currency
        $totalAvailableCredit = [];
        if ($this->contactCreditRepository !== null) {
            try {
                $creditRows = $this->contactCreditRepository->getTotalAvailableCreditByCurrency();
                foreach ($creditRows as $row) {
                    $totalAvailableCredit[] = [
                        'currency' => $row['currency'],
                        'total_available_credit' => number_format($row['total_available_credit']->toMajorUnits(), Constants::getDisplayDecimals($row['currency']))
                    ];
                }
            } catch (\Exception $e) {
                // Non-critical — skip available credit display
            }
        }

        // Build user info data structure
        $userInfo = [
            'locators' => $locators,
            'authentication_code' => $authcodeInfo,
            'public_key' => $this->currentUser->getPublicKey(),
            'total_earnings' => $totalEarnings,
            'total_available_credit' => $totalAvailableCredit
        ];

        $showDetails = isset($argv[2]) && strtolower($argv[2]) === 'detail';

        if ($showDetails) {
            // Get total sent and received by currency
            $balances = $this->balanceRepository->getUserBalance();
            $userInfo['balances'] = [];

            if(isset($balances)){
                foreach($balances as $balance){
                    $balanceData = [
                        'currency' => $balance['currency'],
                        'total_balance' => number_format($balance['total_balance']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency'])),
                        'received' => [],
                        'sent' => []
                    ];

                    // Get received transactions
                    $receivedTx = $this->transactionRepository->getReceivedUserTransactions(PHP_INT_MAX, $balance['currency']);
                    foreach ($receivedTx as $tx) {
                        $balanceData['received'][] = [
                            'date' => $tx['date'],
                            'counterparty_name' => $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($tx['counterparty']), $tx['counterparty']),
                            'counterparty_address' => $tx['counterparty'],
                            'amount' => $tx['amount'],
                            'currency' => $tx['currency']
                        ];
                    }

                    // Get sent transactions
                    $sentTx = $this->transactionRepository->getSentUserTransactions(PHP_INT_MAX, $balance['currency']);
                    foreach ($sentTx as $tx) {
                        $balanceData['sent'][] = [
                            'date' => $tx['date'],
                            'counterparty_name' => $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($tx['counterparty']), $tx['counterparty']),
                            'counterparty_address' => $tx['counterparty'],
                            'amount' => $tx['amount'],
                            'currency' => $tx['currency']
                        ];
                    }

                    $userInfo['balances'][] = $balanceData;
                }
            }
        }

        if ($output->isJsonMode()) {
            $output->userInfo($userInfo);
        } else {
            echo "User Information:\n";

            // Output locators
            echo "\tLocators:\n";
            foreach ($locators as $type => $address) {
                printf("\t\t• %-5s: %s\n",$type,$address);
            }

            // Authentication code - redacted by default for security
            if ($showAuth) {
                // Authcode was displayed via SecureSeedphraseDisplay above
                if ($authcodeInfo['status'] === 'stored_securely') {
                    if ($authcodeInfo['method'] === 'tty') {
                        echo "\tAuthentication Code: [Displayed securely above - not logged]\n";
                    } else {
                        echo "\tAuthentication Code: [Stored in secure temp file]\n";
                        if (!empty($authcodeInfo['filepath'])) {
                            $containerName = gethostname() ?: '<container>';
                            echo "\t\tTo view: docker exec $containerName cat " . $authcodeInfo['filepath'] . "\n";
                            echo "\t\tAuto-deletes in " . ($authcodeInfo['ttl_seconds'] ?? 300) . " seconds\n";
                        }
                    }
                } elseif ($authcodeInfo['status'] === 'not_available') {
                    echo "\tAuthentication Code: [Not available]\n";
                } else {
                    echo "\tAuthentication Code: [Display failed: " . ($authcodeInfo['reason'] ?? 'unknown') . "]\n";
                }
            } else {
                echo "\tAuthentication Code: [REDACTED - use --show-auth to display securely]\n";
            }

            $pubkey = $this->currentUser->getPublicKey();
            // Public key is from the config file
            $readablePubKey = "\n\t\t" . str_replace("\n","\n\t\t",$pubkey);
            echo "\tPublic Key:" . $readablePubKey . "\n";

            // Total fee earnings per currency (always show, fallback to balance currencies)
            echo "\tTotal Fee Earnings:\n";
            if (!empty($totalEarnings)) {
                foreach ($totalEarnings as $earning) {
                    printf("\t\t%s: %s\n", $earning['currency'], $earning['total_earnings']);
                }
            } else {
                // Show 0.00 for each known currency from balances
                $balanceCurrencies = $this->balanceRepository->getUserBalance();
                if (!empty($balanceCurrencies)) {
                    foreach ($balanceCurrencies as $b) {
                        printf("\t\t%s: 0.00\n", $b['currency']);
                    }
                } else {
                    echo "\t\t0.00\n";
                }
            }

            // Total available credit across all contacts, per currency (always show)
            echo "\tTotal Available Credit:\n";
            if (!empty($totalAvailableCredit)) {
                foreach ($totalAvailableCredit as $credit) {
                    printf("\t\t%s: %s\n", $credit['currency'], $credit['total_available_credit']);
                }
            } else {
                // Show 0.00 for each known currency from balances
                $balanceCurrencies = $balanceCurrencies ?? $this->balanceRepository->getUserBalance();
                if (!empty($balanceCurrencies)) {
                    foreach ($balanceCurrencies as $b) {
                        printf("\t\t%s: 0.00\n", $b['currency']);
                    }
                } else {
                    echo "\t\t0.00\n";
                }
            }

            if ($showDetails){
                // Get total sent and received by currency
                $balances = $this->balanceRepository->getUserBalance();
                if(isset($balances)){
                    foreach($balances as $balance){
                        printf("\tTotal Balance %s : %s\n", $balance['currency'], number_format($balance['total_balance']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency'])));
                        $this->viewBalanceQuery("received","from",$this->transactionRepository->getReceivedUserTransactions(PHP_INT_MAX, $balance['currency']), $displayLimit);
                        $this->viewBalanceQuery("sent","to",$this->transactionRepository->getSentUserTransactions(PHP_INT_MAX, $balance['currency']), $displayLimit);
                    }
                } else{
                    printf("\tNo balances available yet.\n");
                }
            }
        }
    }

    /**
     * Display pending contact requests (both incoming and outgoing)
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function displayPendingContacts(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Get incoming pending requests (requests from others - no name set yet)
        $incomingRequests = $this->contactRepository->getPendingContactRequests();

        // Get outgoing pending requests (requests user initiated - has name set)
        $outgoingRequests = $this->contactRepository->getUserPendingContactRequests();

        $pendingData = [
            'incoming' => [],
            'outgoing' => [],
            'incoming_count' => count($incomingRequests),
            'outgoing_count' => count($outgoingRequests),
            'total_count' => count($incomingRequests) + count($outgoingRequests)
        ];

        // Format incoming requests (include description from contact transaction if present)
        $txContactRepo = $this->repositoryFactory ? $this->repositoryFactory->get(\Eiou\Database\TransactionContactRepository::class) : null;
        $myPubkey = $this->currentUser->getPublicKey();
        foreach ($incomingRequests as $contact) {
            $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? 'Unknown';
            $entry = [
                'address' => $contactAddress,
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'created_at' => $contact['created_at'] ?? null,
                'status' => 'pending'
            ];
            // Get the description/message from the contact transaction
            if ($txContactRepo !== null) {
                $contactTx = $txContactRepo->getContactTransactionByParties($contact['pubkey'], $myPubkey);
                $desc = $contactTx['description'] ?? null;
                if ($desc !== null && $desc !== 'Contact request transaction') {
                    $entry['description'] = $desc;
                }
            }
            $pendingData['incoming'][] = $entry;
        }

        // Format outgoing requests
        foreach ($outgoingRequests as $contact) {
            $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? 'Unknown';
            $pendingData['outgoing'][] = [
                'name' => $contact['name'],
                'address' => $contactAddress,
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'created_at' => $contact['created_at'] ?? null,
                'status' => 'pending'
            ];
        }

        if ($output->isJsonMode()) {
            $output->success('Pending contact requests retrieved', $pendingData, 'Pending contact requests');
        } else {
            $totalCount = $pendingData['total_count'];

            if ($totalCount === 0) {
                echo "No pending contact requests.\n";
                return;
            }

            echo "Pending Contact Requests\n";
            echo "========================\n\n";

            // Display incoming requests
            if ($pendingData['incoming_count'] > 0) {
                echo "Incoming Requests ({$pendingData['incoming_count']}):\n";
                echo "-------------------------------------------\n";
                foreach ($pendingData['incoming'] as $contact) {
                    echo "  From: " . $contact['address'] . "\n";
                    if (!empty($contact['description'])) {
                        echo "  Message: " . $contact['description'] . "\n";
                    }
                    if ($contact['created_at']) {
                        echo "  Date: " . $contact['created_at'] . "\n";
                    }
                    echo "  To accept: eiou add " . $contact['address'] . " [name] [fee] [credit] [currency]\n";
                    echo "\n";
                }
            } else {
                echo "Incoming Requests: None\n\n";
            }

            // Display outgoing requests
            if ($pendingData['outgoing_count'] > 0) {
                echo "Outgoing Requests ({$pendingData['outgoing_count']}):\n";
                echo "-------------------------------------------\n";
                foreach ($pendingData['outgoing'] as $contact) {
                    echo "  Name: " . $contact['name'] . "\n";
                    echo "  Address: " . $contact['address'] . "\n";
                    if ($contact['created_at']) {
                        echo "  Date: " . $contact['created_at'] . "\n";
                    }
                    echo "  Status: Awaiting acceptance from recipient\n";
                    echo "\n";
                }
            } else {
                echo "Outgoing Requests: None\n\n";
            }

            echo "-------------------------------------------\n";
            echo "Total: {$totalCount} pending request(s)\n";
        }
    }

    /**
     * Display overview dashboard with balances and recent transactions
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function displayOverview(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Determine limit for recent transactions (default 5)
        $transactionLimit = 5;
        if (isset($argv[2]) && is_numeric($argv[2]) && intval($argv[2]) > 0) {
            $transactionLimit = intval($argv[2]);
        }

        // Get overall balances
        $balances = $this->balanceRepository->getUserBalance();

        // Get recent transactions
        $recentTransactions = $this->transactionRepository->getRecentTransactions($transactionLimit);

        // Get pending contact counts for quick overview
        $incomingPending = count($this->contactRepository->getPendingContactRequests());
        $outgoingPending = count($this->contactRepository->getUserPendingContactRequests());

        // Build overview data
        $overviewData = [
            'balances' => [],
            'recent_transactions' => [],
            'pending_contacts' => [
                'incoming' => $incomingPending,
                'outgoing' => $outgoingPending,
                'total' => $incomingPending + $outgoingPending
            ],
            'transaction_count' => count($recentTransactions),
            'transaction_limit' => $transactionLimit
        ];

        // Format balances
        if ($balances) {
            foreach ($balances as $balance) {
                $overviewData['balances'][] = [
                    'currency' => $balance['currency'],
                    'total_balance' => number_format($balance['total_balance']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency']))
                ];
            }
        }

        // Format recent transactions
        foreach ($recentTransactions as $tx) {
            $counterpartyAddress = $tx['counterparty'] ?? $tx['sender_address'] ?? $tx['receiver_address'] ?? 'Unknown';
            $counterpartyName = $this->contactRepository->lookupNameByAddress(
                $this->transportUtility->determineTransportType($counterpartyAddress),
                $counterpartyAddress
            );

            $overviewData['recent_transactions'][] = [
                'timestamp' => $tx['date'] ?? $tx['timestamp'] ?? null,
                'type' => $tx['type'] ?? 'unknown',
                'direction' => $tx['direction'] ?? $tx['type'] ?? null,
                'counterparty_name' => $counterpartyName,
                'counterparty_address' => $this->generalUtility->truncateAddress($counterpartyAddress, 30),
                'amount' => $tx['amount'] ?? 0,
                'currency' => $tx['currency'] ?? 'N/A',
                'status' => $tx['status'] ?? null
            ];
        }

        if ($output->isJsonMode()) {
            $output->success('Overview retrieved', $overviewData, 'Wallet overview');
        } else {
            echo "===========================================\n";
            echo "             WALLET OVERVIEW\n";
            echo "===========================================\n\n";

            // Display balances
            echo "BALANCES\n";
            echo "-------------------------------------------\n";
            if (empty($overviewData['balances'])) {
                echo "  No balances available.\n";
            } else {
                foreach ($overviewData['balances'] as $balance) {
                    printf("  %s: %s\n", $balance['currency'], $balance['total_balance']);
                }
            }
            echo "\n";

            // Display pending contacts summary
            if ($overviewData['pending_contacts']['total'] > 0) {
                echo "PENDING CONTACTS\n";
                echo "-------------------------------------------\n";
                if ($overviewData['pending_contacts']['incoming'] > 0) {
                    printf("  Incoming requests: %d\n", $overviewData['pending_contacts']['incoming']);
                }
                if ($overviewData['pending_contacts']['outgoing'] > 0) {
                    printf("  Outgoing requests: %d\n", $overviewData['pending_contacts']['outgoing']);
                }
                echo "  (Use 'eiou pending' for details)\n";
                echo "\n";
            }

            // Display recent transactions
            echo "RECENT TRANSACTIONS (Last {$transactionLimit})\n";
            echo "-------------------------------------------\n";
            if (empty($overviewData['recent_transactions'])) {
                echo "  No recent transactions.\n";
            } else {
                echo str_pad("Date", 20) . " | " .
                     str_pad("Type", 10) . " | " .
                     str_pad("Contact", 25) . " | " .
                     str_pad("Amount", 15) . "\n";
                echo "-------------------------------------------\n";

                foreach ($overviewData['recent_transactions'] as $tx) {
                    $date = $tx['timestamp'] ? substr($tx['timestamp'], 0, 19) : 'N/A';
                    $contactDisplay = $tx['counterparty_name'] ?: $tx['counterparty_address'];
                    if (strlen($contactDisplay) > 25) {
                        $contactDisplay = substr($contactDisplay, 0, 22) . '...';
                    }

                    printf("%s | %s | %s | %s %s\n",
                        str_pad($date, 20),
                        str_pad($tx['type'], 10),
                        str_pad($contactDisplay, 25),
                        str_pad(number_format($tx['amount'], Constants::getDisplayDecimals($tx['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY)), 10),
                        $tx['currency']
                    );
                }
            }
            echo "-------------------------------------------\n";
            echo "(Use 'eiou history' for full transaction history)\n";
        }
    }

    // =========================================================================
    // BALANCE OPERATIONS
    // =========================================================================

    /**
     * View balance information in the CLI based on transactions, either received or send by user
     *
     * @param string $direction received/send
     * @param string $where from/to
     * @param array $results Formated transaction data
     * @param int $displayLimit The limit of output displayed
    */
    public function viewBalanceQuery(string $direction, string $where, array $results, int $displayLimit){
        $countResults = count($results);
        echo "\t\tBalance $direction $where:\n";
        $countrows = 1;
        foreach ($results as $res) {
            printf("\t\t\t%s %s %s (%s), %s %s\n",
                    $res['date'],
                    "|",
                    $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($res['counterparty']),$res['counterparty']),
                    $this->generalUtility->truncateAddress($res['counterparty'],30),
                    number_format($res['amount'], Constants::getDisplayDecimals($res['currency'])),
                    $res['currency']);
            if($displayLimit > 0 && ($countrows >= $displayLimit)){
                break;
            }
            $countrows += 1;
        }
        if ($displayLimit === 0 || $displayLimit > $countResults) {
            $displayLimit = $countResults;
        } 
        echo "\t\t\t----- Displaying $displayLimit out of $countResults $direction balance(s) -----\n";
    }

    /**
     * Display balance information, based on transactions, to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function viewBalances(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        // Check if an address or name is provided
        $contactResult = null;
        $filterAddress = null;
        if (isset($argv[2])) {
            $filterAddress = $argv[2];
            // Check if it's a HTTP, HTTPS, or Tor address
            if ($this->transportUtility->isAddress($argv[2])) {
                $address = $argv[2];
                $transportIndex = $this->transportUtility->determineTransportType($address);
                if($this->contactRepository->contactExists($transportIndex, $address)){
                    $contactResult = $this->contactRepository->lookupByAddress($transportIndex, $address);
                }
            } else{
                // Check if the name yields an address
                $contactResult = $this->contactRepository->lookupByName($argv[2]);
            }
        }

        $additionalAddresses = $this->currentUser->getUserAddresses();
        $additionalInfo = $additionalAddresses ? '(' . implode(', ', $additionalAddresses) . ')' : '';

        if(!$contactResult){
            $contacts = $this->contactRepository->getAllContacts();
        }

        $balances = $this->balanceRepository->getUserBalance();

        if ($output->isJsonMode()) {
            // Build JSON response
            $balanceData = [
                'user' => [
                    'addresses' => $additionalAddresses,
                    'balances' => []
                ],
                'contacts' => [],
                'filter' => $filterAddress
            ];

            if($balances){
                foreach($balances as $balance){
                    if ($contactResult) {
                        $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contactResult['pubkey'], $balance['currency']);
                        $contactNetBalance = SplitAmount::zero();
                        foreach($contactBalances as $cb){
                            $contactNetBalance = $contactNetBalance->add($cb['received']->subtract($cb['sent']));
                        }
                        $balanceData['user']['balances'][] = [
                            'currency' => $balance['currency'],
                            'contact_balance' => number_format($contactNetBalance->toMajorUnits(), Constants::getDisplayDecimals($balance['currency'])),
                            'total_balance' => number_format($balance['total_balance']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency']))
                        ];
                        foreach($contactBalances as $contactBalance){
                            $balanceData['contacts'][] = [
                                'name' => $contactResult['name'],
                                'address' => $contactResult['tor'] ?? $contactResult['https'] ?? $contactResult['http'],
                                'currency' => $contactBalance['currency'],
                                'received' => number_format($contactBalance['received']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency'])),
                                'sent' => number_format($contactBalance['sent']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency']))
                            ];
                        }
                    } else {
                        $balanceData['user']['balances'][] = [
                            'currency' => $balance['currency'],
                            'total_balance' => number_format($balance['total_balance']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency']))
                        ];
                        if(isset($contacts)){
                            foreach($contacts as $contact){
                                $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                                foreach($contactBalances as $contactBalance){
                                    $balanceData['contacts'][] = [
                                        'name' => $contact['name'],
                                        'address' => $contact['tor'] ?? $contact['https'] ?? $contact['http'],
                                        'currency' => $contactBalance['currency'],
                                        'received' => number_format($contactBalance['received']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency'])),
                                        'sent' => number_format($contactBalance['sent']->toMajorUnits(), Constants::getDisplayDecimals($balance['currency']))
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            $output->balances($balanceData);
        } else {
            // Original text output
            if(!$contactResult && isset($argv[2])){
                echo "Address/Name unknown or not provided, displaying all balances.\n";
            }

            if($balances){
                foreach($balances as $balance){
                    if ($contactResult) {
                        $contactBalances= $this->balanceRepository->getContactBalancesCurrency($contactResult['pubkey'],$balance['currency']);
                        $contactNetBalance = SplitAmount::zero();
                        foreach($contactBalances as $contactBalance){
                            $contactNetBalance = $contactNetBalance->add($contactBalance['received']->subtract($contactBalance['sent']));
                        }
                        $decimals = Constants::getDisplayDecimals($balance['currency']);
                        printf("%s %s, Balance %s with %s: %s\n", 'me', $additionalInfo, $balance['currency'], $contactResult['name'], number_format($contactNetBalance->toMajorUnits(), $decimals));
                        foreach($contactBalances as $contactBalance){
                            printf("\t%s (%s), Balance (%s | %s): %s | %s %s\n",
                                $contactResult['name'],
                                $contactResult['tor'] ?? $contactResult['https'] ?? $contactResult['http'],
                                'received',
                                'sent',
                                number_format($contactBalance['received']->toMajorUnits(), $decimals),
                                number_format($contactBalance['sent']->toMajorUnits(), $decimals),
                                $contactBalance['currency']
                            );
                        }
                        return;
                    } else{
                        $decimals = Constants::getDisplayDecimals($balance['currency']);
                        printf("%s %s, Balance %s : %s\n", 'me', $additionalInfo, $balance['currency'], number_format($balance['total_balance']->toMajorUnits(), $decimals));
                        if(!isset($contacts) || !$contacts){
                            echo "\tNo Contacts exist, so no contact balances can be displayed.\n";
                            continue;
                        } else{
                            foreach($contacts as $contact){
                                $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                                foreach($contactBalances as $contactBalance){
                                    printf("\t%s (%s), Balance (%s | %s): %s | %s %s\n",
                                        $contact['name'],
                                        $contact['tor'] ?? $contact['https'] ?? $contact['http'],
                                        'received',
                                        'sent',
                                        number_format($contactBalance['received']->toMajorUnits(), $decimals),
                                        number_format($contactBalance['sent']->toMajorUnits(), $decimals),
                                        $contactBalance['currency']
                                    );
                                }
                            }
                        }
                    }
                }
            } else{
                 echo "No balances available.\n";
            }
        }
    }

    // =========================================================================
    // TRANSACTION HISTORY
    // =========================================================================

    /**
     * Display all transaction history in pretty print 'table' to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function viewTransactionHistory(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        // Display limit: 0 = unlimited, positive integer = cap output lines
        if(isset($argv[3]) && is_numeric($argv[3]) && intval($argv[3]) >= 0){
            $displayLimit = intval($argv[3]);
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

        $contactResult = null;
        $transportIndex = null;

        // Check if an address or name is provided
        if (isset($argv[2])) {
            // First check if it's an HTTP, HTTPS, or Tor address
            if ($this->transportUtility->isAddress($argv[2])) {
                $address = $argv[2];
                $transportIndex = $this->transportUtility->determineTransportType($address);
                if($this->contactRepository->contactExists($transportIndex, $address)){
                    $contactResult = $this->contactRepository->lookupByAddress($transportIndex, $address);
                }
                if ($contactResult && $transportIndex && isset($contactResult[$transportIndex])) {
                    $sentTransactions = $this->transactionRepository->getSentUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                    $receivedTransactions = $this->transactionRepository->getReceivedUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                    $this->displayHistory($sentTransactions, 'sent', $displayLimit, $output);
                    $this->displayHistory($receivedTransactions, 'received', $displayLimit, $output);
                    return;
                }
            } else {
                // Check if the name yields an address - query ALL transport addresses for this contact
                $contactResult = $this->contactRepository->lookupByName($argv[2]);
                if ($contactResult) {
                    $sentTransactions = [];
                    $receivedTransactions = [];
                    foreach (Constants::VALID_TRANSPORT_INDICES as $type) {
                        if (!empty($contactResult[$type])) {
                            $sent = $this->transactionRepository->getSentUserTransactionsAddress($contactResult[$type], PHP_INT_MAX);
                            $received = $this->transactionRepository->getReceivedUserTransactionsAddress($contactResult[$type], PHP_INT_MAX);
                            $sentTransactions = array_merge($sentTransactions, $sent);
                            $receivedTransactions = array_merge($receivedTransactions, $received);
                        }
                    }
                    usort($sentTransactions, fn($a, $b) => strcmp($b['date'], $a['date']));
                    usort($receivedTransactions, fn($a, $b) => strcmp($b['date'], $a['date']));
                    $this->displayHistory($sentTransactions, 'sent', $displayLimit, $output);
                    $this->displayHistory($receivedTransactions, 'received', $displayLimit, $output);
                    return;
                }
            }
        }
        // If no address supplied, get all transactions
        $sentTransactions = $this->transactionRepository->getSentUserTransactions(PHP_INT_MAX);
        $receivedTransactions = $this->transactionRepository->getReceivedUserTransactions(PHP_INT_MAX);
        $this->displayHistory($sentTransactions, 'sent', $displayLimit, $output);
        $this->displayHistory($receivedTransactions, 'received', $displayLimit, $output);
    }

    /**
     * Helper to display transaction history (sent or received) in pretty print 'table' to user in the CLI
     *
     * @param array $transactions The formatted transaction data
     * @param string $direction received/send
     * @param int $displayLimit The limit of output displayed (0 = unlimited)
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayHistory(array $transactions, string $direction, $displayLimit, ?CliOutputManager $output = null){
        $output = $output ?? CliOutputManager::getInstance();

        $countResults = count($transactions);

        if ($output->isJsonMode()) {
            // Calculate effective display limit (0 = unlimited)
            $effectiveLimit = $displayLimit;
            if ($displayLimit === 0) {
                $effectiveLimit = $countResults;
            } elseif ($displayLimit > $countResults) {
                $effectiveLimit = $countResults;
            }

            // Build transaction data for JSON
            $txData = [];
            $count = 0;
            foreach ($transactions as $tx) {
                if ($displayLimit > 0 && $count >= $displayLimit) {
                    break;
                }
                $txData[] = [
                    'timestamp' => $tx['date'],
                    'type' => $tx['type'],
                    'direction' => $direction,
                    'counterparty_name' => $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($tx['counterparty']), $tx['counterparty']),
                    'counterparty_address' => $tx['counterparty'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency']
                ];
                $count++;
            }

            $output->transactionHistory($txData, $direction, $countResults, $effectiveLimit);
        } else {
            if(!$transactions){
                echo "No transaction history found for $direction transactions.\n";
                return;
            }

            echo "Transaction History for $direction transactions:\n";
            echo "-------------------------------------------\n";
            echo str_pad("Timestamp", 26, ' ') . " | " .
                str_pad("Direction", 9, ' ') . " | " .
                str_pad("Name (Address)", 82, ' ') . " | " .
                str_pad("Amount", 10, ' ') . " | " .
                str_pad("Currency", 10, ' ') . "\n";
            echo "-------------------------------------------\n";

            $countrows = 1;
            foreach ($transactions as $tx) {
                $contactName = $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($tx['counterparty']),$tx['counterparty']);
                echo str_pad($tx['date'], 26, ' ') . " | " .
                    str_pad($tx['type'], 9, ' ') . " | " .
                    str_pad($contactName . " (" . $this->generalUtility->truncateAddress($tx['counterparty'],82-(strlen($contactName)+2)) . ")", 82, ' ') . " | " .
                    str_pad($tx['amount'], 10, ' ') . " | " .
                    str_pad($tx['currency'], 10, ' ') . "\n" ;

                if($displayLimit > 0 && ($countrows >= $displayLimit)){
                    break;
                }
                $countrows += 1;
            }
            echo "-------------------------------------------\n";
            $effectiveLimit = $displayLimit;
            if($displayLimit === 0){
                $effectiveLimit = $countResults;
            } elseif($displayLimit > $countResults){
                $effectiveLimit = $countResults;
            }
            echo "Displaying " . $effectiveLimit .  " out of " . $countResults . " total transactions.\n";
        }
    }

    // =========================================================================
    // P2P APPROVAL MANAGEMENT (delegated to CliP2pApprovalService)
    // =========================================================================

    /**
     * Display P2P transactions awaiting approval
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayPendingP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->p2pApprovalService === null) {
            $output->error('P2P approval service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->p2pApprovalService->displayPendingP2p($argv, $output);
    }

    /**
     * Display route candidates for a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayP2pCandidates(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->p2pApprovalService === null) {
            $output->error('P2P approval service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->p2pApprovalService->displayP2pCandidates($argv, $output);
    }

    /**
     * Approve a P2P transaction and send it
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function approveP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->p2pApprovalService === null) {
            $output->error('P2P approval service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->p2pApprovalService->approveP2p($argv, $output);
    }

    /**
     * Reject a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function rejectP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->p2pApprovalService === null) {
            $output->error('P2P approval service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->p2pApprovalService->rejectP2p($argv, $output);
    }

    // =========================================================================
    // DEAD LETTER QUEUE (DLQ) MANAGEMENT (delegated to CliDlqService)
    // =========================================================================

    /**
     * List DLQ items
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayDlqItems(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->dlqService === null) {
            $output->error('DLQ service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->dlqService->displayDlqItems($argv, $output);
    }

    /**
     * Retry a DLQ item by ID
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function retryDlqItem(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->dlqService === null) {
            $output->error('DLQ service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->dlqService->retryDlqItem($argv, $output);
    }

    /**
     * Abandon a DLQ item by ID
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function abandonDlqItem(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();
        if ($this->dlqService === null) {
            $output->error('DLQ service not available', ErrorCodes::GENERAL_ERROR);
            return;
        }
        $this->dlqService->abandonDlqItem($argv, $output);
    }
}