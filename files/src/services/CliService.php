<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Utils\InputValidator;
use Eiou\Utils\SecureSeedphraseDisplay;
use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\CliServiceInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
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
 * SECTION INDEX:
 * - Properties & Constructor............. Line ~26
 * - Settings Management.................. Line ~95
 * - Settings & Help Display.............. Line ~391
 * - User Information..................... Line ~776
 * - Balance Operations................... Line ~886
 * - Transaction History.................. Line ~1055
 * - SSL Certificate Management........... Line ~1187
 * - P2P Approval Management.............. Line ~2310
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
     * @var ContactCreditRepository|null Contact credit repository (optional, setter-injected)
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * @var P2pRepository|null P2P repository (optional, setter-injected)
     */
    private ?P2pRepository $p2pRepository = null;

    /**
     * @var Rp2pCandidateRepository|null RP2P candidate repository (optional, setter-injected)
     */
    private ?Rp2pCandidateRepository $rp2pCandidateRepository = null;

    /**
     * @var Rp2pRepository|null RP2P repository (optional, setter-injected)
     */
    private ?Rp2pRepository $rp2pRepository = null;

    /**
     * @var P2pTransactionSenderInterface|null P2P transaction sender (optional, setter-injected)
     */
    private ?P2pTransactionSenderInterface $p2pTransactionSender = null;

    /**
     * @var P2pServiceInterface|null P2P service (optional, setter-injected)
     */
    private ?P2pServiceInterface $p2pService = null;

    /**
     * @var DeadLetterQueueRepository|null DLQ repository (optional, setter-injected)
     */
    private ?DeadLetterQueueRepository $dlqRepository = null;

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
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currentUser = $currentUser;
        $this->currencyUtility = $utilityContainer->getCurrencyUtility();
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->generalUtility = $utilityContainer->getGeneralUtility();
    }

    /**
     * Set the contact credit repository (optional dependency)
     */
    public function setContactCreditRepository(ContactCreditRepository $contactCreditRepository): void {
        $this->contactCreditRepository = $contactCreditRepository;
    }

    /**
     * Set the P2P repository (optional dependency)
     */
    public function setP2pRepository(P2pRepository $p2pRepository): void {
        $this->p2pRepository = $p2pRepository;
    }

    /**
     * Set P2P approval dependencies (optional, for CLI approve/reject commands)
     */
    public function setP2pApprovalDependencies(
        Rp2pRepository $rp2pRepository,
        Rp2pCandidateRepository $rp2pCandidateRepository,
        P2pTransactionSenderInterface $p2pTransactionSender,
        P2pServiceInterface $p2pService
    ): void {
        $this->rp2pRepository = $rp2pRepository;
        $this->rp2pCandidateRepository = $rp2pCandidateRepository;
        $this->p2pTransactionSender = $p2pTransactionSender;
        $this->p2pService = $p2pService;
    }

    /**
     * Set the DLQ repository (optional, for CLI dlq commands)
     */
    public function setDeadLetterQueueRepository(DeadLetterQueueRepository $dlqRepository): void {
        $this->dlqRepository = $dlqRepository;
    }

    // =========================================================================
    // SETTINGS MANAGEMENT
    // =========================================================================

    /**
     * Handler for (CLI) input changes to user settings
     *
     * @param array $argv The (CLI) input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function changeSettings(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        // Check if command line based or user input based
        if(isset($argv[2])){
            if(strtolower($argv[2]) === 'defaultfee'){
                $key = 'defaultFee';
                $validation = InputValidator::validateFeePercent($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('defaultFee', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'defaultcreditlimit'){
                $key = 'defaultCreditLimit';
                $validation = InputValidator::validateAmountFee($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('defaultCreditLimit', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'defaultcurrency'){
                $key = 'defaultCurrency';
                $validation = InputValidator::validateCurrency($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('defaultCurrency', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'minfee'){
                $key = 'minFee';
                $validation = InputValidator::validateAmountFee($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('minFee', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxfee'){
                $key = 'maxFee';
                $validation = InputValidator::validateFeePercent($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('maxFee', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxp2pLevel'){
                $key = 'maxP2pLevel';
                $validation = InputValidator::validateRequestLevel($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('maxP2pLevel', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'p2pexpiration'){
                $key = 'p2pExpiration';
                $validation = InputValidator::validatePositiveInteger($argv[3], Constants::P2P_MIN_EXPIRATION_SECONDS);
                if (!$validation['valid']) {
                    $output->validationError('p2pExpiration', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxoutput'){
                $key = 'maxOutput';
                if (!is_numeric($argv[3]) || intval($argv[3]) < 0) {
                    $output->validationError('maxOutput', 'Max output must be a non-negative integer (0 = unlimited)');
                    return;
                }
                $value = intval($argv[3]);
            } elseif(strtolower($argv[2]) === 'defaulttransportmode'){
                $key = 'defaultTransportMode';
                $value = strtolower($argv[3]);
            } elseif(strtolower($argv[2]) === 'autorefreshenabled'){
                $key = 'autoRefreshEnabled';
                $inputValue = strtolower($argv[3]);
                if ($inputValue === 'true' || $inputValue === '1' || $inputValue === 'on' || $inputValue === 'yes') {
                    $value = true;
                } elseif ($inputValue === 'false' || $inputValue === '0' || $inputValue === 'off' || $inputValue === 'no') {
                    $value = false;
                } else {
                    $output->validationError('autoRefreshEnabled', 'Value must be true/false, on/off, yes/no, or 1/0');
                    return;
                }
            } elseif(strtolower($argv[2]) === 'autoaccepttransaction'){
                $key = 'autoAcceptTransaction';
                $inputValue = strtolower($argv[3]);
                if ($inputValue === 'true' || $inputValue === '1' || $inputValue === 'on' || $inputValue === 'yes') {
                    $value = true;
                } elseif ($inputValue === 'false' || $inputValue === '0' || $inputValue === 'off' || $inputValue === 'no') {
                    $value = false;
                } else {
                    $output->validationError('autoAcceptTransaction', 'Value must be true/false, on/off, yes/no, or 1/0');
                    return;
                }
            } elseif(strtolower($argv[2]) === 'hostname'){
                $key = 'hostname';
                $validation = InputValidator::validateHostname($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('hostname', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'trustedproxies'){
                $key = 'trustedProxies';
                $validation = InputValidator::validateTrustedProxies($argv[3] ?? '');
                if (!$validation['valid']) {
                    $output->validationError('trustedProxies', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'name'){
                $key = 'name';
                if (!isset($argv[3]) || empty(trim($argv[3]))) {
                    $output->validationError('name', 'Display name cannot be empty');
                    return;
                }
                $value = trim($argv[3]);
            // Feature toggles
            } elseif(strtolower($argv[2]) === 'contactstatusenabled'){
                $key = 'contactStatusEnabled';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'contactstatussynconping'){
                $key = 'contactStatusSyncOnPing';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'autochaindroppropose'){
                $key = 'autoChainDropPropose';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'autochaindropaccept'){
                $key = 'autoChainDropAccept';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'apienabled'){
                $key = 'apiEnabled';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'apicorsallowedorigins'){
                $key = 'apiCorsAllowedOrigins';
                $value = trim($argv[3] ?? '');
            } elseif(strtolower($argv[2]) === 'ratelimitenabled'){
                $key = 'rateLimitEnabled';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            // Backup & logging
            } elseif(strtolower($argv[2]) === 'backupretentioncount'){
                $key = 'backupRetentionCount';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'backupcronhour'){
                $key = 'backupCronHour';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 0, 23, 'Backup hour');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'backupcronminute'){
                $key = 'backupCronMinute';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 0, 59, 'Backup minute');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'loglevel'){
                $key = 'logLevel';
                $validation = InputValidator::validateLogLevel($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'logmaxentries'){
                $key = 'logMaxEntries';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 10);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            // Data retention
            } elseif(strtolower($argv[2]) === 'cleanupdeliveryretentiondays'){
                $key = 'cleanupDeliveryRetentionDays';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'cleanupdlqretentiondays'){
                $key = 'cleanupDlqRetentionDays';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'cleanupheldtxretentiondays'){
                $key = 'cleanupHeldTxRetentionDays';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'cleanuprp2pretentiondays'){
                $key = 'cleanupRp2pRetentionDays';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'cleanupmetricsretentiondays'){
                $key = 'cleanupMetricsRetentionDays';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            // Rate limiting
            } elseif(strtolower($argv[2]) === 'p2pratelimitperminute'){
                $key = 'p2pRateLimitPerMinute';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'ratelimitmaxattempts'){
                $key = 'rateLimitMaxAttempts';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'ratelimitwindowseconds'){
                $key = 'rateLimitWindowSeconds';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'ratelimitblockseconds'){
                $key = 'rateLimitBlockSeconds';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            // Network
            } elseif(strtolower($argv[2]) === 'httptransporttimeoutseconds'){
                $key = 'httpTransportTimeoutSeconds';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 5, 120, 'HTTP timeout');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'tortransporttimeoutseconds'){
                $key = 'torTransportTimeoutSeconds';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 10, 300, 'Tor timeout');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            // Display
            } elseif(strtolower($argv[2]) === 'displaydateformat'){
                $key = 'displayDateFormat';
                $validation = InputValidator::validateDateFormat($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'displaycurrencydecimals'){
                $key = 'displayCurrencyDecimals';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 0, 8, 'Currency decimals');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'displayrecenttransactionslimit'){
                $key = 'displayRecentTransactionsLimit';
                $validation = InputValidator::validatePositiveInteger($argv[3] ?? '', 1);
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } else{
                $output->error('Setting provided does not exist. No changes made.', ErrorCodes::INVALID_SETTING, 400);
                return;
            }
        } else{
            // Interactive mode - not supported in JSON mode
            if ($output->isJsonMode()) {
                $output->error('Interactive mode not supported with --json flag. Please provide setting name and value.', 'INTERACTIVE_NOT_SUPPORTED', 400);
                return;
            }

            // Display current settings
            $this->displayCurrentSettings($output);

            // Prompt user for which setting they want to change
            echo "Select the setting you want to change:\n";
            echo "\t1. Default currency\n";
            echo "\t2. Minimum fee amount\n";
            echo "\t3. Default fee percentage\n";
            echo "\t4. Maximum fee percentage\n";
            echo "\t5. Default credit limit\n";
            echo "\t6. Maximum peer to peer Level\n";
            echo "\t7. Default peer to peer Expiration\n";
            echo "\t8. Maximum lines of balance/transaction output\n";
            echo "\t9. Default transport type\n";
            echo "\t10. Hostname\n";
            echo "\t11. Auto-refresh transactions\n";
            echo "\t12. Auto-backup database\n";
            echo "\t13. Trusted proxy IPs\n";
            echo "\t14. Auto-accept P2P transactions\n";
            echo "\t0. Cancel\n";

            // Read user input
            $setting_choice = trim(fgets(STDIN));

            switch($setting_choice) {
                case '1':
                    echo "Enter new default currency (e.g., USD): ";
                    $key = 'defaultCurrency';
                    $validation = InputValidator::validateCurrency(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '2':
                    echo "Enter new minimum fee amount: ";
                    $key = 'minFee';
                    $validation = InputValidator::validateAmountFee(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '3':
                    echo "Enter new default fee percentage: ";
                    $key = 'defaultFee';
                    $validation = InputValidator::validateFeePercent(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '4':
                    echo "Enter new maximum fee percentage: ";
                    $key = 'maxFee';
                    $validation = InputValidator::validateFeePercent(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;


                case '5':
                    echo "Enter new default credit limit: ";
                    $key = 'defaultCreditLimit';
                    $validation = InputValidator::validateAmountFee(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;


                case '6':
                    echo "Enter new maximum peer to peer Level: ";
                    $key = 'maxP2pLevel';
                    $validation = InputValidator::validateRequestLevel(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '7':
                    echo "Enter new peer to peer expiration (in seconds, minimum " . Constants::P2P_MIN_EXPIRATION_SECONDS . "): ";
                    $key = 'p2pExpiration';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), Constants::P2P_MIN_EXPIRATION_SECONDS);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '8':
                    echo "Enter new maximum of balance/transaction output lines to display (0 = unlimited): ";
                    $key = 'maxOutput';
                    $read = trim(fgets(STDIN));
                    if (!is_numeric($read) || intval($read) < 0) {
                        echo "Error: Max output must be a non-negative integer (0 = unlimited)\n";
                        return;
                    }
                    $value = intval($read);
                    break;

                case '9':
                    echo "Enter new default transport type (e.g. http, https, tor): ";
                    $key = 'defaultTransportMode';
                    $value = strtolower(trim(fgets(STDIN)));
                    break;

                case '10':
                    echo "Enter new hostname (e.g. http://httpA): ";
                    $key = 'hostname';
                    $validation = InputValidator::validateHostname(strtolower(trim(fgets(STDIN))));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '11':
                    echo "Enable auto-refresh for pending transactions? (yes/no): ";
                    $key = 'autoRefreshEnabled';
                    $inputValue = strtolower(trim(fgets(STDIN)));
                    if ($inputValue === 'yes' || $inputValue === 'y' || $inputValue === 'true' || $inputValue === '1' || $inputValue === 'on') {
                        $value = true;
                    } elseif ($inputValue === 'no' || $inputValue === 'n' || $inputValue === 'false' || $inputValue === '0' || $inputValue === 'off') {
                        $value = false;
                    } else {
                        echo "Error: Please enter yes or no\n";
                        return;
                    }
                    break;

                case '12':
                    echo "Enable automatic daily database backups? (yes/no): ";
                    $key = 'autoBackupEnabled';
                    $inputValue = strtolower(trim(fgets(STDIN)));
                    if ($inputValue === 'yes' || $inputValue === 'y' || $inputValue === 'true' || $inputValue === '1' || $inputValue === 'on') {
                        $value = true;
                    } elseif ($inputValue === 'no' || $inputValue === 'n' || $inputValue === 'false' || $inputValue === '0' || $inputValue === 'off') {
                        $value = false;
                    } else {
                        echo "Error: Please enter yes or no\n";
                        return;
                    }
                    break;

                case '13':
                    echo "Enter trusted proxy IPs (comma-separated, empty to clear): ";
                    $key = 'trustedProxies';
                    $validation = InputValidator::validateTrustedProxies(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '14':
                    echo "Auto-accept P2P transactions when route found? (yes/no): ";
                    $key = 'autoAcceptTransaction';
                    $inputValue = strtolower(trim(fgets(STDIN)));
                    if ($inputValue === 'yes' || $inputValue === 'y' || $inputValue === 'true' || $inputValue === '1' || $inputValue === 'on') {
                        $value = true;
                    } elseif ($inputValue === 'no' || $inputValue === 'n' || $inputValue === 'false' || $inputValue === '0' || $inputValue === 'off') {
                        $value = false;
                    } else {
                        echo "Error: Please enter yes or no\n";
                        return;
                    }
                    break;

                case '0':
                    echo "Setting change cancelled.\n";
                    return;

                default:
                    echo "Invalid selection. No changes made.\n";
                    return;
            }
        }
        // Save changes to config file
        if($key == 'hostname'){
            $configFile = 'userconfig.json';

            // Derive hostname_secure from hostname
            // Convert http:// to https:// or normalize if already https://
            if (strpos($value, 'http://') === 0) {
                $hostnameSecure = 'https://' . substr($value, 7);
            } elseif (strpos($value, 'https://') === 0) {
                $hostnameSecure = $value;
                $value = 'http://' . substr($value, 8); // hostname should be http
            } else {
                // No protocol prefix, assume http for hostname
                $hostnameSecure = 'https://' . $value;
                $value = 'http://' . $value;
            }
        } elseif($key == 'name'){
            $configFile = 'userconfig.json';
        } else{
            $configFile = 'defaultconfig.json';
        }

        $config_content = json_decode(file_get_contents('/etc/eiou/config/' . $configFile),true);
        $config_content[$key] = $value;

        // Also save hostname_secure when hostname is updated
        if ($key == 'hostname') {
            $config_content['hostname_secure'] = $hostnameSecure;
        }

        file_put_contents('/etc/eiou/config/'. $configFile, json_encode($config_content,true), LOCK_EX);

        // Regenerate SSL certificate when hostname changes
        // The certificate CN and SANs need to match the new hostname
        if ($key == 'hostname') {
            $this->regenerateSslCertificate($value, $output);
        }

        $output->success('Setting updated successfully.', [
            'setting' => $key,
            'value' => $value,
            'config_file' => $configFile,
            'hostname_secure' => $key == 'hostname' ? $hostnameSecure : null
        ]);
    }

    // =========================================================================
    // SETTINGS & HELP DISPLAY
    // =========================================================================

    /**
     * Display current settings of user in the CLI
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayCurrentSettings(?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        $settings = [
            'default_currency' => $this->currentUser->getDefaultCurrency(),
            'minimum_fee_amount' => $this->currentUser->getMinimumFee(),
            'minimum_fee_currency' => $this->currentUser->getDefaultCurrency(),
            'default_fee_percent' => $this->currentUser->getDefaultFee(),
            'maximum_fee_percent' => $this->currentUser->getMaxFee(),
            'default_credit_limit' => $this->currentUser->getDefaultCreditLimit(),
            'max_p2p_level' => $this->currentUser->getMaxP2pLevel(),
            'p2p_expiration_seconds' => $this->currentUser->getP2pExpirationTime(),
            'max_output_lines' => $this->currentUser->getMaxOutput(),
            'default_transport_mode' => $this->currentUser->getDefaultTransportMode(),
            'hostname' => $this->currentUser->getHttpAddress(),
            'hostname_secure' => $this->currentUser->getHttpsAddress(),
            'auto_refresh_enabled' => $this->currentUser->getAutoRefreshEnabled(),
            'auto_backup_enabled' => $this->currentUser->getAutoBackupEnabled(),
            'auto_accept_transaction' => $this->currentUser->getAutoAcceptTransaction(),
            'trusted_proxies' => $this->currentUser->getTrustedProxies(),
            // Feature toggles
            'contact_status_enabled' => $this->currentUser->getContactStatusEnabled(),
            'contact_status_sync_on_ping' => $this->currentUser->getContactStatusSyncOnPing(),
            'auto_chain_drop_propose' => $this->currentUser->getAutoChainDropPropose(),
            'auto_chain_drop_accept' => $this->currentUser->getAutoChainDropAccept(),
            'api_enabled' => $this->currentUser->getApiEnabled(),
            'api_cors_allowed_origins' => $this->currentUser->getApiCorsAllowedOrigins(),
            'rate_limit_enabled' => $this->currentUser->getRateLimitEnabled(),
            // Backup & logging
            'backup_retention_count' => $this->currentUser->getBackupRetentionCount(),
            'backup_cron_hour' => $this->currentUser->getBackupCronHour(),
            'backup_cron_minute' => $this->currentUser->getBackupCronMinute(),
            'log_level' => $this->currentUser->getLogLevel(),
            'log_max_entries' => $this->currentUser->getLogMaxEntries(),
            // Data retention
            'cleanup_delivery_retention_days' => $this->currentUser->getCleanupDeliveryRetentionDays(),
            'cleanup_dlq_retention_days' => $this->currentUser->getCleanupDlqRetentionDays(),
            'cleanup_held_tx_retention_days' => $this->currentUser->getCleanupHeldTxRetentionDays(),
            'cleanup_rp2p_retention_days' => $this->currentUser->getCleanupRp2pRetentionDays(),
            'cleanup_metrics_retention_days' => $this->currentUser->getCleanupMetricsRetentionDays(),
            // Rate limiting
            'p2p_rate_limit_per_minute' => $this->currentUser->getP2pRateLimitPerMinute(),
            'rate_limit_max_attempts' => $this->currentUser->getRateLimitMaxAttempts(),
            'rate_limit_window_seconds' => $this->currentUser->getRateLimitWindowSeconds(),
            'rate_limit_block_seconds' => $this->currentUser->getRateLimitBlockSeconds(),
            // Network
            'http_transport_timeout_seconds' => $this->currentUser->getHttpTransportTimeoutSeconds(),
            'tor_transport_timeout_seconds' => $this->currentUser->getTorTransportTimeoutSeconds(),
            // Display
            'display_date_format' => $this->currentUser->getDisplayDateFormat(),
            'display_currency_decimals' => $this->currentUser->getDisplayCurrencyDecimals(),
            'display_recent_transactions_limit' => $this->currentUser->getDisplayRecentTransactionsLimit(),
        ];

        if ($output->isJsonMode()) {
            $output->settings($settings);
        } else {
            echo "Current Settings:\n";
            echo "\n  Transaction Settings:\n";
            echo "\tDefault currency: " . $settings['default_currency'] . "\n";
            echo "\tMinimum fee amount: " . $settings['minimum_fee_amount'] . " " . $settings['minimum_fee_currency'] ."\n";
            echo "\tDefault fee percent: " . $settings['default_fee_percent'] ."%\n";
            echo "\tMaximum fee percent: " . $settings['maximum_fee_percent'] . "%\n";
            echo "\tDefault credit limit: " . $settings['default_credit_limit'] ."\n";
            echo "\n  P2P & Network:\n";
            echo "\tMaximum peer to peer Level: " .  $settings['max_p2p_level'] . "\n";
            echo "\tDefault peer to peer Expiration: " .  $settings['p2p_expiration_seconds'] . " seconds\n";
            echo "\tDefault transport mode: " . $settings['default_transport_mode'] . "\n";
            echo "\tHTTP transport timeout: " . $settings['http_transport_timeout_seconds'] . "s\n";
            echo "\tTor transport timeout: " . $settings['tor_transport_timeout_seconds'] . "s\n";
            if ($settings['hostname']) echo "\tHostname: " . $settings['hostname'] . "\n";
            if ($settings['hostname_secure']) echo "\tHostname (secure): " . $settings['hostname_secure'] . "\n";
            echo "\tAuto-refresh transactions: " . ($settings['auto_refresh_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto-backup database: " . ($settings['auto_backup_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto-accept P2P transactions: " . ($settings['auto_accept_transaction'] ? 'enabled' : 'disabled') . "\n";
            echo "\tTrusted proxies: " . ($settings['trusted_proxies'] ?: '(none)') . "\n";
            echo "\n  Feature Toggles:\n";
            echo "\tContact status pinging: " . ($settings['contact_status_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tContact status sync on ping: " . ($settings['contact_status_sync_on_ping'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto chain drop propose: " . ($settings['auto_chain_drop_propose'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto chain drop accept: " . ($settings['auto_chain_drop_accept'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAPI enabled: " . ($settings['api_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAPI CORS origins: " . ($settings['api_cors_allowed_origins'] ?: '(none)') . "\n";
            echo "\tRate limiting: " . ($settings['rate_limit_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto-refresh transactions: " . ($settings['auto_refresh_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\n  Backup & Logging:\n";
            echo "\tAuto-backup database: " . ($settings['auto_backup_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tBackup retention count: " . $settings['backup_retention_count'] . "\n";
            echo "\tBackup schedule: " . sprintf("%02d:%02d", $settings['backup_cron_hour'], $settings['backup_cron_minute']) . "\n";
            echo "\tLog level: " . $settings['log_level'] . "\n";
            echo "\tLog max entries: " . $settings['log_max_entries'] . "\n";
            echo "\n  Data Retention:\n";
            echo "\tDelivery retention: " . $settings['cleanup_delivery_retention_days'] . " days\n";
            echo "\tDLQ retention: " . $settings['cleanup_dlq_retention_days'] . " days\n";
            echo "\tHeld TX retention: " . $settings['cleanup_held_tx_retention_days'] . " days\n";
            echo "\tRP2P retention: " . $settings['cleanup_rp2p_retention_days'] . " days\n";
            echo "\tMetrics retention: " . $settings['cleanup_metrics_retention_days'] . " days\n";
            echo "\n  Rate Limiting:\n";
            echo "\tP2P rate limit: " . $settings['p2p_rate_limit_per_minute'] . "/min\n";
            echo "\tMax attempts: " . $settings['rate_limit_max_attempts'] . "\n";
            echo "\tWindow: " . $settings['rate_limit_window_seconds'] . "s\n";
            echo "\tBlock duration: " . $settings['rate_limit_block_seconds'] . "s\n";
            echo "\n  Display:\n";
            echo "\tDefault maximum lines of balance output: " .  ($settings['max_output_lines'] === 0 ? 'unlimited' : $settings['max_output_lines']) . "\n";
            echo "\tDate format: " . $settings['display_date_format'] . "\n";
            echo "\tCurrency decimals: " . $settings['display_currency_decimals'] . "\n";
            echo "\tRecent transactions limit: " . $settings['display_recent_transactions_limit'] . "\n";
        }
    }

    /**
     * Display available commands to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayHelp(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        // Define all commands with their metadata
        $commands = [
            'info' => [
                'description' => 'Display wallet information including addresses, public key, fee earnings, and available credit',
                'usage' => 'info ([detail]) ([--show-auth])',
                'arguments' => [
                    'detail' => ['type' => 'optional', 'description' => 'Show detailed balance information with sent/received breakdown per currency'],
                    '--show-auth' => ['type' => 'optional', 'description' => 'Securely display auth code via temp file (never shown in logs)']
                ],
                'examples' => [
                    'info' => 'Basic wallet info (auth code redacted)',
                    'info detail' => 'Detailed info with balance breakdown',
                    'info --show-auth' => 'Show authentication code securely via temp file',
                    'info --show-auth --json' => 'JSON output with auth code file path'
                ],
                'note' => 'The auth code is never exposed in command output to prevent leaks via Docker logs, shell history, or screen sharing. With --show-auth, the code is stored in a memory-only temp file (/dev/shm/) that auto-deletes after 5 minutes.'
            ],
            'add' => [
                'description' => 'Add a new contact or accept an incoming contact request',
                'usage' => 'add [address] [name] [fee] [credit] [currency]',
                'arguments' => [
                    'address' => ['type' => 'required', 'description' => 'Contact address (HTTP, HTTPS, or Tor .onion)'],
                    'name' => ['type' => 'required', 'description' => 'Contact name (use quotes for multi-word names: "John Doe")'],
                    'fee' => ['type' => 'required', 'description' => 'Fee percentage for relaying transactions through you (e.g., 1.0 = 1%)'],
                    'credit' => ['type' => 'required', 'description' => 'Credit limit you extend to this contact'],
                    'currency' => ['type' => 'required', 'description' => 'Currency code (e.g., USD)']
                ],
                'examples' => [
                    'add http://bob:8080 Bob 1.0 100 USD' => 'Add a new contact',
                    'add http://bob:8080 "Jane Doe" 1.0 100 USD' => 'Add with a multi-word name',
                    'add abc123...onion Alice 0.5 500 USD' => 'Add via Tor address',
                    'add http://charlie:8080 Charlie 1 200 USD --json' => 'JSON output'
                ],
                'note' => 'Creates a pending contact request that the recipient must accept. To accept an incoming request, use add with the sender\'s address. Rate limited: 20 additions per minute.'
            ],
            'search' => [
                'description' => 'Search for contacts by name (partial match) or list all contacts',
                'usage' => 'search ([name])',
                'arguments' => [
                    'name' => ['type' => 'optional', 'description' => 'Search term (partial name match). Omit to list all contacts.']
                ],
                'examples' => [
                    'search bob' => 'Search for contacts containing "bob"',
                    'search' => 'List all contacts',
                    'search alice --json' => 'JSON output'
                ],
                'note' => 'Shows name, address(es), status, fee percentage, credit limit, currency, your available credit (from pong), and their available credit (calculated).'
            ],
            'viewcontact' => [
                'description' => 'View detailed information about a specific contact',
                'usage' => 'viewcontact [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or display name']
                ],
                'examples' => [
                    'viewcontact Bob' => 'View by name',
                    'viewcontact http://bob:8080' => 'View by address',
                    'viewcontact --json Bob' => 'JSON output'
                ],
                'note' => 'Shows name, status, addresses, public key, balance (received/sent/net), fee percentage, credit limit, your available credit with them (from pong, ~5 min refresh), and their available credit with you (calculated).'
            ],
            'update' => [
                'description' => 'Update contact settings (name, fee, credit limit, or all at once)',
                'usage' => 'update [address/name] [all/name/fee/credit] ([values...])',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or display name'],
                    'field' => ['type' => 'required', 'description' => 'Field to update: all, name, fee, or credit'],
                    'values' => ['type' => 'optional', 'description' => 'New value(s) for the field(s)']
                ],
                'examples' => [
                    'update Bob name Robert' => 'Rename contact',
                    'update Bob fee 1.5' => 'Change fee percentage',
                    'update Bob credit 500' => 'Change credit limit',
                    'update Bob all NewName 2.0 1000' => 'Update all fields at once',
                    'update http://bob:8080 fee 2.0 --json' => 'Update by address with JSON output'
                ],
                'note' => 'Changes are local only — the contact is not notified. Fee percentage controls what you charge for relaying transactions through you for this contact. Credit limit is the maximum amount you allow this contact to owe you.'
            ],
            'block' => [
                'description' => 'Block a contact from sending transactions to you',
                'usage' => 'block [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to block']
                ],
                'examples' => [
                    'block SpamUser' => 'Block by name',
                    'block http://badactor:8080' => 'Block by address',
                    'block http://badactor:8080 --json' => 'JSON output'
                ],
                'note' => 'Blocked contacts cannot send you transactions or P2P requests. Incoming transactions from blocked contacts are rejected.'
            ],
            'unblock' => [
                'description' => 'Unblock a previously blocked contact',
                'usage' => 'unblock [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to unblock']
                ],
                'examples' => [
                    'unblock SpamUser' => 'Unblock by name',
                    'unblock http://user:8080 --json' => 'JSON output'
                ],
                'note' => 'Restores the contact to their previous status (accepted or pending). They can resume sending transactions and P2P requests.'
            ],
            'delete' => [
                'description' => 'Delete a contact permanently',
                'usage' => 'delete [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to delete']
                ],
                'examples' => [
                    'delete OldContact' => 'Delete by name',
                    'delete http://old:8080' => 'Delete by address',
                    'delete OldContact --json' => 'JSON output'
                ],
                'note' => 'Removes the contact and their addresses. Transaction history and balances are preserved. The contact can re-add you, which will appear as a new pending request.'
            ],
            'send' => [
                'description' => 'Send an eIOU transaction to a contact (direct or P2P relayed)',
                'usage' => 'send [address/"name"] [amount] [currency] (--best)',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Recipient address or name (use quotes for multi-word names: "John Doe")'],
                    'amount' => ['type' => 'required', 'description' => 'Amount to send (positive number)'],
                    'currency' => ['type' => 'required', 'description' => 'Currency code (e.g., USD)'],
                    '--best' => ['type' => 'optional', 'description' => '[EXPERIMENTAL] Collect all route responses and select lowest fee. Slower but cheaper. Ignored for Tor recipients.']
                ],
                'examples' => [
                    'send Bob 50 USD' => 'Send by contact name (fast mode)',
                    'send http://bob:8080 100 USD' => 'Send by address',
                    'send Bob 50 USD --best' => 'Best-fee routing (experimental)',
                    'send Alice 25.50 USD --json' => 'JSON output'
                ],
                'note' => 'Direct contacts receive the transaction immediately. Non-contacts are reached via P2P relay through intermediaries. Default fast mode uses first available route. Chain integrity is verified before every send; gaps trigger auto-sync and chain drop proposal if needed. Rate limited: 30 per minute.'
            ],
            'viewbalances' => [
                'description' => 'View eIOU balances with all contacts or a specific contact',
                'usage' => 'viewbalances ([address/name])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name. Omit to view all balances.']
                ],
                'examples' => [
                    'viewbalances' => 'View all balances',
                    'viewbalances Bob' => 'View balance with specific contact',
                    'viewbalances --json' => 'JSON output'
                ],
                'note' => 'Shows received, sent, and net balance per contact per currency. Balances are calculated from verified transaction chains. Use "all" as name to explicitly show all contacts.'
            ],
            'history' => [
                'description' => 'View transaction history with all contacts or a specific contact',
                'usage' => 'history ([address/name]) ([limit])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name'],
                    'limit' => ['type' => 'optional', 'description' => 'Maximum transactions to display (0 = unlimited)']
                ],
                'examples' => [
                    'history' => 'View all transaction history',
                    'history Bob' => 'View history with specific contact',
                    'history Bob 0' => 'View all history with Bob (no limit)',
                    'history --json' => 'JSON output'
                ],
                'note' => 'Shows transaction ID, direction (sent/received), amount, currency, timestamp, and contact name. Default limit is controlled by the maxOutput setting. Transactions are shown newest first.'
            ],
            'pending' => [
                'description' => 'View pending contact requests (incoming and outgoing)',
                'usage' => 'pending',
                'arguments' => [],
                'examples' => [
                    'pending' => 'View all pending requests',
                    'pending --json' => 'JSON output'
                ],
                'note' => 'Shows incoming requests (from others awaiting your acceptance) and outgoing requests (your requests awaiting others). After a wallet restore, prior contacts that ping your node appear here as incoming requests. Contacts with transaction history are prior contacts that can be re-accepted with the add command.'
            ],
            'p2p' => [
                'description' => 'Manage P2P transactions awaiting approval',
                'usage' => 'p2p ([subcommand]) ([args...])',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'Subcommand: list (default), candidates, approve, reject'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the subcommand']
                ],
                'actions' => [
                    'list' => [
                        'usage' => 'p2p',
                        'description' => 'List all P2P transactions awaiting approval'
                    ],
                    'candidates' => [
                        'usage' => 'p2p candidates <hash>',
                        'description' => 'Show route candidates for a transaction'
                    ],
                    'approve' => [
                        'usage' => 'p2p approve <hash> [index]',
                        'description' => 'Approve and send a P2P transaction. Index (1-based) selects candidate in best-fee mode.'
                    ],
                    'reject' => [
                        'usage' => 'p2p reject <hash>',
                        'description' => 'Reject and cancel a P2P transaction'
                    ]
                ],
                'examples' => [
                    'p2p' => 'List pending P2P transactions',
                    'p2p candidates abc123' => 'View route candidates for hash abc123',
                    'p2p approve abc123' => 'Approve single-route P2P (fast mode)',
                    'p2p approve abc123 2' => 'Approve using candidate #2 (best-fee mode)',
                    'p2p reject abc123' => 'Reject and cancel P2P transaction',
                    'p2p --json' => 'JSON output'
                ],
                'note' => 'Used when autoAcceptTransaction is disabled. P2P transactions wait in awaiting_approval status until manually approved or rejected via this command.'
            ],
            'overview' => [
                'description' => 'Display wallet dashboard with balances and recent transactions',
                'usage' => 'overview ([limit])',
                'arguments' => [
                    'limit' => ['type' => 'optional', 'description' => 'Number of recent transactions to show (default: 5)']
                ],
                'examples' => [
                    'overview' => 'Default dashboard (5 recent transactions)',
                    'overview 10' => 'Show 10 recent transactions',
                    'overview --json' => 'JSON output'
                ],
                'note' => 'Combines balance summary across all contacts with recent transaction activity. Useful as a quick status check.'
            ],
            'help' => [
                'description' => 'Display help information for all commands or a specific command',
                'usage' => 'help ([command])',
                'arguments' => [
                    'command' => ['type' => 'optional', 'description' => 'Specific command to get detailed help for']
                ],
                'examples' => [
                    'help' => 'List all available commands',
                    'help send' => 'Detailed help for the send command',
                    'help --json' => 'JSON format help'
                ]
            ],
            'viewsettings' => [
                'description' => 'Display current wallet settings',
                'usage' => 'viewsettings',
                'arguments' => [],
                'examples' => [
                    'viewsettings' => 'View all settings',
                    'viewsettings --json' => 'JSON output'
                ],
                'note' => 'Shows default currency, fee settings (min/default/max), credit limit, P2P routing level and expiration, max output lines, transport mode, hostname, auto-refresh, and auto-backup status.'
            ],
            'changesettings' => [
                'description' => 'Change wallet settings (interactive or direct)',
                'usage' => 'changesettings ([setting] [value])',
                'arguments' => [
                    'setting' => ['type' => 'optional', 'description' => 'Setting name to change (interactive mode if omitted)'],
                    'value' => ['type' => 'optional', 'description' => 'New value for the setting']
                ],
                'available_settings' => [
                    'defaultFee' => 'Default fee percentage for transactions (e.g., 1.0)',
                    'defaultCreditLimit' => 'Default credit limit for new contacts (e.g., 100)',
                    'defaultCurrency' => 'Default currency code (e.g., USD)',
                    'minFee' => 'Minimum fee amount (e.g., 0.01)',
                    'maxFee' => 'Maximum fee percentage (e.g., 5.0)',
                    'maxP2pLevel' => 'Maximum peer-to-peer routing hops (e.g., 3)',
                    'p2pExpiration' => 'Peer-to-peer request expiration time in seconds (e.g., 300)',
                    'maxOutput' => 'Maximum lines of output to display (0 = unlimited)',
                    'defaultTransportMode' => 'Default transport type: http, https, or tor',
                    'autoRefreshEnabled' => 'Enable auto-refresh for pending transactions (true/false)',
                    'autoBackupEnabled' => 'Enable automatic daily database backups (true/false)',
                    'hostname' => 'Node hostname (e.g., http://alice). Automatically derives HTTPS version and regenerates SSL cert',
                    'name' => 'Display name for this node (shown in local UI)'
                ],
                'examples' => [
                    'changesettings' => 'Interactive mode (prompts for setting)',
                    'changesettings defaultCurrency USD' => 'Change default currency',
                    'changesettings maxP2pLevel 5' => 'Change max P2P routing hops',
                    'changesettings autoRefreshEnabled true' => 'Enable auto-refresh',
                    'changesettings defaultFee 1.5 --json' => 'JSON output'
                ]
            ],
            'generate' => [
                'description' => 'Wallet generation and restoration (handled during container startup)',
                'usage' => 'generate',
                'arguments' => [],
                'examples' => [],
                'note' => 'Wallet creation is handled automatically by startup.sh during container initialization via Docker environment variables (QUICKSTART, EIOU_HOST, EIOU_NAME, RESTORE, RESTORE_FILE). This command cannot be used after the wallet has been created.'
            ],
            'sync' => [
                'description' => 'Synchronize data with contacts (contacts, transactions, balances)',
                'usage' => 'sync ([type])',
                'arguments' => [
                    'type' => ['type' => 'optional', 'description' => 'Sync type: contacts, transactions, or balances. Omit to sync all.']
                ],
                'examples' => [
                    'sync' => 'Sync all (contacts, transactions, and balances)',
                    'sync contacts' => 'Sync only contacts',
                    'sync transactions' => 'Sync only transactions (includes backup recovery)',
                    'sync balances' => 'Recalculate balances from transaction history'
                ],
                'note' => 'Transaction sync verifies chain integrity locally for each contact. If gaps are found, backup recovery is attempted on both sides. If gaps remain after recovery, the output reports the gap count and recommends using chaindrop to resolve.'
            ],
            'out' => [
                'description' => 'Process outgoing message queue (pending transactions)',
                'usage' => 'out',
                'arguments' => [],
                'examples' => [
                    'out' => 'Process all pending outgoing messages'
                ],
                'note' => 'Requires EIOU_TEST_MODE=true. Manually triggers the outgoing message processor. Used for testing and debugging.'
            ],
            'in' => [
                'description' => 'Process incoming/held transactions',
                'usage' => 'in',
                'arguments' => [],
                'examples' => [
                    'in' => 'Process all held incoming transactions'
                ],
                'note' => 'Requires EIOU_TEST_MODE=true. Processes held transactions that may have completed sync. Used for testing and debugging.'
            ],
            'ping' => [
                'description' => 'Check if a contact is online, verify chain validity, and retrieve available credit',
                'usage' => 'ping [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to ping']
                ],
                'examples' => [
                    'ping Bob' => 'Ping by name',
                    'ping http://bob:8080' => 'Ping by address',
                    'ping --json Alice' => 'JSON output'
                ],
                'note' => 'Returns online status (online, partial, or offline). Verifies local chain integrity and compares chain heads with the remote contact. If a mismatch or gap is detected, auto-triggers sync (with backup recovery). If sync fails, auto-proposes a chain drop. The pong response also includes the available credit the contact extends to you (~5 min auto-refresh).'
            ],
            'apikey' => [
                'description' => 'Manage API keys for external API access',
                'usage' => 'apikey [action] ([args...])',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: create, list, delete, disable, enable, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'create' => [
                        'usage' => 'apikey create <name> [permissions]',
                        'description' => 'Create a new API key',
                        'arguments' => [
                            'name' => ['type' => 'required', 'description' => 'Name for the API key'],
                            'permissions' => ['type' => 'optional', 'description' => 'Comma-separated permissions (default: wallet:read,contacts:read)']
                        ]
                    ],
                    'list' => [
                        'usage' => 'apikey list',
                        'description' => 'List all API keys'
                    ],
                    'delete' => [
                        'usage' => 'apikey delete <key_id>',
                        'description' => 'Delete an API key permanently',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to delete']
                        ]
                    ],
                    'disable' => [
                        'usage' => 'apikey disable <key_id>',
                        'description' => 'Disable an API key (can be re-enabled)',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to disable']
                        ]
                    ],
                    'enable' => [
                        'usage' => 'apikey enable <key_id>',
                        'description' => 'Enable a disabled API key',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to enable']
                        ]
                    ],
                    'help' => [
                        'usage' => 'apikey help',
                        'description' => 'Show detailed API key help'
                    ]
                ],
                'examples' => [
                    'apikey help' => 'Show detailed API key help',
                    'apikey create "My App"' => 'Create new API key with default permissions',
                    'apikey create "My App" wallet:read,contacts:read' => 'Create key with specific permissions',
                    'apikey list' => 'List all API keys',
                    'apikey delete <key_id>' => 'Delete an API key permanently',
                    'apikey disable <key_id>' => 'Disable an API key',
                    'apikey enable <key_id>' => 'Enable a disabled API key'
                ],
                'permissions' => [
                    'wallet:read' => 'Read wallet balance, info, and transactions',
                    'wallet:send' => 'Send transactions, manage chain drops',
                    'contacts:read' => 'List, view, search, and ping contacts',
                    'contacts:write' => 'Add, update, delete, block/unblock contacts',
                    'system:read' => 'View system status, metrics, and settings',
                    'backup:read' => 'Read backup status/list, verify backups',
                    'backup:write' => 'Create, restore, delete, enable/disable backups',
                    'admin' => 'Full administrative access (settings, sync, shutdown, keys)',
                    'all' => 'All permissions (same as admin)'
                ],
                'api_usage' => [
                    'base_url' => 'http://your-node/api/v1/...',
                    'required_headers' => [
                        'X-API-Key' => '<key_id>',
                        'X-API-Timestamp' => '<unix_timestamp>',
                        'X-API-Signature' => '<hmac>'
                    ],
                    'signature_format' => 'HMAC-SHA256(secret, METHOD + "\\n" + PATH + "\\n" + TIMESTAMP + "\\n" + BODY)',
                    'example_endpoints' => [
                        'GET /api/v1/wallet/balance' => 'Get wallet balances',
                        'GET /api/v1/wallet/info' => 'Wallet public key, addresses, fee earnings',
                        'GET /api/v1/wallet/overview' => 'Wallet overview (balance + recent transactions)',
                        'POST /api/v1/wallet/send' => 'Send transaction',
                        'GET /api/v1/wallet/transactions' => 'Transaction history',
                        'GET /api/v1/contacts' => 'List contacts',
                        'POST /api/v1/contacts' => 'Add contact',
                        'GET /api/v1/contacts/pending' => 'Pending contact requests',
                        'GET /api/v1/contacts/search?q=' => 'Search contacts by name',
                        'GET /api/v1/contacts/:address' => 'Get contact details',
                        'PUT /api/v1/contacts/:address' => 'Update contact',
                        'DELETE /api/v1/contacts/:address' => 'Delete contact',
                        'POST /api/v1/contacts/block/:address' => 'Block contact',
                        'POST /api/v1/contacts/unblock/:address' => 'Unblock contact',
                        'POST /api/v1/contacts/ping/:address' => 'Ping contact',
                        'GET /api/v1/system/status' => 'System status',
                        'GET /api/v1/system/settings' => 'System settings',
                        'PUT /api/v1/system/settings' => 'Update settings (admin)',
                        'POST /api/v1/system/sync' => 'Trigger sync (admin)',
                        'POST /api/v1/system/shutdown' => 'Shutdown processors (admin)',
                        'POST /api/v1/system/start' => 'Start processors (admin)',
                        'GET /api/v1/chaindrop' => 'List chain drop proposals',
                        'POST /api/v1/chaindrop/propose' => 'Propose chain drop',
                        'POST /api/v1/chaindrop/accept' => 'Accept chain drop',
                        'POST /api/v1/chaindrop/reject' => 'Reject chain drop',
                        'GET /api/v1/backup/status' => 'Backup status',
                        'GET /api/v1/backup/list' => 'List backups',
                        'POST /api/v1/backup/create' => 'Create backup',
                        'POST /api/v1/backup/restore' => 'Restore from backup',
                        'POST /api/v1/backup/verify' => 'Verify backup integrity',
                        'DELETE /api/v1/backup/:filename' => 'Delete backup',
                        'POST /api/v1/backup/enable' => 'Enable auto backups',
                        'POST /api/v1/backup/disable' => 'Disable auto backups',
                        'POST /api/v1/backup/cleanup' => 'Cleanup old backups',
                        'GET /api/v1/keys' => 'List API keys (admin)',
                        'POST /api/v1/keys' => 'Create API key (admin)',
                        'DELETE /api/v1/keys/:key_id' => 'Delete API key (admin)',
                        'POST /api/v1/keys/enable/:key_id' => 'Enable API key (admin)',
                        'POST /api/v1/keys/disable/:key_id' => 'Disable API key (admin)'
                    ]
                ]
            ],
            'shutdown' => [
                'description' => 'Gracefully shutdown all processors (P2P, Transaction, Cleanup, ContactStatus)',
                'usage' => 'shutdown',
                'arguments' => [],
                'examples' => [
                    'shutdown' => 'Stop all background processors',
                    'shutdown --json' => 'JSON output'
                ],
                'note' => 'Sends SIGTERM to all running processors, removes PID/lockfiles, and creates a shutdown flag to prevent watchdog restarts. The node remains accessible via CLI and API but will not process incoming or outgoing messages. Use "eiou start" to resume.'
            ],
            'start' => [
                'description' => 'Resume processor operations after a previous shutdown',
                'usage' => 'start',
                'arguments' => [],
                'examples' => [
                    'start' => 'Resume all background processors',
                    'start --json' => 'JSON output'
                ],
                'note' => 'Removes the shutdown flag. The watchdog detects this and restarts all processors within 30 seconds. If no shutdown flag exists (processors already running), reports that and exits.'
            ],
            'chaindrop' => [
                'description' => 'Manage chain drop agreements for resolving transaction chain gaps',
                'usage' => 'chaindrop [action] ([args...])',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: propose, accept, reject, list, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'propose' => [
                        'usage' => 'chaindrop propose <contact_address>',
                        'description' => 'Propose dropping a missing transaction from the chain (auto-detects the gap)',
                        'arguments' => [
                            'contact_address' => ['type' => 'required', 'description' => 'Address of the contact with the broken chain']
                        ]
                    ],
                    'accept' => [
                        'usage' => 'chaindrop accept <proposal_id>',
                        'description' => 'Accept an incoming chain drop proposal',
                        'arguments' => [
                            'proposal_id' => ['type' => 'required', 'description' => 'ID of the proposal to accept']
                        ]
                    ],
                    'reject' => [
                        'usage' => 'chaindrop reject <proposal_id>',
                        'description' => 'Reject an incoming chain drop proposal (transactions remain blocked)',
                        'arguments' => [
                            'proposal_id' => ['type' => 'required', 'description' => 'ID of the proposal to reject']
                        ]
                    ],
                    'list' => [
                        'usage' => 'chaindrop list [contact_address]',
                        'description' => 'List pending chain drop proposals',
                        'arguments' => [
                            'contact_address' => ['type' => 'optional', 'description' => 'Filter by contact address (omit to list all incoming)']
                        ]
                    ],
                    'help' => [
                        'usage' => 'chaindrop help',
                        'description' => 'Show chain drop help'
                    ]
                ],
                'examples' => [
                    'chaindrop propose https://bob' => 'Propose dropping a missing transaction with Bob',
                    'chaindrop accept cdp-abc123...' => 'Accept an incoming proposal',
                    'chaindrop reject cdp-abc123...' => 'Reject a proposal (chain stays broken)',
                    'chaindrop list' => 'List all incoming pending proposals',
                    'chaindrop list https://bob' => 'List proposals for a specific contact'
                ],
                'note' => 'While a chain gap exists, transactions with that contact are blocked. Rejecting a proposal leaves the gap unresolved.'
            ],
            'backup' => [
                'description' => 'Manage encrypted database backups',
                'usage' => 'backup <action> [arguments]',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: create, restore, list, delete, verify, enable, disable, status, cleanup, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'create' => [
                        'description' => 'Create a new encrypted backup',
                        'usage' => 'backup create [name]',
                        'arguments' => [
                            'name' => ['type' => 'optional', 'description' => 'Custom name for the backup file']
                        ]
                    ],
                    'restore' => [
                        'description' => 'Restore database from an encrypted backup',
                        'usage' => 'backup restore <filename> --confirm',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to restore from'],
                            '--confirm' => ['type' => 'required', 'description' => 'Required flag to confirm the destructive operation']
                        ]
                    ],
                    'list' => [
                        'description' => 'List all available backups',
                        'usage' => 'backup list'
                    ],
                    'delete' => [
                        'description' => 'Delete a backup file',
                        'usage' => 'backup delete <filename>',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to delete']
                        ]
                    ],
                    'verify' => [
                        'description' => 'Verify backup integrity',
                        'usage' => 'backup verify <filename>',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to verify']
                        ]
                    ],
                    'enable' => [
                        'description' => 'Enable automatic daily backups',
                        'usage' => 'backup enable'
                    ],
                    'disable' => [
                        'description' => 'Disable automatic daily backups',
                        'usage' => 'backup disable'
                    ],
                    'status' => [
                        'description' => 'Show backup status and settings',
                        'usage' => 'backup status'
                    ],
                    'cleanup' => [
                        'description' => 'Remove old backups (keeps 3 most recent)',
                        'usage' => 'backup cleanup'
                    ],
                    'help' => [
                        'description' => 'Show backup help',
                        'usage' => 'backup help'
                    ]
                ],
                'examples' => [
                    'backup create' => 'Create backup with auto-generated name',
                    'backup create pre_upgrade' => 'Create backup with custom name',
                    'backup list' => 'List all backups',
                    'backup restore backup_20260124.eiou.enc --confirm' => 'Restore from backup',
                    'backup verify backup_20260124.eiou.enc' => 'Verify backup integrity',
                    'backup status' => 'Show backup status',
                    'backup enable' => 'Enable automatic daily backups'
                ],
                'note' => 'Backups are AES-256-CBC encrypted using the node\'s master key. Automatic backups run daily at midnight when enabled. Cleanup keeps the 3 most recent backups.'
            ],
            'dlq' => [
                'description' => 'Manage the dead letter queue — messages that failed delivery after all automatic retries',
                'usage' => 'dlq [list|retry|abandon] [id] [--status=pending|retrying|resolved|abandoned|all]',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'list (default), retry, or abandon'],
                    'id' => ['type' => 'optional', 'description' => 'DLQ item ID (required for retry and abandon)'],
                    '--status' => ['type' => 'optional', 'description' => 'Filter list by status (default: all active items)'],
                ],
                'examples' => [
                    'dlq' => 'List all pending and retrying DLQ items',
                    'dlq list --status=all' => 'List all items regardless of status',
                    'dlq retry 42' => 'Retry DLQ item #42 immediately',
                    'dlq abandon 42' => 'Abandon DLQ item #42 (no further retries)',
                    'dlq --json' => 'JSON output with statistics',
                ],
                'note' => 'The DLQ captures messages that could not be delivered after ' . Constants::DELIVERY_MAX_RETRIES . ' automatic attempts. All items originated from this node. Retry re-sends the original signed payload directly to the recipient.'
            ],
            'global_options' => [
                'description' => 'Global options available for all commands',
                'options' => [
                    '--json, -j' => 'Output results in JSON format for scripting/automation',
                    '--no-metadata' => 'Exclude metadata (timestamp, node_id) from JSON output'
                ]
            ]
        ];

        $specificCommand = isset($argv[2]) ? strtolower($argv[2]) : null;

        if ($output->isJsonMode()) {
            if ($specificCommand !== null) {
                if (isset($commands[$specificCommand])) {
                    $output->help([$specificCommand => $commands[$specificCommand]], $specificCommand);
                } else {
                    $output->error("Command '$specificCommand' does not exist", ErrorCodes::COMMAND_NOT_FOUND, 404);
                }
            } else {
                $output->help($commands);
            }
        } else {
            if ($specificCommand !== null) {
                echo "Command:\n";
                if (isset($commands[$specificCommand])) {
                    echo "\t" . $commands[$specificCommand]['usage'] . " - " . $commands[$specificCommand]['description'] . "\n";

                    // Show detailed help for the specific command
                    $this->showDetailedHelp($specificCommand, $commands[$specificCommand]);
                } else {
                    echo "\tcommand does not exist.\n";
                }
            } else {
                echo "Available commands:\n";
                foreach ($commands as $name => $cmd) {
                    if (isset($cmd['usage'])) {
                        echo "\t" . $cmd['usage'] . " - " . $cmd['description'] . "\n";
                    }
                }
            }
        }
    }

    /**
     * Display detailed help for a specific command in TTY mode.
     *
     * For commands with dedicated detailed help methods (apikey, chaindrop),
     * delegates to those. For all other commands, renders a structured help
     * output from the command definition array (arguments, examples, notes).
     *
     * @param string $command The command name
     * @param array $definition The command definition from the $commands array
     */
    private function showDetailedHelp(string $command, array $definition): void {
        // Commands with dedicated detailed help methods
        if ($command === 'apikey') {
            $this->showApiKeyDetailedHelp();
            return;
        }
        if ($command === 'chaindrop') {
            $this->showChainDropDetailedHelp();
            return;
        }

        // Generic detailed help from command definition
        echo "\n";

        // Arguments
        if (!empty($definition['arguments'])) {
            echo "Arguments:\n";
            foreach ($definition['arguments'] as $argName => $argInfo) {
                $type = $argInfo['type'] ?? 'required';
                $desc = $argInfo['description'] ?? '';
                echo "  {$argName} ({$type})\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Available settings (changesettings)
        if (!empty($definition['available_settings'])) {
            echo "Available settings:\n";
            foreach ($definition['available_settings'] as $setting => $desc) {
                echo "  {$setting}\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Examples
        if (!empty($definition['examples'])) {
            echo "Examples:\n";
            foreach ($definition['examples'] as $example => $desc) {
                echo "  eiou {$example}\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Actions (sub-commands)
        if (!empty($definition['actions'])) {
            echo "Actions:\n";
            foreach ($definition['actions'] as $actionName => $actionInfo) {
                $actionUsage = $actionInfo['usage'] ?? $actionName;
                $actionDesc = $actionInfo['description'] ?? '';
                echo "  {$actionUsage}\n";
                echo "    {$actionDesc}\n";
            }
            echo "\n";
        }

        // Note
        if (!empty($definition['note'])) {
            echo "Note:\n";
            echo "  {$definition['note']}\n";
            echo "\n";
        }
    }

    /**
     * Display detailed help for API key management commands
     */
    private function showApiKeyDetailedHelp(): void {
        $help = <<<HELP

API Key Management Commands
===========================

Create a new API key:
  eiou apikey create <name> [permissions]

  Example:
    eiou apikey create "My Application" wallet:read,contacts:read

  Available permissions:
    - wallet:read     Read wallet balance and transactions
    - wallet:send     Send transactions
    - contacts:read   List and view contacts
    - contacts:write  Add, update, delete contacts
    - system:read     View system status and metrics
    - admin           Full administrative access
    - all             All permissions (same as admin)

List all API keys:
  eiou apikey list

Delete an API key (permanent):
  eiou apikey delete <key_id>

Disable an API key (can be re-enabled):
  eiou apikey disable <key_id>

Enable a disabled API key:
  eiou apikey enable <key_id>

API Usage
=========

Once you have an API key, make requests to:
  http://your-node/api/v1/...

Required headers for each request:
  X-API-Key: <key_id>
  X-API-Timestamp: <unix_timestamp>
  X-API-Signature: <hmac>

The HMAC signature is calculated as:
  HMAC-SHA256(secret, METHOD + "\\n" + PATH + "\\n" + TIMESTAMP + "\\n" + BODY)

IMPORTANT: Never send the secret in requests - only the computed HMAC signature.
The server retrieves and decrypts your secret to verify the signature.

Example endpoints:
  GET  /api/v1/wallet/balance       - Get wallet balances
  GET  /api/v1/wallet/overview      - Wallet overview (balance + recent transactions)
  POST /api/v1/wallet/send          - Send transaction
  GET  /api/v1/wallet/transactions  - Transaction history
  GET  /api/v1/contacts             - List contacts
  GET  /api/v1/contacts/pending     - Pending contact requests
  GET  /api/v1/contacts/search?q=   - Search contacts by name
  POST /api/v1/contacts             - Add contact
  POST /api/v1/contacts/ping/:addr  - Ping contact
  GET  /api/v1/system/status        - System status
  GET  /api/v1/system/settings      - System settings

HELP;

        echo $help;
    }

    /**
     * Display detailed help for chain drop agreement commands
     */
    private function showChainDropDetailedHelp(): void {
        $help = <<<HELP

Chain Drop Agreement Commands
=============================

When both contacts are missing the same transaction in their shared chain,
the chain cannot be repaired via sync. Chain drop resolves this by mutually
agreeing to remove the missing transaction and relink the chain.

IMPORTANT: While a chain gap exists, transactions with that contact are
blocked. The send command verifies chain integrity before every transaction
and will halt if a gap is detected.

Propose dropping a missing transaction:
  eiou chaindrop propose <contact_address>

  Auto-detects the chain gap and sends a proposal to the contact.
  Example:
    eiou chaindrop propose https://bob

Accept an incoming proposal:
  eiou chaindrop accept <proposal_id>

  Executes the chain drop, re-signs affected transactions, and
  exchanges re-signed copies with the proposer.
  Example:
    eiou chaindrop accept cdp-2c3c26ba61ab4073...

Reject an incoming proposal:
  eiou chaindrop reject <proposal_id>

  WARNING: Rejecting leaves the chain gap unresolved. Transactions
  with this contact remain blocked until a new proposal is accepted.
  Example:
    eiou chaindrop reject cdp-2c3c26ba61ab4073...

List pending proposals:
  eiou chaindrop list [contact_address]

  Without an address, lists all incoming pending proposals.
  With an address, lists proposals for that specific contact.
  Examples:
    eiou chaindrop list
    eiou chaindrop list https://bob

Flow
====

1. Contact A runs: eiou chaindrop propose <contact_B_address>
2. Contact B receives the proposal (visible via: eiou chaindrop list)
3. Contact B runs: eiou chaindrop accept <proposal_id>
4. Both chains are repaired and transactions can resume

For multiple gaps, repeat the propose/accept cycle for each gap.

HELP;

        echo $help;
    }

    // =========================================================================
    // USER INFORMATION
    // =========================================================================

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
                        'total_earnings' => number_format(((int)($row['total_amount'] ?? 0)) / Constants::CONVERSION_FACTORS[$row['currency']], 2)
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
                        'total_available_credit' => number_format($row['total_available_credit'] / Constants::CONVERSION_FACTORS[$row['currency']], 2)
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
                        'total_balance' => number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
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
                        printf("\tTotal Balance %s : %s\n", $balance['currency'], number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2));
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

        // Format incoming requests
        foreach ($incomingRequests as $contact) {
            $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? 'Unknown';
            $pendingData['incoming'][] = [
                'address' => $contactAddress,
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'created_at' => $contact['created_at'] ?? null,
                'status' => 'pending'
            ];
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
                    'total_balance' => number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2)
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
                        str_pad(number_format($tx['amount'], 2), 10),
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
            printf("\t\t\t%s %s %s (%s), %.2f %s\n", 
                    $res['date'],
                    "|",
                    $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($res['counterparty']),$res['counterparty']), 
                    $this->generalUtility->truncateAddress($res['counterparty'],30), 
                    $res['amount'], 
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
                        $contactNetBalance = 0;
                        foreach($contactBalances as $cb){
                            $contactNetBalance += ($cb['received'] - $cb['sent']);
                        }
                        $balanceData['user']['balances'][] = [
                            'currency' => $balance['currency'],
                            'contact_balance' => number_format($contactNetBalance / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                            'total_balance' => number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2)
                        ];
                        foreach($contactBalances as $contactBalance){
                            $balanceData['contacts'][] = [
                                'name' => $contactResult['name'],
                                'address' => $contactResult['tor'] ?? $contactResult['https'] ?? $contactResult['http'],
                                'currency' => $contactBalance['currency'],
                                'received' => number_format($contactBalance['received'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                                'sent' => number_format($contactBalance['sent'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2)
                            ];
                        }
                    } else {
                        $balanceData['user']['balances'][] = [
                            'currency' => $balance['currency'],
                            'total_balance' => number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2)
                        ];
                        if(isset($contacts)){
                            foreach($contacts as $contact){
                                $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                                foreach($contactBalances as $contactBalance){
                                    $balanceData['contacts'][] = [
                                        'name' => $contact['name'],
                                        'address' => $contact['tor'] ?? $contact['https'] ?? $contact['http'],
                                        'currency' => $contactBalance['currency'],
                                        'received' => number_format($contactBalance['received'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                                        'sent' => number_format($contactBalance['sent'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2)
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
                        $contactNetBalance = 0;
                        foreach($contactBalances as $contactBalance){
                            $contactNetBalance += ($contactBalance['received'] - $contactBalance['sent']);
                        }
                        printf("%s %s, Balance %s with %s: %.2f\n", 'me', $additionalInfo, $balance['currency'], $contactResult['name'], number_format($contactNetBalance / Constants::CONVERSION_FACTORS[$balance['currency']], 2));
                        foreach($contactBalances as $contactBalance){
                            printf("\t%s (%s), Balance (%s | %s): %.2f | %.2f %s\n",
                                $contactResult['name'],
                                $contactResult['tor'] ?? $contactResult['https'] ?? $contactResult['http'],
                                'received',
                                'sent',
                                number_format($contactBalance['received'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                                number_format($contactBalance['sent'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                                $contactBalance['currency']
                            );
                        }
                        return;
                    } else{
                        printf("%s %s, Balance %s : %.2f\n", 'me', $additionalInfo, $balance['currency'], number_format($balance['total_balance'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2));
                        if(!isset($contacts) || !$contacts){
                            echo "\tNo Contacts exist, so no contact balances can be displayed.\n";
                            continue;
                        } else{
                            foreach($contacts as $contact){
                                $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                                foreach($contactBalances as $contactBalance){
                                    printf("\t%s (%s), Balance (%s | %s): %.2f | %.2f %s\n",
                                        $contact['name'],
                                        $contact['tor'] ?? $contact['https'] ?? $contact['http'],
                                        'received',
                                        'sent',
                                        number_format($contactBalance['received'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
                                        number_format($contactBalance['sent'] / Constants::CONVERSION_FACTORS[$balance['currency']], 2),
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
    // SSL CERTIFICATE MANAGEMENT
    // =========================================================================

    /**
     * Regenerate SSL certificate when hostname changes
     *
     * This method removes the existing SSL certificate and triggers regeneration
     * with the new hostname as CN and in the SANs. The certificate is regenerated
     * on the next Apache restart or can be done immediately.
     *
     * @param string $newHostname The new hostname to use for the certificate
     * @param CliOutputManager|null $output Optional output manager for status messages
     */
    private function regenerateSslCertificate(string $newHostname, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $sslCertPath = '/etc/apache2/ssl/server.crt';
        $sslKeyPath = '/etc/apache2/ssl/server.key';

        // Check if we're using externally provided certificates (don't regenerate those)
        if (file_exists('/ssl-certs/server.crt')) {
            if (!$output->isJsonMode()) {
                echo "Note: Using externally provided SSL certificate - not regenerating.\n";
            }
            return;
        }

        // Remove existing certificate to trigger regeneration
        if (file_exists($sslCertPath)) {
            unlink($sslCertPath);
        }
        if (file_exists($sslKeyPath)) {
            unlink($sslKeyPath);
        }

        // Extract domain from hostname URL (e.g., "http://alice" -> "alice")
        $domain = preg_replace('#^https?://#', '', $newHostname);
        $domain = rtrim($domain, '/');

        // Build SAN list with auto-detected IPs
        $sanList = "DNS:localhost,DNS:{$domain}";

        // Add container hostname if different
        $containerHostname = gethostname();
        if ($containerHostname && $containerHostname !== $domain && $containerHostname !== 'localhost') {
            $sanList .= ",DNS:{$containerHostname}";
        }

        // Auto-detect container IP addresses
        $ipOutput = shell_exec('hostname -I 2>/dev/null');
        if ($ipOutput) {
            $ips = preg_split('/\s+/', trim($ipOutput));
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                    $sanList .= ",IP:{$ip}";
                }
            }
        }
        $sanList .= ",IP:127.0.0.1";

        // Add extra SANs from environment if set
        $extraSans = getenv('SSL_EXTRA_SANS');
        if ($extraSans) {
            $sanList .= ",{$extraSans}";
        }

        // Create OpenSSL config with SANs
        $opensslConfig = "[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = req_ext
x509_extensions = v3_ext

[dn]
C = XX
ST = State
L = City
O = EIOU
OU = Node
CN = {$domain}

[req_ext]
subjectAltName = {$sanList}

[v3_ext]
subjectAltName = {$sanList}
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
";

        $configPath = '/tmp/openssl-san.cnf';
        file_put_contents($configPath, $opensslConfig);

        // Check if CA is available for CA-signed certificate
        if (file_exists('/ssl-ca/ca.crt') && file_exists('/ssl-ca/ca.key')) {
            // Generate CA-signed certificate
            $csrPath = '/tmp/server.csr';

            // Generate key and CSR
            shell_exec("openssl req -new -nodes -newkey rsa:2048 -keyout {$sslKeyPath} -out {$csrPath} -config {$configPath} 2>/dev/null");

            // Sign with CA
            shell_exec("openssl x509 -req -in {$csrPath} -CA /ssl-ca/ca.crt -CAkey /ssl-ca/ca.key -CAcreateserial -out {$sslCertPath} -days 365 -sha256 -extfile {$configPath} -extensions v3_ext 2>/dev/null");

            @unlink($csrPath);

            if (!$output->isJsonMode()) {
                echo "SSL certificate regenerated (CA-signed) for hostname: {$domain}\n";
            }
        } else {
            // Generate self-signed certificate
            shell_exec("openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout {$sslKeyPath} -out {$sslCertPath} -config {$configPath} 2>/dev/null");

            if (!$output->isJsonMode()) {
                echo "SSL certificate regenerated (self-signed) for hostname: {$domain}\n";
                echo "Note: Browsers will show warnings for self-signed certificates.\n";
            }
        }

        @unlink($configPath);

        // Set proper permissions
        if (file_exists($sslKeyPath)) {
            chmod($sslKeyPath, 0600);
        }
        if (file_exists($sslCertPath)) {
            chmod($sslCertPath, 0644);
        }

        // Reload Apache to use new certificate
        shell_exec('apache2ctl graceful 2>/dev/null');

        if (!$output->isJsonMode()) {
            echo "Apache reloaded to use new certificate.\n";
        }
    }

    // =========================================================================
    // P2P APPROVAL MANAGEMENT
    // =========================================================================

    /**
     * Display P2P transactions awaiting approval
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayPendingP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null) {
            $output->error('P2P repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $awaitingList = $this->p2pRepository->getAwaitingApprovalList();

        $p2pData = [];
        foreach ($awaitingList as $p2p) {
            $candidateCount = 0;
            if ($this->rp2pCandidateRepository !== null) {
                $candidateCount = $this->rp2pCandidateRepository->getCandidateCount($p2p['hash']);
            }

            $p2pData[] = [
                'hash' => $p2p['hash'],
                'amount' => (int) $p2p['amount'],
                'currency' => $p2p['currency'],
                'destination_address' => $p2p['destination_address'],
                'my_fee_amount' => (int) ($p2p['my_fee_amount'] ?? 0),
                'rp2p_amount' => $p2p['rp2p_amount'] !== null ? (int) $p2p['rp2p_amount'] : null,
                'fast' => (int) $p2p['fast'],
                'candidate_count' => $candidateCount,
                'created_at' => $p2p['created_at'],
            ];
        }

        if ($output->isJsonMode()) {
            $output->success('Pending P2P transactions retrieved', [
                'transactions' => $p2pData,
                'count' => count($p2pData),
            ], 'Pending P2P transactions');
        } else {
            if (empty($p2pData)) {
                echo "No pending P2P transactions awaiting approval.\n";
                return;
            }

            echo "P2P Transactions Awaiting Approval\n";
            echo "===================================\n\n";

            foreach ($p2pData as $i => $p2p) {
                $mode = $p2p['fast'] ? 'fast' : 'best-fee';
                $totalCost = $p2p['rp2p_amount'] !== null
                    ? $this->currencyUtility->formatCurrency($p2p['rp2p_amount'], $p2p['currency'])
                    : 'pending';
                echo ($i + 1) . ". Hash: " . $p2p['hash'] . "\n";
                echo "   Amount: " . $this->currencyUtility->formatCurrency($p2p['amount'], $p2p['currency']) . " " . $p2p['currency'] . "\n";
                echo "   Total cost: " . $totalCost . " | Mode: " . $mode . "\n";
                echo "   Candidates: " . $p2p['candidate_count'] . " | Created: " . $p2p['created_at'] . "\n";
                echo "\n";
            }

            echo "-------------------------------------------\n";
            echo "Total: " . count($p2pData) . " transaction(s) awaiting approval\n";
            echo "\nUse: eiou p2p candidates <hash>  to view route options\n";
            echo "     eiou p2p approve <hash>     to approve\n";
            echo "     eiou p2p reject <hash>      to reject\n";
        }
    }

    /**
     * Display route candidates for a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayP2pCandidates(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null || $this->rp2pCandidateRepository === null) {
            $output->error('P2P approval dependencies not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p candidates <hash>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);

        // Also check for single rp2p (fast mode)
        $rp2p = null;
        if ($this->rp2pRepository !== null) {
            $rp2p = $this->rp2pRepository->getByHash($hash);
        }

        if ($output->isJsonMode()) {
            $output->success('P2P candidates retrieved', [
                'hash' => $hash,
                'amount' => (int) $p2p['amount'],
                'currency' => $p2p['currency'],
                'fast' => (int) $p2p['fast'],
                'candidates' => $candidates,
                'rp2p' => $rp2p,
            ], 'P2P candidates');
        } else {
            echo "Route Candidates for P2P: " . $hash . "\n";
            echo "==========================================\n";
            echo "Amount: " . $this->currencyUtility->formatCurrency((int) $p2p['amount'], $p2p['currency']) . " " . $p2p['currency'] . "\n\n";

            if (!empty($candidates)) {
                echo "Available routes (ordered by fee, lowest first):\n";
                echo "-------------------------------------------\n";
                foreach ($candidates as $i => $candidate) {
                    $num = $i + 1;
                    echo "  [{$num}] Via: " . $candidate['sender_address'] . "\n";
                    echo "      Total amount: " . $this->currencyUtility->formatCurrency((int) $candidate['amount'], $candidate['currency']) . " " . $candidate['currency'] . "\n";
                    echo "      Fee: " . $this->currencyUtility->formatCurrency((int) $candidate['fee_amount'], $candidate['currency']) . "\n";
                    echo "\n";
                }
                echo "Use: eiou p2p approve {$hash} <number>  to approve a route\n";
            } elseif ($rp2p) {
                echo "Single route (fast mode):\n";
                echo "-------------------------------------------\n";
                echo "  Via: " . $rp2p['sender_address'] . "\n";
                echo "  Total amount: " . $this->currencyUtility->formatCurrency((int) $rp2p['amount'], $rp2p['currency']) . " " . $rp2p['currency'] . "\n";
                echo "\nUse: eiou p2p approve {$hash}  to approve\n";
            } else {
                echo "No route candidates available yet. Routes may still be arriving.\n";
            }
        }
    }

    /**
     * Approve a P2P transaction and send it
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function approveP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null || $this->rp2pCandidateRepository === null
            || $this->p2pTransactionSender === null) {
            $output->error('P2P approval dependencies not configured', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p approve <hash> [index]', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (empty($p2p['destination_address'])) {
            $output->error('Only the transaction originator can approve', ErrorCodes::PERMISSION_DENIED, 403);
            return;
        }

        $candidateIndex = isset($argv[4]) ? (int) $argv[4] : 0;

        if ($candidateIndex > 0) {
            // User selected a specific candidate by 1-based index
            $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);
            if (empty($candidates)) {
                $output->error('No candidates available for this transaction', ErrorCodes::NOT_FOUND, 404);
                return;
            }

            if ($candidateIndex < 1 || $candidateIndex > count($candidates)) {
                $output->error("Invalid candidate index. Choose between 1 and " . count($candidates), ErrorCodes::VALIDATION_ERROR, 400);
                return;
            }

            $candidate = $candidates[$candidateIndex - 1];

            $request = [
                'hash' => $candidate['hash'],
                'time' => $candidate['time'],
                'amount' => (int) $candidate['amount'],
                'currency' => $candidate['currency'],
                'senderPublicKey' => $candidate['sender_public_key'],
                'senderAddress' => $candidate['sender_address'],
                'signature' => $candidate['sender_signature'],
            ];

            // Candidate amount already includes the originator's fee from handleRp2pCandidate.
            // Insert rp2p record (required by daemon's processOutgoingP2p for the 'time' field).

            try {
                $this->rp2pRepository->insertRp2pRequest($request);
                $this->p2pRepository->updateStatus($hash, 'found');
                $this->p2pTransactionSender->sendP2pEiou($request);
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);

                $output->success('P2P transaction approved and sent', [
                    'hash' => $hash,
                    'candidate_index' => $candidateIndex,
                    'sender_address' => $candidate['sender_address'],
                ], "P2P transaction {$hash} approved (candidate #{$candidateIndex})");
            } catch (\Throwable $e) {
                $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
            }
            return;
        }

        // No index provided - check for single rp2p (fast mode)
        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);

        if (!empty($candidates) && count($candidates) > 1) {
            $output->error(
                'Multiple route candidates available. Specify an index: eiou p2p approve ' . $hash . ' <1-' . count($candidates) . '>',
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return;
        }

        if (!empty($candidates) && count($candidates) === 1) {
            // Single candidate in best-fee mode - use it
            $candidate = $candidates[0];

            $request = [
                'hash' => $candidate['hash'],
                'time' => $candidate['time'],
                'amount' => (int) $candidate['amount'],
                'currency' => $candidate['currency'],
                'senderPublicKey' => $candidate['sender_public_key'],
                'senderAddress' => $candidate['sender_address'],
                'signature' => $candidate['sender_signature'],
            ];

            try {
                $this->rp2pRepository->insertRp2pRequest($request);
                $this->p2pRepository->updateStatus($hash, 'found');
                $this->p2pTransactionSender->sendP2pEiou($request);
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);

                $output->success('P2P transaction approved and sent', [
                    'hash' => $hash,
                    'sender_address' => $candidate['sender_address'],
                ], "P2P transaction {$hash} approved");
            } catch (\Throwable $e) {
                $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
            }
            return;
        }

        // No candidates - check for single rp2p (fast mode)
        if ($this->rp2pRepository !== null) {
            $rp2p = $this->rp2pRepository->getByHash($hash);
            if ($rp2p) {
                $request = [
                    'hash' => $rp2p['hash'],
                    'time' => $rp2p['time'],
                    'amount' => (int) $rp2p['amount'],
                    'currency' => $rp2p['currency'],
                    'senderPublicKey' => $rp2p['sender_public_key'],
                    'senderAddress' => $rp2p['sender_address'],
                    'signature' => $rp2p['sender_signature'],
                ];

                try {
                    $this->p2pRepository->updateStatus($hash, 'found');
                    $this->p2pTransactionSender->sendP2pEiou($request);

                    $output->success('P2P transaction approved and sent', [
                        'hash' => $hash,
                        'sender_address' => $rp2p['sender_address'],
                    ], "P2P transaction {$hash} approved (fast mode)");
                } catch (\Throwable $e) {
                    $output->error('Failed to send transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
                }
                return;
            }
        }

        $output->error('No route available for this transaction. Routes may still be arriving.', ErrorCodes::NOT_FOUND, 404);
    }

    /**
     * Reject a P2P transaction
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function rejectP2p(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->p2pRepository === null) {
            $output->error('P2P repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $hash = $argv[3] ?? null;
        if (empty($hash)) {
            $output->error('Transaction hash is required. Usage: eiou p2p reject <hash>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            $output->error('Transaction not found or not awaiting approval', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (empty($p2p['destination_address'])) {
            $output->error('Only the transaction originator can reject', ErrorCodes::PERMISSION_DENIED, 403);
            return;
        }

        try {
            $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);

            // Propagate cancel upstream
            if ($this->p2pService !== null) {
                $this->p2pService->sendCancelNotificationForHash($hash);
            }

            // Clean up any remaining candidates
            if ($this->rp2pCandidateRepository !== null) {
                $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
            }

            $output->success('P2P transaction rejected', [
                'hash' => $hash,
            ], "P2P transaction {$hash} rejected and cancelled");
        } catch (\Throwable $e) {
            $output->error('Failed to reject transaction: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
        }
    }

    // =========================================================================
    // DEAD LETTER QUEUE (DLQ) MANAGEMENT
    // =========================================================================

    /**
     * List DLQ items
     *
     * Usage: eiou dlq [list] [--status=pending|retrying|resolved|abandoned|all]
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayDlqItems(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        // Parse optional --status flag
        $statusFilter = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--status=')) {
                $statusFilter = substr($arg, 9);
            }
        }

        $allowedStatuses = ['pending', 'retrying', 'resolved', 'abandoned'];
        if ($statusFilter !== null && $statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
            $output->error("Invalid status filter. Use: pending, retrying, resolved, abandoned, or all", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        $items = $this->dlqRepository->getItems(
            ($statusFilter === 'all' || $statusFilter === null) ? null : $statusFilter,
            Constants::DLQ_BATCH_SIZE
        );

        $stats = $this->dlqRepository->getStatistics();

        if ($output->isJsonMode()) {
            $output->success('DLQ items', [
                'items'      => $items,
                'statistics' => $stats,
                'filter'     => $statusFilter ?? 'all',
            ]);
            return;
        }

        echo "Dead Letter Queue\n";
        echo str_repeat("=", 60) . "\n";
        echo sprintf(
            "Pending: %d  Retrying: %d  Resolved: %d  Abandoned: %d\n\n",
            $stats['pending'] ?? 0,
            $stats['retrying'] ?? 0,
            $stats['resolved'] ?? 0,
            $stats['abandoned'] ?? 0
        );

        if (empty($items)) {
            echo "No items" . ($statusFilter ? " with status '{$statusFilter}'" : "") . ".\n";
            return;
        }

        echo sprintf("%-5s %-12s %-10s %-6s %-16s %s\n", "ID", "Type", "Status", "Tries", "Added", "Recipient");
        echo str_repeat("-", 80) . "\n";
        foreach ($items as $item) {
            $ts = strtotime($item['created_at'] ?? '');
            $date = $ts ? date('m-d H:i', $ts) : '—';
            $recipient = $item['recipient_address'] ?? '';
            if (strlen($recipient) > 38) { $recipient = substr($recipient, 0, 35) . '...'; }
            echo sprintf(
                "%-5d %-12s %-10s %-6d %-16s %s\n",
                $item['id'],
                $item['message_type'] ?? '?',
                $item['status'] ?? '?',
                $item['retry_count'] ?? 0,
                $date,
                $recipient
            );
            if (!empty($item['failure_reason'])) {
                $reason = $item['failure_reason'];
                if (strlen($reason) > 70) { $reason = substr($reason, 0, 67) . '...'; }
                echo "      Reason: {$reason}\n";
            }
        }
        echo "\nUse 'eiou dlq retry <id>' to retry or 'eiou dlq abandon <id>' to abandon.\n";
    }

    /**
     * Retry a DLQ item by ID
     *
     * Usage: eiou dlq retry <id>
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function retryDlqItem(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $id = isset($argv[3]) ? (int)$argv[3] : 0;
        if ($id <= 0) {
            $output->error('DLQ item ID is required. Usage: eiou dlq retry <id>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $item = $this->dlqRepository->getById($id);
        if (!$item) {
            $output->error("DLQ item #{$id} not found", ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (!in_array($item['status'], ['pending', 'retrying'], true)) {
            $output->error("Item #{$id} has status '{$item['status']}' and cannot be retried", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        if (in_array($item['message_type'], ['p2p', 'rp2p'], true)) {
            $output->error(
                "P2P and relay messages (type '{$item['message_type']}') cannot be retried — they are time-sensitive routing messages that expire in " . Constants::P2P_DEFAULT_EXPIRATION_SECONDS . "s and are stale by the time they reach the DLQ. Use 'eiou dlq abandon {$id}' instead.",
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        $this->dlqRepository->markRetrying($id);

        $successStatuses = ['received', 'inserted', 'forwarded', 'accepted', 'acknowledged', 'completed', 'warning', 'updated', 'already_relayed'];
        $recipient = $item['recipient_address'];
        $payload   = $item['payload'];

        try {
            $sendResult = $this->transportUtility->send($recipient, $payload, true);
            $response   = is_array($sendResult) ? ($sendResult['response'] ?? '') : $sendResult;
            $decoded    = json_decode($response, true);
            $status     = $decoded['status'] ?? null;

            if ($status && in_array($status, $successStatuses, true)) {
                $this->dlqRepository->markResolved($id);
                $output->success("DLQ item #{$id} successfully re-sent", [
                    'id'             => $id,
                    'message_type'   => $item['message_type'],
                    'recipient'      => $recipient,
                    'response_status' => $status,
                ], "Message delivered to {$recipient}");
            } else {
                $this->dlqRepository->returnToPending($id);
                $errDetail = $status ? "Recipient returned: {$status}" : 'No response from recipient';
                $output->error("Retry failed — item returned to pending. {$errDetail}", ErrorCodes::GENERAL_ERROR);
            }
        } catch (\Throwable $e) {
            $this->dlqRepository->returnToPending($id);
            $output->error('Retry failed: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
        }
    }

    /**
     * Abandon a DLQ item by ID
     *
     * Usage: eiou dlq abandon <id>
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function abandonDlqItem(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $id = isset($argv[3]) ? (int)$argv[3] : 0;
        if ($id <= 0) {
            $output->error('DLQ item ID is required. Usage: eiou dlq abandon <id>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $item = $this->dlqRepository->getById($id);
        if (!$item) {
            $output->error("DLQ item #{$id} not found", ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if ($item['status'] === 'abandoned') {
            $output->error("Item #{$id} is already abandoned", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        $success = $this->dlqRepository->markAbandoned($id, 'Manually abandoned via CLI');

        if ($success) {
            $output->success("DLQ item #{$id} abandoned", [
                'id'           => $id,
                'message_type' => $item['message_type'],
                'recipient'    => $item['recipient_address'],
            ], "Item #{$id} marked as abandoned");
        } else {
            $output->error("Failed to abandon item #{$id}", ErrorCodes::GENERAL_ERROR);
        }
    }
}