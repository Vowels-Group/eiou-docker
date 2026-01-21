<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../core/ErrorCodes.php';
require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../cli/CliOutputManager.php';

/**
 * Cli Service
 *
 * Handles all business logic for cli management.
 *
 * @package Services
 *
 * SECTION INDEX:
 * - Properties & Constructor............. Line ~26
 * - Settings Management.................. Line ~95
 * - Settings & Help Display.............. Line ~391
 * - User Information..................... Line ~776
 * - Balance Operations................... Line ~886
 * - Transaction History.................. Line ~1055
 * - SSL Certificate Management........... Line ~1187
 */
class CliService {

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
            } elseif(strtolower($argv[2]) === 'hostname'){
                $key = 'hostname';
                $validation = InputValidator::validateHostname($argv[3]);
                if (!$validation['valid']) {
                    $output->validationError('hostname', $validation['error']);
                    return;
                }
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
            echo "\t2. Minimum ree amount\n";
            echo "\t3. Default fee percentage\n";
            echo "\t4. Maximum fee percentage\n";
            echo "\t5. Default credit limit\n";
            echo "\t6. Maximum peer to peer Level\n";
            echo "\t7. Default peer to peer Expiration\n";
            echo "\t8. Maximum lines of balance/transaction output\n";
            echo "\t9. Default transport type\n";
            echo "\t10. Hostname\n";
            echo "\t11. Auto-refresh transactions\n";
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
                    echo "Enter new maximum of balance/transaction output lines to display: ";
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
        } else{
            $configFile = 'defaultconfig.json';
        }

        $config_content = json_decode(file_get_contents('/etc/eiou/' . $configFile),true);
        $config_content[$key] = $value;

        // Also save hostname_secure when hostname is updated
        if ($key == 'hostname') {
            $config_content['hostname_secure'] = $hostnameSecure;
        }

        file_put_contents('/etc/eiou/'. $configFile, json_encode($config_content,true), LOCK_EX);

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
            'auto_refresh_enabled' => $this->currentUser->getAutoRefreshEnabled()
        ];

        if ($output->isJsonMode()) {
            $output->settings($settings);
        } else {
            echo "Current Settings:\n";
            echo "\tDefault currency: " . $settings['default_currency'] . "\n";
            echo "\tMinimum fee amount: " . $settings['minimum_fee_amount'] . " " . $settings['minimum_fee_currency'] ."\n";
            echo "\tDefault fee percent: " . $settings['default_fee_percent'] ."%\n";
            echo "\tMaximum fee percent: " . $settings['maximum_fee_percent'] . "%\n";
            echo "\tDefault credit limit: " . $settings['default_credit_limit'] ."\n";
            echo "\tMaximum peer to peer Level: " .  $settings['max_p2p_level'] . "\n";
            echo "\tDefault peer to peer Expiration: " .  $settings['p2p_expiration_seconds'] . " seconds\n";
            echo "\tDefault maximum lines of balance output: " .  $settings['max_output_lines'] . "\n";
            echo "\tDefault transport mode: " . $settings['default_transport_mode'] . "\n";
            echo "\tAuto-refresh transactions: " . ($settings['auto_refresh_enabled'] ? 'enabled' : 'disabled') . "\n";
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
                    'address' => ['type' => 'required', 'description' => 'Contact address (HTTP, HTTPS, or Tor)'],
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
                'description' => 'Display detailed help information',
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
                ],
                'available_settings' => [
                    'defaultFee' => 'Default fee percentage for transactions',
                    'defaultCreditLimit' => 'Default credit limit for new contacts',
                    'defaultCurrency' => 'Default currency code (e.g., USD)',
                    'minFee' => 'Minimum fee amount',
                    'maxFee' => 'Maximum fee percentage',
                    'maxP2pLevel' => 'Maximum peer-to-peer routing level',
                    'p2pExpiration' => 'Peer-to-peer request expiration time (seconds)',
                    'maxOutput' => 'Maximum lines of output to display (integer or "all")',
                    'defaultTransportMode' => 'Default transport type (http, https, tor)',
                    'autoRefreshEnabled' => 'Enable auto-refresh for pending transactions (true/false)',
                    'hostname' => 'Node hostname (e.g., http://alice). Setting this automatically derives hostname_secure (HTTPS version)'
                ]
            ],
            'generate' => [
                'description' => 'Generate a new wallet or restore from seed phrase',
                'usage' => 'generate [restore <24 words>] [restore-file <filepath>] [hostname]',
                'arguments' => [
                    'restore' => ['type' => 'optional', 'description' => 'Restore wallet from BIP39 seed phrase (24 words)'],
                    'restore-file' => ['type' => 'optional', 'description' => 'Restore wallet from seed phrase stored in file (more secure)'],
                    'hostname' => ['type' => 'optional', 'description' => 'HTTP/S hostname for the wallet']
                ],
                'examples' => [
                    'generate' => 'Create new wallet with seed phrase',
                    'generate restore word1 word2 ... word24' => 'Restore from 24-word seed',
                    'generate restore-file /path/to/seedphrase' => 'Restore from seed phrase file (avoids process list exposure)'
                ]
            ],
            'sync' => [
                'description' => 'Synchronize data (contacts, transactions, balances)',
                'usage' => 'sync ([type])',
                'arguments' => [
                    'type' => ['type' => 'optional', 'description' => 'Sync type: contacts, transactions, or balances. If omitted, syncs all.']
                ],
                'examples' => [
                    'sync' => 'Sync all (contacts, transactions, and balances)',
                    'sync contacts' => 'Sync only contacts',
                    'sync transactions' => 'Sync only transactions',
                    'sync balances' => 'Recalculate balances from transaction history'
                ]
            ],
            'out' => [
                'description' => 'Process outgoing message queue (pending transactions)',
                'usage' => 'out',
                'arguments' => [],
                'note' => 'Requires EIOU_TEST_MODE=true'
            ],
            'in' => [
                'description' => 'Process incoming/held transactions',
                'usage' => 'in',
                'arguments' => [],
                'note' => 'Requires EIOU_TEST_MODE=true'
            ],
            'ping' => [
                'description' => 'Ping a contact to check their online status and chain validity',
                'usage' => 'ping [address/name]',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Contact address or name to ping']
                ]
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
                    'wallet:read' => 'Read wallet balance and transactions',
                    'wallet:send' => 'Send transactions',
                    'contacts:read' => 'List and view contacts',
                    'contacts:write' => 'Add, update, delete contacts',
                    'system:read' => 'View system status and metrics',
                    'admin' => 'Full administrative access',
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
                        'POST /api/v1/wallet/send' => 'Send transaction',
                        'GET /api/v1/wallet/transactions' => 'Transaction history',
                        'GET /api/v1/contacts' => 'List contacts',
                        'POST /api/v1/contacts' => 'Add contact',
                        'GET /api/v1/system/status' => 'System status'
                    ]
                ]
            ],
            'shutdown' => [
                'description' => 'Shutdown the application gracefully',
                'usage' => 'shutdown',
                'arguments' => []
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

                    // Show detailed help for apikey command
                    if ($specificCommand === 'apikey') {
                        $this->showApiKeyDetailedHelp();
                    }
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
  GET  /api/v1/wallet/balance      - Get wallet balances
  POST /api/v1/wallet/send         - Send transaction
  GET  /api/v1/wallet/transactions - Transaction history
  GET  /api/v1/contacts            - List contacts
  POST /api/v1/contacts            - Add contact
  GET  /api/v1/system/status       - System status

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
                            'counterparty_name' => $this->contactRepository->lookupNameByAddress($this->transportUtility->determineTransportType($tx['counterparty']), $tx['counterparty']),
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

        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $displayLimit = $argv[3];
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
}