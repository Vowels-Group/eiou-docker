<?php
# Copyright 2025

require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../cli/CliOutputManager.php';

/**
 * Cli Service
 *
 * Handles all business logic for cli management.
 *
 * @package Services
 */
class CliService {
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
                $validation = InputValidator::validateTimestamp($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('p2pExpiration', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxoutput'){
                $key = 'maxOutput';
                if($argv[3] === 'all'){
                    $value = 'all';
                } else{
                    // Validate as positive integer using Security::sanitizeInt
                    if (!is_numeric($argv[3]) || intval($argv[3]) <= 0) {
                        $output->validationError('maxOutput', 'Max output must be a positive integer or \'all\'');
                        return;
                    }
                    $value = intval($argv[3]);
                }
            } elseif(strtolower($argv[2]) === 'localhostonly'){
                $key = 'localhostOnly';
                $value = ($argv[3] === '1');
            } elseif(strtolower($argv[2]) === 'defaulttransportmode'){
                $key = 'defaultTransportMode';
                $value = strtolower($argv[3]);
            } elseif(strtolower($argv[2]) === 'hostname'){
                $key = 'hostname';
                $validation = InputValidator::validateHostname($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('hostname', $validation['error']);
                    return;
                }
                $value = $validation['value'];
            } else{
                $output->error('Setting provided does not exist. No changes made.', 'INVALID_SETTING', 400);
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
            echo "\t1. Default Currency\n";
            echo "\t2. Minimum Fee amount\n";
            echo "\t3. Default Fee percentage\n";
            echo "\t4. Maximum Fee percentage\n";
            echo "\t5. Maximum Peer to Peer Level\n";
            echo "\t6. Default Peer to Peer Expiration\n";
            echo "\t7. Maximum lines of Balance/Transaction output\n";
            echo "\t8. Access Mode\n";
            echo "\t9. Default Transport Type\n";
            echo "\t10. Hostname\n";
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
                    echo "Enter new Maximum Peer to Peer Level: ";
                    $key = 'maxP2pLevel';
                    $validation = InputValidator::validateRequestLevel(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '6':
                    echo "Enter new Peer to Peer Expiration (in seconds): ";
                    $key = 'p2pExpiration';
                    $validation = InputValidator::validateTimestamp(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '7':
                    echo "Enter new Maximum of Balance/Transaction output lines to display: ";
                    $key = 'maxOutput';
                    $read = trim(fgets(STDIN));
                    if($read === 'all'){
                        $value = 'all';
                    } else{
                        if (!is_numeric($read) || intval($read) <= 0) {
                            echo "Error: Max output must be a positive integer or 'all'\n";
                            return;
                        }
                        $value = intval($read);
                    }
                    break;

                case '8':
                    echo "Enter access mode (0 for Network Enabled, 1 for LocalHost Only): ";
                    $key = 'localhostOnly';
                    $value = (trim(fgets(STDIN)) === '1');
                    break;

                case '9':
                    echo "Enter new default transport type (e.g. http, tor): ";
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
        } else{
            $configFile = 'defaultconfig.json';
        }

        $config_content = json_decode(file_get_contents('/etc/eiou/' . $configFile),true);
        $config_content[$key] = $value;
        file_put_contents('/etc/eiou/'. $configFile, json_encode($config_content,true), LOCK_EX);

        $output->success('Setting updated successfully.', [
            'setting' => $key,
            'value' => $value,
            'config_file' => $configFile
        ]);
    }

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
            'max_p2p_level' => $this->currentUser->getMaxP2pLevel(),
            'p2p_expiration_seconds' => $this->currentUser->getP2pExpirationTime(),
            'max_output_lines' => $this->currentUser->getMaxOutput(),
            'access_mode' => $this->currentUser->isLocalhostOnly() ? 'localhost_only' : 'network_enabled',
            'default_transport_mode' => $this->currentUser->getDefaultTransportMode()
        ];

        if ($output->isJsonMode()) {
            $output->settings($settings);
        } else {
            echo "Current Settings:\n";
            echo "\tDefault currency: " . $settings['default_currency'] . "\n";
            echo "\tMinimum fee amount: " . $settings['minimum_fee_amount'] . " " . $settings['minimum_fee_currency'] ."\n";
            echo "\tDefault fee percent: " . $settings['default_fee_percent'] ."%\n";
            echo "\tMaximum Fee percent: " . $settings['maximum_fee_percent'] . "%\n";
            echo "\tMaximum Peer to Peer Level: " .  $settings['max_p2p_level'] . "\n";
            echo "\tDefault Peer to Peer Expiration: " .  $settings['p2p_expiration_seconds'] . " seconds\n";
            echo "\tDefault Maximum lines of balance output: " .  $settings['max_output_lines'] . "\n";
            echo "\tAccess Mode: " . ($this->currentUser->isLocalhostOnly() ? "Local Access Only" : "Network Authorized") . "\n";
            echo "\tDefault Transport Mode: " . $settings['default_transport_mode'] . "\n";
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
                'description' => 'Display user information',
                'usage' => 'info ([detail])',
                'arguments' => [
                    'detail' => ['type' => 'optional', 'description' => 'Show detailed balance information']
                ]
            ],
            'add' => [
                'description' => 'Add a new contact',
                'usage' => 'add [address] [name] [fee] [credit] [currency]',
                'arguments' => [
                    'address' => ['type' => 'required', 'description' => 'Contact address (HTTP or Tor)'],
                    'name' => ['type' => 'required', 'description' => 'Contact name'],
                    'fee' => ['type' => 'required', 'description' => 'Fee percentage'],
                    'credit' => ['type' => 'required', 'description' => 'Credit limit'],
                    'currency' => ['type' => 'required', 'description' => 'Currency code']
                ]
            ],
            'search' => [
                'description' => 'Search for contact',
                'usage' => 'search ([name])',
                'arguments' => [
                    'name' => ['type' => 'optional', 'description' => 'Contact name to search']
                ]
            ],
            'viewcontact' => [
                'description' => 'View contact information',
                'usage' => 'viewcontact [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name']
                ]
            ],
            'update' => [
                'description' => 'Update a contact',
                'usage' => 'update [address/name] [all/name/fee/credit] ([name]) ([fee]) ([credit])',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name'],
                    'field' => ['type' => 'required', 'description' => 'Field to update: all, name, fee, or credit'],
                    'values' => ['type' => 'optional', 'description' => 'New values for the field(s)']
                ]
            ],
            'block' => [
                'description' => 'Block a contact',
                'usage' => 'block [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to block']
                ]
            ],
            'unblock' => [
                'description' => 'Unblock a contact',
                'usage' => 'unblock [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to unblock']
                ]
            ],
            'delete' => [
                'description' => 'Delete a contact',
                'usage' => 'delete [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to delete']
                ]
            ],
            'send' => [
                'description' => 'Send an eIOU',
                'usage' => 'send [address/name] [amount] [currency]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Recipient address or name'],
                    'amount' => ['type' => 'required', 'description' => 'Amount to send'],
                    'currency' => ['type' => 'required', 'description' => 'Currency code']
                ]
            ],
            'viewbalances' => [
                'description' => 'View eIOU balance(s)',
                'usage' => 'viewbalances ([address/name])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name']
                ]
            ],
            'history' => [
                'description' => 'View transaction history for contacts',
                'usage' => 'history ([address/name])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name']
                ]
            ],
            'help' => [
                'description' => 'Display help information',
                'usage' => 'help ([command])',
                'arguments' => [
                    'command' => ['type' => 'optional', 'description' => 'Specific command to get help for']
                ]
            ],
            'viewsettings' => [
                'description' => 'View current settings',
                'usage' => 'viewsettings',
                'arguments' => []
            ],
            'changesettings' => [
                'description' => 'Change settings',
                'usage' => 'changesettings ([setting] [value])',
                'arguments' => [
                    'setting' => ['type' => 'optional', 'description' => 'Setting name to change'],
                    'value' => ['type' => 'optional', 'description' => 'New value for the setting']
                ]
            ],
            'generate' => [
                'description' => 'Generate a new wallet',
                'usage' => 'generate',
                'arguments' => []
            ]
        ];

        $specificCommand = isset($argv[2]) ? strtolower($argv[2]) : null;

        if ($output->isJsonMode()) {
            if ($specificCommand !== null) {
                if (isset($commands[$specificCommand])) {
                    $output->help([$specificCommand => $commands[$specificCommand]], $specificCommand);
                } else {
                    $output->error("Command '$specificCommand' does not exist", 'COMMAND_NOT_FOUND', 404);
                }
            } else {
                $output->help($commands);
            }
        } else {
            if ($specificCommand !== null) {
                echo "Command:\n";
                if (isset($commands[$specificCommand])) {
                    echo "\t" . $commands[$specificCommand]['usage'] . " - " . $commands[$specificCommand]['description'] . "\n";
                } else {
                    echo "\tcommand does not exist.\n";
                }
            } else {
                echo "Available commands:\n";
                foreach ($commands as $name => $cmd) {
                    echo "\t" . $cmd['usage'] . " - " . $cmd['description'] . "\n";
                }
            }
        }
    }

    /**
     * Display user information to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayUserInfo(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

       // Define limit of output displayed
        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $displayLimit = $argv[3];
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

        // Locators array
        $locators = $this->currentUser->getUserLocaters();

        // Build user info data structure
        $userInfo = [
            'locators' => $locators,
            'authentication_code' => $this->currentUser->getAuthCode(),
            'public_key' => $this->currentUser->getPublicKey()
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
                        'total_balance' => number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                        'received' => [],
                        'sent' => []
                    ];

                    // Get received transactions
                    $receivedTx = $this->transactionRepository->getReceivedUserTransactionsCurrency($balance['currency'], PHP_INT_MAX);
                    foreach ($receivedTx as $tx) {
                        $balanceData['received'][] = [
                            'date' => $tx['date'],
                            'counterparty_name' => $this->contactRepository->lookupNameByAddress($tx['counterparty']),
                            'counterparty_address' => $tx['counterparty'],
                            'amount' => $tx['amount'],
                            'currency' => $tx['currency']
                        ];
                    }

                    // Get sent transactions
                    $sentTx = $this->transactionRepository->getSentUserTransactionsCurrency($balance['currency'], PHP_INT_MAX);
                    foreach ($sentTx as $tx) {
                        $balanceData['sent'][] = [
                            'date' => $tx['date'],
                            'counterparty_name' => $this->contactRepository->lookupNameByAddress($tx['counterparty']),
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

            // Authentication code is from the config file
            echo "\tAuthentication Code: " . $this->currentUser->getAuthCode() . "\n";

            $pubkey = $this->currentUser->getPublicKey();
            // Public key is from the config file
            $readablePubKey = "\n\t\t" . str_replace("\n","\n\t\t",$pubkey);
            echo "\tPublic Key:" . $readablePubKey . "\n";

            if ($showDetails){
                // Get total sent and received by currency
                $balances = $this->balanceRepository->getUserBalance();
                if(isset($balances)){
                    foreach($balances as $balance){
                        printf("\tTotal Balance %s : %s\n", $balance['currency'], number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2));
                        $this->viewBalanceQuery("received","from",$this->transactionRepository->getReceivedUserTransactionsCurrency($balance['currency'],PHP_INT_MAX), $displayLimit);
                        $this->viewBalanceQuery("sent","to",$this->transactionRepository->getSentUserTransactionsCurrency($balance['currency'],PHP_INT_MAX), $displayLimit);
                    }
                } else{
                    printf("\tNo balances available yet.\n");
                }
            }
        }
    }

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
                    $this->contactRepository->lookupNameByAddress($res['counterparty']), 
                    $this->generalUtility->truncateAddress($res['counterparty'],30), 
                    $res['amount'], 
                    $res['currency']);
            if($displayLimit !== 'all' && ($countrows >= $displayLimit)){
                break;
            } 
            $countrows += 1;
        }
        if ($displayLimit === 'all' || $displayLimit > $countResults) {
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
            // Check if it's a HTTP or Tor address
            if ($this->transportUtility->isAddress($argv[2])) {
                $address = $argv[2];
                $transportIndex = $this->transportUtility->determineTransportType($address);
                if($this->contactRepository->contactExists($transportIndex, $address)){
                    $contactResult = $this->contactRepository->lookupByAddress($address);
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
                    $balanceData['user']['balances'][] = [
                        'currency' => $balance['currency'],
                        'total_balance' => number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2)
                    ];

                    if ($contactResult) {
                        $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contactResult['pubkey'], $balance['currency']);
                        foreach($contactBalances as $contactBalance){
                            $balanceData['contacts'][] = [
                                'name' => $contactResult['name'],
                                'address' => $contactResult['http'] ?? $contactResult['tor'],
                                'currency' => $contactBalance['currency'],
                                'received' => number_format($contactBalance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                                'sent' => number_format($contactBalance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2)
                            ];
                        }
                    } else if(isset($contacts)){
                        foreach($contacts as $contact){
                            $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                            foreach($contactBalances as $contactBalance){
                                $balanceData['contacts'][] = [
                                    'name' => $contact['name'],
                                    'address' => $contact['http'] ?? $contact['tor'],
                                    'currency' => $contactBalance['currency'],
                                    'received' => number_format($contactBalance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                                    'sent' => number_format($contactBalance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2)
                                ];
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
                    printf("%s %s, Balance %s : %.2f\n", 'me', $additionalInfo, $balance['currency'], number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2));
                    if ($contactResult) {
                        $contactBalances= $this->balanceRepository->getContactBalancesCurrency($contactResult['pubkey'],$balance['currency']);
                        foreach($contactBalances as $contactBalance){
                            printf("\t%s (%s), Balance (%s | %s): %.2f | %.2f %s\n",
                                $contactResult['name'],
                                $contactResult['http'] ?? $contactResult['tor'],
                                'received',
                                'sent',
                                number_format($contactBalance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                                number_format($contactBalance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                                $contactBalance['currency']
                            );
                        }
                        return;
                    } else{
                        if(!isset($contacts) || !$contacts){
                            echo "\tNo Contacts exist, so no contact balances can be displayed.\n";
                            continue;
                        } else{
                            foreach($contacts as $contact){
                                $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                                foreach($contactBalances as $contactBalance){
                                    printf("\t%s (%s), Balance (%s | %s): %.2f | %.2f %s\n",
                                        $contact['name'],
                                        $contact['http'] ?? $contact['tor'],
                                        'received',
                                        'sent',
                                        number_format($contactBalance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
                                        number_format($contactBalance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2),
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

    /**
     * Display all transaction history in pretty print 'table' to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function viewTransactionHistory(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $displayLimit = $argv[3];
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

        $contactResult = null;
        $transportIndex = null;

        // Check if an address or name is provided
        if (isset($argv[2])) {
            // First if it's an HTTP or Tor address
            if ($this->transportUtility->isAddress($argv[2])) {
                $address = $argv[2];
                $transportIndex = $this->transportUtility->determineTransportType($address);
                if($this->contactRepository->contactExists($transportIndex, $address)){
                    $contactResult = $this->contactRepository->lookupByAddress($address);
                }
            } else {
                // Check if the name yields an address
                $contactResult = $this->contactRepository->lookupByName($argv[2]);
            }
            if ($contactResult && $transportIndex) {
                $sentTransactions = $this->transactionRepository->getSentUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                $receivedTransactions = $this->transactionRepository->getReceivedUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                $this->displayHistory($sentTransactions, 'sent', $displayLimit, $output);
                $this->displayHistory($receivedTransactions, 'received', $displayLimit, $output);
                return;
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
     * @param int|string $displayLimit The limit of output displayed
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayHistory(array $transactions, string $direction, $displayLimit, ?CliOutputManager $output = null){
        $output = $output ?? CliOutputManager::getInstance();

        $countResults = count($transactions);

        if ($output->isJsonMode()) {
            // Calculate effective display limit
            $effectiveLimit = $displayLimit;
            if ($displayLimit === 'all') {
                $effectiveLimit = $countResults;
            } elseif ($displayLimit > $countResults) {
                $effectiveLimit = $countResults;
            }

            // Build transaction data for JSON
            $txData = [];
            $count = 0;
            foreach ($transactions as $tx) {
                if ($displayLimit !== 'all' && $count >= $displayLimit) {
                    break;
                }
                $txData[] = [
                    'timestamp' => $tx['date'],
                    'type' => $tx['type'],
                    'direction' => $direction,
                    'counterparty_name' => $this->contactRepository->lookupNameByAddress($tx['counterparty']),
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
                $contactName = $this->contactRepository->lookupNameByAddress($tx['counterparty']);
                echo str_pad($tx['date'], 26, ' ') . " | " .
                    str_pad($tx['type'], 9, ' ') . " | " .
                    str_pad($contactName . " (" . $this->generalUtility->truncateAddress($tx['counterparty'],82-(strlen($contactName)+2)) . ")", 82, ' ') . " | " .
                    str_pad($tx['amount'], 10, ' ') . " | " .
                    str_pad($tx['currency'], 10, ' ') . "\n" ;

                if($displayLimit !== 'all' && ($countrows >= $displayLimit)){
                    break;
                }
                $countrows += 1;
            }
            echo "-------------------------------------------\n";
            $effectiveLimit = $displayLimit;
            if($displayLimit === 'all'){
                $effectiveLimit = $countResults;
            } elseif($displayLimit > $countResults){
                $effectiveLimit = $countResults;
            }
            echo "Displaying " . $effectiveLimit .  " out of " . $countResults . " total transactions.\n";
        }
    }
}