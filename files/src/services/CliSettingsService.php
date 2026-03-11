<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Utils\InputValidator;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\UserContext;

/**
 * CliSettingsService
 *
 * Handles Settings Management, Settings Display, and SSL Certificate Management
 * for the CLI. Extracted from CliService as part of the ARCH-04 refactor to
 * reduce CliService's size and improve separation of concerns.
 */
class CliSettingsService
{
    private UserContext $currentUser;

    public function __construct(UserContext $currentUser)
    {
        $this->currentUser = $currentUser;
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
            } elseif(strtolower($argv[2]) === 'maxp2plevel'){
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
            } elseif(strtolower($argv[2]) === 'directtxexpiration'){
                $key = 'directTxExpiration';
                if (!is_numeric($argv[3]) || intval($argv[3]) < 0) {
                    $output->validationError('directTxExpiration', 'Must be a non-negative integer (0 = no expiry)');
                    return;
                }
                $value = intval($argv[3]);
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
            } elseif(strtolower($argv[2]) === 'autobackupenabled'){
                $key = 'autoBackupEnabled';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
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
            } elseif(strtolower($argv[2]) === 'autochaindropacceptguard'){
                $key = 'autoChainDropAcceptGuard';
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
            } elseif(strtolower($argv[2]) === 'torcircuitmaxfailures'){
                $key = 'torCircuitMaxFailures';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 1, 10, 'Tor circuit max failures');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'torcircuitcooldownseconds'){
                $key = 'torCircuitCooldownSeconds';
                $validation = InputValidator::validateIntRange($argv[3] ?? '', 60, 3600, 'Tor circuit cooldown');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'torfailuretransportfallback'){
                $key = 'torFailureTransportFallback';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
                if (!$validation['valid']) { $output->validationError($key, $validation['error']); return; }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'torfallbackrequireencrypted'){
                $key = 'torFallbackRequireEncrypted';
                $validation = InputValidator::validateBoolean($argv[3] ?? '');
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
            // Currency management
            } elseif(strtolower($argv[2]) === 'allowedcurrencies'){
                $key = 'allowedCurrencies';
                $currencies = array_filter(array_map('trim', explode(',', strtoupper($argv[3] ?? ''))));
                foreach ($currencies as $c) {
                    $validation = InputValidator::validateAllowedCurrency($c);
                    if (!$validation['valid']) {
                        $output->validationError($key, "Currency {$c}: " . $validation['error']);
                        return;
                    }
                }
                $value = implode(',', $currencies);
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

            // Two-level interactive settings menu
            $categories = [
                'Transaction Settings' => [
                    ['num' => '1', 'label' => 'Default currency'],
                    ['num' => '2', 'label' => 'Minimum fee amount'],
                    ['num' => '3', 'label' => 'Default fee percentage'],
                    ['num' => '4', 'label' => 'Maximum fee percentage'],
                    ['num' => '5', 'label' => 'Default credit limit'],
                ],
                'P2P & Network' => [
                    ['num' => '6', 'label' => 'Maximum peer to peer Level'],
                    ['num' => '7', 'label' => 'Default peer to peer Expiration'],
                    ['num' => '8', 'label' => 'Direct transaction delivery expiration'],
                    ['num' => '9', 'label' => 'Default transport type'],
                    ['num' => '10', 'label' => 'HTTP transport timeout'],
                    ['num' => '11', 'label' => 'Tor transport timeout'],
                    ['num' => '12', 'label' => 'Hostname'],
                    ['num' => '13', 'label' => 'Trusted proxy IPs'],
                    ['num' => '14', 'label' => 'Auto-accept P2P transactions'],
                    ['num' => '45', 'label' => 'Tor circuit max failures before cooldown'],
                    ['num' => '46', 'label' => 'Tor circuit cooldown duration (seconds)'],
                    ['num' => '47', 'label' => 'Tor failure transport fallback'],
                    ['num' => '48', 'label' => 'Tor fallback require encrypted (HTTPS only)'],
                ],
                'Feature Toggles' => [
                    ['num' => '15', 'label' => 'Display name'],
                    ['num' => '16', 'label' => 'Auto-refresh transactions'],
                    ['num' => '17', 'label' => 'Contact status pinging'],
                    ['num' => '18', 'label' => 'Contact status sync on ping'],
                    ['num' => '19', 'label' => 'Auto chain drop propose'],
                    ['num' => '20', 'label' => 'Auto chain drop accept'],
                    ['num' => '21', 'label' => 'Auto chain drop accept guard'],
                    ['num' => '22', 'label' => 'API enabled'],
                    ['num' => '23', 'label' => 'API CORS allowed origins'],
                    ['num' => '24', 'label' => 'Rate limiting enabled'],
                ],
                'Backup & Logging' => [
                    ['num' => '25', 'label' => 'Auto-backup database'],
                    ['num' => '26', 'label' => 'Backup retention count'],
                    ['num' => '27', 'label' => 'Backup schedule hour'],
                    ['num' => '28', 'label' => 'Backup schedule minute'],
                    ['num' => '29', 'label' => 'Log level'],
                    ['num' => '30', 'label' => 'Log max entries'],
                ],
                'Data Retention' => [
                    ['num' => '31', 'label' => 'Delivery retention days'],
                    ['num' => '32', 'label' => 'DLQ retention days'],
                    ['num' => '33', 'label' => 'Held TX retention days'],
                    ['num' => '34', 'label' => 'RP2P retention days'],
                    ['num' => '35', 'label' => 'Metrics retention days'],
                ],
                'Rate Limiting' => [
                    ['num' => '36', 'label' => 'P2P rate limit per minute'],
                    ['num' => '37', 'label' => 'Rate limit max attempts'],
                    ['num' => '38', 'label' => 'Rate limit window seconds'],
                    ['num' => '39', 'label' => 'Rate limit block duration'],
                ],
                'Display' => [
                    ['num' => '40', 'label' => 'Maximum lines of balance/transaction output'],
                    ['num' => '41', 'label' => 'Date format'],
                    ['num' => '42', 'label' => 'Currency decimals'],
                    ['num' => '43', 'label' => 'Recent transactions limit'],
                ],
                'Currency Management' => [
                    ['num' => '44', 'label' => 'Allowed currencies'],
                ],
            ];

            $setting_choice = null;
            while (true) {
                // Level 1: Category selection
                echo "\nSelect a category:\n";
                $catNames = array_keys($categories);
                foreach ($catNames as $i => $catName) {
                    $count = count($categories[$catName]);
                    echo "\t" . ($i + 1) . ". {$catName} ({$count})\n";
                }
                echo "\n\t0. Cancel\n";

                $catChoice = trim(fgets(STDIN));
                if ($catChoice === '0') {
                    echo "Setting change cancelled.\n";
                    return;
                }
                $catIndex = intval($catChoice) - 1;
                if ($catIndex < 0 || $catIndex >= count($catNames)) {
                    echo "Invalid category. Please try again.\n";
                    continue;
                }

                $selectedCat = $catNames[$catIndex];
                $catSettings = $categories[$selectedCat];

                // Level 2: Setting selection within category
                echo "\n  {$selectedCat}:\n";
                foreach ($catSettings as $i => $setting) {
                    echo "\t" . ($i + 1) . ". " . $setting['label'] . "\n";
                }
                echo "\n\t0. Back\n";

                $settingChoice = trim(fgets(STDIN));
                if ($settingChoice === '0') {
                    continue; // Back to category menu
                }
                $settingIndex = intval($settingChoice) - 1;
                if ($settingIndex < 0 || $settingIndex >= count($catSettings)) {
                    echo "Invalid setting. Please try again.\n";
                    continue;
                }

                $setting_choice = $catSettings[$settingIndex]['num'];
                break;
            }

            switch($setting_choice) {
                // Transaction Settings
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

                // P2P & Network
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
                    echo "Enter direct transaction delivery expiration in seconds (0 = no expiry, e.g., 3600): ";
                    $key = 'directTxExpiration';
                    $rawInput = trim(fgets(STDIN));
                    if (!is_numeric($rawInput) || intval($rawInput) < 0) {
                        echo "Error: Must be a non-negative integer (0 = no expiry)\n";
                        return;
                    }
                    $value = intval($rawInput);
                    break;

                case '9':
                    echo "Enter new default transport type (e.g. http, https, tor): ";
                    $key = 'defaultTransportMode';
                    $value = strtolower(trim(fgets(STDIN)));
                    break;

                case '10':
                    echo "Enter HTTP transport timeout in seconds (5-120): ";
                    $key = 'httpTransportTimeoutSeconds';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 5, 120, 'HTTP timeout');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '11':
                    echo "Enter Tor transport timeout in seconds (10-300): ";
                    $key = 'torTransportTimeoutSeconds';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 10, 300, 'Tor timeout');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '12':
                    echo "Enter new hostname (e.g. http://httpA): ";
                    $key = 'hostname';
                    $validation = InputValidator::validateHostname(strtolower(trim(fgets(STDIN))));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
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
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Feature Toggles
                case '15':
                    echo "Enter display name for this node: ";
                    $key = 'name';
                    $rawInput = trim(fgets(STDIN));
                    if (empty($rawInput)) {
                        echo "Error: Display name cannot be empty\n";
                        return;
                    }
                    $value = $rawInput;
                    break;

                case '16':
                    echo "Enable auto-refresh for pending transactions? (yes/no): ";
                    $key = 'autoRefreshEnabled';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '17':
                    echo "Enable contact status pinging? (yes/no): ";
                    $key = 'contactStatusEnabled';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '18':
                    echo "Enable contact status sync on ping? (yes/no): ";
                    $key = 'contactStatusSyncOnPing';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '19':
                    echo "Enable auto chain drop propose? (yes/no): ";
                    $key = 'autoChainDropPropose';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '20':
                    echo "Enable auto chain drop accept? (yes/no): ";
                    $key = 'autoChainDropAccept';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '21':
                    echo "Enable auto chain drop accept balance guard? (yes/no): ";
                    $key = 'autoChainDropAcceptGuard';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '22':
                    echo "Enable API? (yes/no): ";
                    $key = 'apiEnabled';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '23':
                    echo "Enter API CORS allowed origins (empty to clear): ";
                    $key = 'apiCorsAllowedOrigins';
                    $value = trim(fgets(STDIN));
                    break;

                case '24':
                    echo "Enable rate limiting? (yes/no): ";
                    $key = 'rateLimitEnabled';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Backup & Logging
                case '25':
                    echo "Enable automatic daily database backups? (yes/no): ";
                    $key = 'autoBackupEnabled';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '26':
                    echo "Enter backup retention count (minimum 1): ";
                    $key = 'backupRetentionCount';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '27':
                    echo "Enter backup schedule hour (0-23): ";
                    $key = 'backupCronHour';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 0, 23, 'Backup hour');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '28':
                    echo "Enter backup schedule minute (0-59): ";
                    $key = 'backupCronMinute';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 0, 59, 'Backup minute');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '29':
                    echo "Enter log level (debug, info, warning, error): ";
                    $key = 'logLevel';
                    $validation = InputValidator::validateLogLevel(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '30':
                    echo "Enter log max entries (minimum 10): ";
                    $key = 'logMaxEntries';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 10);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Data Retention
                case '31':
                    echo "Enter delivery retention days (minimum 1): ";
                    $key = 'cleanupDeliveryRetentionDays';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '32':
                    echo "Enter DLQ retention days (minimum 1): ";
                    $key = 'cleanupDlqRetentionDays';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '33':
                    echo "Enter held TX retention days (minimum 1): ";
                    $key = 'cleanupHeldTxRetentionDays';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '34':
                    echo "Enter RP2P retention days (minimum 1): ";
                    $key = 'cleanupRp2pRetentionDays';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '35':
                    echo "Enter metrics retention days (minimum 1): ";
                    $key = 'cleanupMetricsRetentionDays';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Rate Limiting
                case '36':
                    echo "Enter P2P rate limit per minute (minimum 1): ";
                    $key = 'p2pRateLimitPerMinute';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '37':
                    echo "Enter rate limit max attempts (minimum 1): ";
                    $key = 'rateLimitMaxAttempts';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '38':
                    echo "Enter rate limit window in seconds (minimum 1): ";
                    $key = 'rateLimitWindowSeconds';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '39':
                    echo "Enter rate limit block duration in seconds (minimum 1): ";
                    $key = 'rateLimitBlockSeconds';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Display
                case '40':
                    echo "Enter new maximum of balance/transaction output lines to display (0 = unlimited): ";
                    $key = 'maxOutput';
                    $read = trim(fgets(STDIN));
                    if (!is_numeric($read) || intval($read) < 0) {
                        echo "Error: Max output must be a non-negative integer (0 = unlimited)\n";
                        return;
                    }
                    $value = intval($read);
                    break;

                case '41':
                    echo "Enter date format (e.g., Y-m-d H:i:s): ";
                    $key = 'displayDateFormat';
                    $validation = InputValidator::validateDateFormat(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '42':
                    echo "Enter currency decimals (0-8): ";
                    $key = 'displayCurrencyDecimals';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 0, 8, 'Currency decimals');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '43':
                    echo "Enter recent transactions limit (minimum 1): ";
                    $key = 'displayRecentTransactionsLimit';
                    $validation = InputValidator::validatePositiveInteger(trim(fgets(STDIN)), 1);
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                // Currency Management
                case '44':
                    echo "Current allowed currencies: " . implode(', ', UserContext::getInstance()->getAllowedCurrencies()) . "\n";
                    echo "Enter currencies (comma-separated, e.g., USD,EUR): ";
                    $key = 'allowedCurrencies';
                    $input = trim(fgets(STDIN));
                    $currencies = array_filter(array_map('trim', explode(',', strtoupper($input))));
                    foreach ($currencies as $c) {
                        $validation = InputValidator::validateAllowedCurrency($c);
                        if (!$validation['valid']) {
                            echo "Error for {$c}: " . $validation['error'] . "\n";
                            return;
                        }
                    }
                    $value = implode(',', $currencies);
                    break;

                // Tor Circuit Health
                case '45':
                    echo "Enter consecutive Tor failures before cooldown (1-10): ";
                    $key = 'torCircuitMaxFailures';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 1, 10, 'Tor circuit max failures');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '46':
                    echo "Enter Tor circuit cooldown duration in seconds (60-3600): ";
                    $key = 'torCircuitCooldownSeconds';
                    $validation = InputValidator::validateIntRange(trim(fgets(STDIN)), 60, 3600, 'Tor circuit cooldown');
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '47':
                    echo "Fall back to HTTP/HTTPS when Tor fails? (yes/no): ";
                    $key = 'torFailureTransportFallback';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '48':
                    echo "Require encrypted (HTTPS) fallback when Tor fails? (yes/no): ";
                    $key = 'torFallbackRequireEncrypted';
                    $validation = InputValidator::validateBoolean(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
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
            'name' => $this->currentUser->getName(),
            'default_currency' => $this->currentUser->getDefaultCurrency(),
            'minimum_fee_amount' => $this->currentUser->getMinimumFee(),
            'minimum_fee_currency' => $this->currentUser->getDefaultCurrency(),
            'default_fee_percent' => $this->currentUser->getDefaultFee(),
            'maximum_fee_percent' => $this->currentUser->getMaxFee(),
            'default_credit_limit' => $this->currentUser->getDefaultCreditLimit(),
            'max_p2p_level' => $this->currentUser->getMaxP2pLevel(),
            'p2p_expiration_seconds' => $this->currentUser->getP2pExpirationTime(),
            'direct_tx_expiration' => $this->currentUser->getDirectTxExpirationTime(),
            'max_output_lines' => $this->currentUser->getMaxOutput(),
            'default_transport_mode' => $this->currentUser->getDefaultTransportMode(),
            'hostname' => $this->currentUser->getHttpAddress(),
            'hostname_secure' => $this->currentUser->getHttpsAddress(),
            'trusted_proxies' => $this->currentUser->getTrustedProxies(),
            'auto_refresh_enabled' => $this->currentUser->getAutoRefreshEnabled(),
            'auto_backup_enabled' => $this->currentUser->getAutoBackupEnabled(),
            'auto_accept_transaction' => $this->currentUser->getAutoAcceptTransaction(),
            // Feature toggles
            'contact_status_enabled' => $this->currentUser->getContactStatusEnabled(),
            'contact_status_sync_on_ping' => $this->currentUser->getContactStatusSyncOnPing(),
            'auto_chain_drop_propose' => $this->currentUser->getAutoChainDropPropose(),
            'auto_chain_drop_accept' => $this->currentUser->getAutoChainDropAccept(),
            'auto_chain_drop_accept_guard' => $this->currentUser->getAutoChainDropAcceptGuard(),
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
            'tor_circuit_max_failures' => $this->currentUser->getTorCircuitMaxFailures(),
            'tor_circuit_cooldown_seconds' => $this->currentUser->getTorCircuitCooldownSeconds(),
            'tor_failure_transport_fallback' => $this->currentUser->isTorFailureTransportFallback(),
            'tor_fallback_require_encrypted' => $this->currentUser->isTorFallbackRequireEncrypted(),
            // Display
            'display_date_format' => $this->currentUser->getDisplayDateFormat(),
            'display_currency_decimals' => $this->currentUser->getDisplayCurrencyDecimals(),
            'display_recent_transactions_limit' => $this->currentUser->getDisplayRecentTransactionsLimit(),
            // Currency management
            'allowed_currencies' => $this->currentUser->getAllowedCurrencies(),
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
            echo "\tDirect TX delivery expiration: " . ($settings['direct_tx_expiration'] === 0 ? 'no expiry' : $settings['direct_tx_expiration'] . " seconds") . "\n";
            echo "\tDefault transport mode: " . $settings['default_transport_mode'] . "\n";
            echo "\tHTTP transport timeout: " . $settings['http_transport_timeout_seconds'] . "s\n";
            echo "\tTor transport timeout: " . $settings['tor_transport_timeout_seconds'] . "s\n";
            echo "\tTor circuit max failures: " . $settings['tor_circuit_max_failures'] . "\n";
            echo "\tTor circuit cooldown: " . $settings['tor_circuit_cooldown_seconds'] . "s\n";
            echo "\tTor failure transport fallback: " . ($settings['tor_failure_transport_fallback'] ? 'enabled' : 'disabled') . "\n";
            echo "\tTor fallback require encrypted: " . ($settings['tor_fallback_require_encrypted'] ? 'enabled' : 'disabled') . "\n";
            if ($settings['hostname']) echo "\tHostname: " . $settings['hostname'] . "\n";
            if ($settings['hostname_secure']) echo "\tHostname (secure): " . $settings['hostname_secure'] . "\n";
            echo "\tTrusted proxies: " . ($settings['trusted_proxies'] ?: '(none)') . "\n";
            echo "\tAuto-accept P2P transactions: " . ($settings['auto_accept_transaction'] ? 'enabled' : 'disabled') . "\n";
            echo "\n  Feature Toggles:\n";
            if ($settings['name']) echo "\tDisplay name: " . $settings['name'] . "\n";
            echo "\tAuto-refresh transactions: " . ($settings['auto_refresh_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tContact status pinging: " . ($settings['contact_status_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tContact status sync on ping: " . ($settings['contact_status_sync_on_ping'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto chain drop propose: " . ($settings['auto_chain_drop_propose'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto chain drop accept: " . ($settings['auto_chain_drop_accept'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAuto chain drop accept guard: " . ($settings['auto_chain_drop_accept_guard'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAPI enabled: " . ($settings['api_enabled'] ? 'enabled' : 'disabled') . "\n";
            echo "\tAPI CORS origins: " . ($settings['api_cors_allowed_origins'] ?: '(none)') . "\n";
            echo "\tRate limiting: " . ($settings['rate_limit_enabled'] ? 'enabled' : 'disabled') . "\n";
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
            echo "\n  Currency Management:\n";
            echo "\tAllowed currencies: " . (is_array($settings['allowed_currencies']) ? implode(', ', $settings['allowed_currencies']) : ($settings['allowed_currencies'] ?: '(all)')) . "\n";
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
     * on the next nginx reload or can be done immediately.
     *
     * @param string $newHostname The new hostname to use for the certificate
     * @param CliOutputManager|null $output Optional output manager for status messages
     */
    private function regenerateSslCertificate(string $newHostname, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $sslCertPath = '/etc/nginx/ssl/server.crt';
        $sslKeyPath = '/etc/nginx/ssl/server.key';

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

            if (file_exists($csrPath)) { unlink($csrPath); }

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

        if (file_exists($configPath)) { unlink($configPath); }

        // Set proper permissions
        if (file_exists($sslKeyPath)) {
            chmod($sslKeyPath, 0600);
        }
        if (file_exists($sslCertPath)) {
            chmod($sslCertPath, 0644);
        }

        // Reload nginx to use new certificate
        shell_exec('nginx -s reload 2>/dev/null');

        if (!$output->isJsonMode()) {
            echo "nginx reloaded to use new certificate.\n";
        }
    }
}
