<?php
# Copyright 2025

require_once __DIR__ . '/../utils/InputValidator.php';

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
    }

    /**
     * Handler for (CLI) input changes to user settings
     *
     * @param array $argv The (CLI) input data
    */
    public function changeSettings(array $argv) {
        // Check if command line based or user input based
        if(isset($argv[2])){
            if(strtolower($argv[2]) === 'defaultfee'){
                $key = 'defaultFee';
                $validation = InputValidator::validateFeePercent($argv[3]);
                if (!$validation['valid']) {
                    echo "Error: " . $validation['error'] . "\n";
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'defaultcurrency'){
                $key = 'defaultCurrency';
                $validation = InputValidator::validateCurrency($argv[3]);
                if (!$validation['valid']) {
                    echo "Error: " . $validation['error'] . "\n";
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxfee'){
                $key = 'maxFee';
                $validation = InputValidator::validateFeePercent($argv[3]);
                if (!$validation['valid']) {
                    echo "Error: " . $validation['error'] . "\n";
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'maxp2pLevel'){
                $key = 'maxP2pLevel';
                $validation = InputValidator::validateRequestLevel($argv[3]);
                if (!$validation['valid']) {
                    echo "Error: " . $validation['error'] . "\n";
                    return;
                }
                $value = $validation['value'];
            } elseif(strtolower($argv[2]) === 'p2pexpiration'){
                $key = 'p2pExpiration';
                $validation = InputValidator::validateTimestamp($argv[3]);
                if (!$validation['valid']) {
                    echo "Error: " . $validation['error'] . "\n";
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
                        echo "Error: Max output must be a positive integer or 'all'\n";
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
                    echo "Error: " . $validation['error'] . "\n";
                    return;
                }
                $value = $validation['value'];
            } else{
                echo "Setting provided does not exist. No changes made.\n";
                return;
            }
        } else{

            // Display current settings
            $this->displayCurrentSettings();
            
            // Prompt user for which setting they want to change
            echo "Select the setting you want to change:\n";
            echo "\t1. Default Fee\n";
            echo "\t2. Default Currency\n";
            echo "\t3. Maximum Fee\n";
            echo "\t4. Maximum Peer to Peer Level\n";
            echo "\t5. Default Peer to Peer Expiration\n";
            echo "\t6. Maximum lines of Balance/Transaction output\n";
            echo "\t7. Access Mode\n";
            echo "\t8. Default Transport Type\n";
            echo "\t9. Hostname\n";
            echo "\t0. Cancel\n";

            // Read user input
            $setting_choice = trim(fgets(STDIN));
            
            switch($setting_choice) {
                case '1':
                    echo "Enter new default fee percentage: ";
                    $key = 'defaultFee';
                    $validation = InputValidator::validateFeePercent(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '2':
                    echo "Enter new default currency (e.g., USD): ";
                    $key = 'defaultCurrency';
                    $validation = InputValidator::validateCurrency(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '3':
                    echo "Enter new maximum fee percentage: ";
                    $key = 'maxFee';
                    $validation = InputValidator::validateFeePercent(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '4':
                    echo "Enter new Maximum Peer to Peer Level: ";
                    $key = 'maxP2pLevel';
                    $validation = InputValidator::validateRequestLevel(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '5':
                    echo "Enter new Peer to Peer Expiration (in seconds): ";
                    $key = 'p2pExpiration';
                    $validation = InputValidator::validateTimestamp(trim(fgets(STDIN)));
                    if (!$validation['valid']) {
                        echo "Error: " . $validation['error'] . "\n";
                        return;
                    }
                    $value = $validation['value'];
                    break;

                case '6':
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

                case '7':
                    echo "Enter access mode (0 for Network Enabled, 1 for LocalHost Only): ";
                    $key = 'localhostOnly';
                    $value = (trim(fgets(STDIN)) === '1');
                    break;

                case '8':
                    echo "Enter new default transport type (e.g. http, tor): ";
                    $key = 'defaultTransportMode';
                    $value = strtolower(trim(fgets(STDIN)));
                    break;

                case '9':
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
        echo "Setting updated successfully.\n";
    }

    /**
     * Display current settings of user in the CLI
     *
    */
    public function displayCurrentSettings() {
        echo "Current Settings:\n";
        echo "\tDefault fee: " . $this->currentUser->getDefaultFee() ."%\n";
        echo "\tDefault currency: " . $this->currentUser->getDefaultCurrency() . "\n";
        echo "\tMaximum Fee: " . $this->currentUser->getMaxFee() . "%\n";
        echo "\tMaximum Peer to Peer Level: " .  $this->currentUser->getMaxP2pLevel() . "\n";
        echo "\tDefault Peer to Peer Expiration: " .  $this->currentUser->getP2pExpirationTime() . " seconds\n";
        echo "\tDefault Maximum lines of balance output: " .  $this->currentUser->getMaxOutput() . "\n";
        echo "\tAccess Mode: " . ($this->currentUser->isLocalhostOnly() ? "Local Access Only" : "Network Authorized") . "\n";
        echo "\tDefault Transport Mode: " . $this->currentUser->getDefaultTransportMode() . "\n";
    }

    /**
     * Display available commands to user in the CLI
     *
     * @param array $argv The CLI input data
    */
    public function displayHelp(array $argv) {
        if(isset($argv[2])){
            echo "Command:\n";
            if(strtolower($argv[2]) === 'defaultfee'){
            } elseif(strtolower($argv[2]) === 'add'){
                echo "\tadd [address] [name] [fee] [credit] [currency] - Add a new contact.\n";
            } elseif(strtolower($argv[2]) === 'viewcontact'){
                echo "\tviewcontact [address/name] - View contact information.\n";
            } elseif(strtolower($argv[2]) === 'update'){
                echo "\tupdate [address/name] [all/name/fee/credit] ([name]) ([fee]) ([credit]) - Update a contact.\n";
            } elseif(strtolower($argv[2]) === 'block'){
                echo "\block [address/name] - Block a contact.\n";
            } elseif(strtolower($argv[2]) === 'delete'){
                echo "\unblock [address/name] - Unblock a contact.\n";
            } elseif(strtolower($argv[2]) === 'delete'){
                echo "\tdelete [address/name] - Delete a contact.\n";
            } elseif(strtolower($argv[2]) === 'send'){
                echo "\tsend [address/name] [amount] [currency] - Send an eIOU.\n";
            } elseif(strtolower($argv[2]) === 'viewbalances'){
                echo "\tviewbalances ([address/name]) - View eIOU balance(s).\n";
            } elseif(strtolower($argv[2]) === 'history'){
                echo "\thistory ([address/name]) - View transaction history for contacts, (default: all contacts).\n";
            } elseif(strtolower($argv[2]) === 'help'){
                echo "\thelp - Display this help information.\n";
            } elseif(strtolower($argv[2]) === 'viewsettings'){
                echo "\tviewsettings - View current settings.\n";
            } elseif(strtolower($argv[2]) === 'changesettings'){
                echo "\tchangesettings - Change settings.\n";
            } elseif(strtolower($argv[2]) === 'generate'){
                echo "\tgenerate - Generate a new wallet.\n";
            } else{
                echo "\tcommand does not exist.\n";
            }
        } else{
            echo "Available commands:\n";
            echo "\tadd [address] [name] [fee] [credit] [currency] - Add a new contact.\n";
            echo "\tviewcontact [address/name] - View contact information.\n";
            echo "\tupdate [address/name] [all/name/fee/credit] ([name]) ([fee]) ([credit]) - Update a contact.\n";
            echo "\tblock [address/name] - Block a contact.\n";
            echo "\tunblock [address/name] - Unblock a contact.\n";
            echo "\tdelete [address/name] - Delete a contact.\n";
            echo "\tsend [address/name] [amount] [currency] - Send an eIOU.\n";
            echo "\tviewbalances ([address/name]) - View eIOU balance(s).\n";
            echo "\thistory ([address/name]) - View transaction history for contacts, (default: all contacts).\n";
            echo "\thelp - Display this help information.\n";
            echo "\tviewsettings - View current settings.\n";
            echo "\tchangesettings - Change settings.\n";
            echo "\tgenerate - Generate a new wallet.\n";
        }
    }

    /**
     * Display user information to user in the CLI
     *
     * @param array $argv The CLI input data
    */
    public function displayUserInfo(array $argv) {    
       // Define limit of output displayed
        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $displayLimit = $argv[3];                   
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

        echo "User Information:\n";
        
        // Locators array
        $locators = $this->currentUser->getUserLocaters();
        
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

        if (isset($argv[2]) && strtolower($argv[2]) === 'detail'){
            // Get total sent and received by currency
            $balances = $this->balanceRepository->getUserBalance();
            if(isset($balances)){
                foreach($balances as $balance){
                    printf("\tTotal Balance %s : %s\n", $balance['currency'], number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2));
                    if (isset($argv[2]) && $argv[2] === 'detail') { 
                        $this->viewBalanceQuery("received","from",$this->transactionRepository->getReceivedUserTransactionsCurrency($balance['currency'],PHP_INT_MAX), $displayLimit); // Received Balances
                        $this->viewBalanceQuery("sent","to",$this->transactionRepository->getSentUserTransactionsCurrency($balance['currency'],PHP_INT_MAX), $displayLimit); // Sent Balances
                    }
                }
            } else{
                printf("\tNo balances available yet.\n");
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
                    $this->transportUtility->truncateAddress($res['counterparty'],30), 
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
    */
    public function viewBalances(array $argv) {
        // Check if an address or name is provided
        $contactResult = null;
        if (isset($argv[2])) {
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
            echo "Address/Name unknown or not provided, displaying all balances.\n";
            $contacts = $this->contactRepository->getAllContacts();
        }
        $balances = $this->balanceRepository->getUserBalance();
        foreach($balances as $balance){
            printf("%s %s, Balance %s : %.2f\n", 'me', $additionalInfo, $balance['currency'], number_format($balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, 2)); 
            if ($contactResult) {
                $contactBalances= $this->balanceRepository->getContactBalancesCurrency($contactResult['pubkey'],$balance['currency']);
                foreach($contactBalances as $contactBalance){
                    printf("\t%s (%s), Balance %s : %.2f %s\n", $contactResult['name'], $contactResult['tor'] ?? $contactResult['http'], $contactBalance['direction'], $contactBalance['balance'], $contactBalance['currency']);
                }
                return;
            } else{
                if(!$contacts){
                    echo "\tNo Contacts exist, so no contact balances can be displayed.\n";
                    continue;
                } else{
                    foreach($contacts as $contact){
                        $contactBalances = $this->balanceRepository->getContactBalancesCurrency($contact['pubkey'], $balance['currency']);
                        foreach($contactBalances as $contactBalance){
                            printf("\t%s (%s), Balance %s : %.2f %s\n", $contact['name'], $contact['http'] ?? $contact['tor'], $contactBalance['direction'], $contactBalance['balance'], $contactBalance['currency']);
                        }
                    }
                }    
            }    
        }     
    }

    /**
     * Display all transaction history in pretty print 'table' to user in the CLI
     *
     * @param array $argv The CLI input data
    */
    public function viewTransactionHistory(array $argv) {
        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $displayLimit = $argv[3];                   
        } else{
            $displayLimit = $this->currentUser->getMaxOutput();
        }

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
            if ($contactResult) {
                $sentTransactions = $this->transactionRepository->getSentUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                $receivedTransactions = $this->transactionRepository->getReceivedUserTransactionsAddress($contactResult[$transportIndex],PHP_INT_MAX);
                $this->displayHistory($sentTransactions, 'sent', $displayLimit);
                $this->displayHistory($receivedTransactions, 'received', $displayLimit);
                return;
            }
        }
        // If no address supplied, get all transactions
        $sentTransactions = $this->transactionRepository->getSentUserTransactions(PHP_INT_MAX);
        $receivedTransactions = $this->transactionRepository->getReceivedUserTransactions(PHP_INT_MAX);
        $this->displayHistory($sentTransactions, 'sent', $displayLimit);
        $this->displayHistory($receivedTransactions, 'received', $displayLimit); 
    }

    /**
     * Helper to display transaction history (sent or received) in pretty print 'table' to user in the CLI
     *
     * @param array $transactions The formatted transaction data
     * @param string $direction received/send
     * @param int $displayLimit The limit of output displayed
    */
    public function displayHistory(array $transactions, string $direction, int $displayLimit){
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
            
        $countResults = count($transactions);
        $countrows = 1;
        foreach ($transactions as $tx) {
            $contactName = $this->contactRepository->lookupNameByAddress($tx['counterparty']);
            echo str_pad($tx['date'], 26, ' ') . " | " . 
                str_pad($tx['type'], 9, ' ') . " | " . 
                str_pad($contactName . " (" . $this->transportUtility->truncateAddress($tx['counterparty'],82-(strlen($contactName)+2)) . ")", 82, ' ') . " | " . 
                str_pad($tx['amount'], 10, ' ') . " | " . 
                str_pad($tx['currency'], 10, ' ') . "\n" ; 
                        
            if($displayLimit !== 'all' && ($countrows >= $displayLimit)){
                break;
            } 
            $countrows += 1;        
        }
        echo "-------------------------------------------\n";
        if($displayLimit === 'all'){
            $displayLimit = $countResults;
        } elseif($displayLimit > $countResults){
            $displayLimit = $countResults;
        }
        echo "Displaying " . $displayLimit .  " out of " . $countResults . " total transactions.\n";
    }
}