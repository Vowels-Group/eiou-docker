<?php
/**
 * Bootstrap File
 *
 * Initializes the GUI application with dependency injection and autoloading.
 * This provides a clean, OOP architecture while maintaining backward compatibility
 * with the existing functions.php file.
 *
 * @package eIOUGUI
 * @author Hive Mind Collective
 * @copyright 2025
 */

// Register PSR-4 autoloader
spl_autoload_register(function ($class) {
    // Project namespace prefix
    $prefix = 'eIOUGUI\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require_once $file;
    }
});

// Import required classes
use eIOUGUI\Core\Session;
use eIOUGUI\Repositories\ContactRepository;
use eIOUGUI\Repositories\TransactionRepository;
use eIOUGUI\Services\ContactService;
use eIOUGUI\Controllers\ContactController;
use eIOUGUI\Controllers\TransactionController;
use eIOUGUI\Helpers\ViewHelper;
use eIOUGUI\Helpers\MessageHelper;

// Initialize session manager
$guiSession = new Session();

// Initialize repositories
$contactRepository = new ContactRepository();
$transactionRepository = new TransactionRepository();

// Initialize services
// Note: We use the global ServiceContainer's contactService for actual operations
$guiContactService = new ContactService($guiSession, $contactRepository);

// Get the original contact service from global ServiceContainer if available
$originalContactService = null;
if (class_exists('\ServiceContainer')) {
    $originalContactService = \ServiceContainer::getInstance()->getContactService();
}

// Initialize controllers
$contactController = new ContactController($guiSession, $originalContactService ?: $guiContactService);
$transactionController = new TransactionController($guiSession);

/**
 * Simple Service Container for GUI components
 *
 * Provides centralized access to all services, repositories, and helpers
 */
class GUIServiceContainer
{
    private static array $services = [];

    /**
     * Register a service
     *
     * @param string $name
     * @param object $service
     * @return void
     */
    public static function register(string $name, object $service): void
    {
        self::$services[$name] = $service;
    }

    /**
     * Get a service
     *
     * @param string $name
     * @return object|null
     */
    public static function get(string $name): ?object
    {
        return self::$services[$name] ?? null;
    }

    /**
     * Check if service exists
     *
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$services[$name]);
    }
}

// Register services in container
GUIServiceContainer::register('session', $guiSession);
GUIServiceContainer::register('contactRepository', $contactRepository);
GUIServiceContainer::register('transactionRepository', $transactionRepository);
GUIServiceContainer::register('contactService', $guiContactService);
GUIServiceContainer::register('contactController', $contactController);
GUIServiceContainer::register('transactionController', $transactionController);

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Contact actions
    if (in_array($action, ['addContact', 'acceptContact', 'deleteContact', 'blockContact', 'unblockContact', 'ublockContact', 'editContact'])) {
        $contactController->routeAction();
    }

    // Transaction actions
    if (in_array($action, ['sendEIOU'])) {
        $transactionController->routeAction();
    }
}

// Handle GET requests for update checking
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
    $transactionController->routeAction();
}

// Backward compatibility functions
// These allow the existing layout files to work without modification

if (!function_exists('truncateAddress')) {
    /**
     * Truncate address for display
     *
     * @param string $address
     * @param int $length
     * @return string
     */
    function truncateAddress(string $address, int $length = 10): string
    {
        return ViewHelper::truncateAddress($address, $length);
    }
}

if (!function_exists('currencyOutputConversion')) {
    /**
     * Convert currency output based on currency type
     *
     * @param float|int $value
     * @param string $currency
     * @return float|int
     */
    function currencyOutputConversion($value, string $currency)
    {
        return ViewHelper::currencyOutputConversion($value, $currency);
    }
}

if (!function_exists('contactConversion')) {
    /**
     * Convert contacts array with balances for output
     *
     * @param array $contacts
     * @return array
     */
    function contactConversion(array $contacts): array
    {
        global $user;
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return ViewHelper::contactConversion($contacts, $user['public'], $transactionRepository);
    }
}

if (!function_exists('getUserTotalBalance')) {
    /**
     * Get user total balance formatted
     *
     * @return string
     */
    function getUserTotalBalance(): string
    {
        global $user;
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return ViewHelper::getUserTotalBalance($user['public'], $transactionRepository);
    }
}

if (!function_exists('getAcceptedContacts')) {
    /**
     * Get accepted contacts
     *
     * @return array
     */
    function getAcceptedContacts(): array
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getAcceptedContacts();
    }
}

if (!function_exists('getPendingContacts')) {
    /**
     * Get pending contacts
     *
     * @return array
     */
    function getPendingContacts(): array
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getPendingContacts();
    }
}

if (!function_exists('getUserPendingContacts')) {
    /**
     * Get user pending contacts
     *
     * @return array
     */
    function getUserPendingContacts(): array
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getUserPendingContacts();
    }
}

if (!function_exists('getBlockedContacts')) {
    /**
     * Get blocked contacts
     *
     * @return array
     */
    function getBlockedContacts(): array
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getBlockedContacts();
    }
}

if (!function_exists('getAllContacts')) {
    /**
     * Get all contacts
     *
     * @return array
     */
    function getAllContacts(): array
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getAllContacts();
    }
}

if (!function_exists('getContactBalance')) {
    /**
     * Get contact balance
     *
     * @param string $userPubkey
     * @param string $contactPubkey
     * @return int
     */
    function getContactBalance(string $userPubkey, string $contactPubkey): int
    {
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return $transactionRepository->getContactBalance($userPubkey, $contactPubkey);
    }
}

if (!function_exists('getAllContactBalances')) {
    /**
     * Get all contact balances
     *
     * @param string $userPubkey
     * @param array $contactPubkeys
     * @return array
     */
    function getAllContactBalances(string $userPubkey, array $contactPubkeys): array
    {
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return $transactionRepository->getAllContactBalances($userPubkey, $contactPubkeys);
    }
}

if (!function_exists('getTransactionHistory')) {
    /**
     * Get transaction history
     *
     * @param int $limit
     * @return array
     */
    function getTransactionHistory(int $limit = 10): array
    {
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return $transactionRepository->getTransactionHistory($limit);
    }
}

if (!function_exists('getContactNameByAddress')) {
    /**
     * Get contact name by address
     *
     * @param string $address
     * @return string|null
     */
    function getContactNameByAddress(string $address): ?string
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->getContactNameByAddress($address);
    }
}

if (!function_exists('checkForNewTransactions')) {
    /**
     * Check for new transactions
     *
     * @param int $lastCheckTime
     * @return bool
     */
    function checkForNewTransactions(int $lastCheckTime): bool
    {
        $transactionRepository = GUIServiceContainer::get('transactionRepository');
        return $transactionRepository->checkForNewTransactions($lastCheckTime);
    }
}

if (!function_exists('checkForNewContactRequests')) {
    /**
     * Check for new contact requests
     *
     * @param int $lastCheckTime
     * @return bool
     */
    function checkForNewContactRequests(int $lastCheckTime): bool
    {
        $contactRepository = GUIServiceContainer::get('contactRepository');
        return $contactRepository->checkForNewContactRequests($lastCheckTime);
    }
}

if (!function_exists('parseContactOutput')) {
    /**
     * Parse contact output
     *
     * @param string $output
     * @return array
     */
    function parseContactOutput(string $output): array
    {
        return MessageHelper::parseContactOutput($output);
    }
}

if (!function_exists('redirectMessage')) {
    /**
     * Redirect with message
     *
     * @param string $message
     * @param string $messageType
     * @return void
     */
    function redirectMessage(string $message, string $messageType): void
    {
        MessageHelper::redirectMessage($message, $messageType);
    }
}

// Initialize data for backward compatibility with existing layout files
// These variables are used by the layout files
if (isset($user) && isset($user['public'])) {
    $totalBalance = getUserTotalBalance();
    $transactions = getTransactionHistory(10);
    $allContacts = getAllContacts();
    $acceptedContacts = contactConversion(getAcceptedContacts());
    $pendingContacts = getPendingContacts();
    $pendingUserContacts = contactConversion(getUserPendingContacts());
    $blockedContacts = contactConversion(getBlockedContacts());
}

// Get message from URL parameters (for redirects)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $messageForDisplay = $_GET['message'];
    $messageTypeForDisplay = $_GET['type'];
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}
