<?php
/**
 * Wallet Controller
 *
 * Handles wallet page logic and data preparation
 *
 * Copyright 2025
 */

namespace Eiou\Gui\Controllers;

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../../services/ServiceContainer.php';
require_once __DIR__ . '/../../services/utilities/UtilityServiceContainer.php';
require_once __DIR__ . '/../../core/UserContext.php';

use Session;
use ServiceContainer;
use UtilityServiceContainer;
use UserContext;

/**
 * Wallet Controller
 *
 * Prepares data for wallet view and handles wallet-related actions
 */
class WalletController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var ServiceContainer Service container
     */
    private ServiceContainer $serviceContainer;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var UserContext User context
     */
    private UserContext $user;

    /**
     * @var array View data to be passed to templates
     */
    private array $viewData = [];

    /**
     * Constructor
     *
     * @param Session $session Session manager
     * @param ServiceContainer $serviceContainer Service container
     * @param UtilityServiceContainer $utilityContainer Utility service container
     * @param UserContext $user User context
     */
    public function __construct(
        Session $session,
        ServiceContainer $serviceContainer,
        UtilityServiceContainer $utilityContainer,
        UserContext $user
    ) {
        $this->session = $session;
        $this->serviceContainer = $serviceContainer;
        $this->utilityContainer = $utilityContainer;
        $this->user = $user;
    }

    /**
     * Initialize wallet page data
     *
     * Prepares all data needed for wallet view
     *
     * @return void
     */
    public function index(): void
    {
        // Load models
        require_once __DIR__ . '/../models/Balance.php';
        require_once __DIR__ . '/../models/Contact.php';
        require_once __DIR__ . '/../models/Transaction.php';

        $balanceModel = new \Eiou\Gui\Models\Balance($this->serviceContainer);
        $contactModel = new \Eiou\Gui\Models\Contact($this->serviceContainer);
        $transactionModel = new \Eiou\Gui\Models\Transaction($this->serviceContainer);

        // Prepare view data
        $this->viewData = [
            // User data
            'user' => [
                'hostname' => $this->user->has('hostname') ?
                    htmlspecialchars(parse_url($this->user->getHttpAddress(), PHP_URL_HOST) ?: $this->user->getHttpAddress()) :
                    '',
                'address' => $this->user->has('http_address') ? $this->user->getHttpAddress() : '',
                'authCode' => $this->user->has('authcode') ? $this->user->getAuthCode() : ''
            ],

            // Balance data
            'balance' => [
                'usd' => $balanceModel->getFormattedBalance('USD'),
                'all' => $balanceModel->getAllBalances()
            ],

            // Contacts data
            'contacts' => [
                'all' => $contactModel->getAllContacts(),
                'active' => $contactModel->getActiveContacts(),
                'pending' => $contactModel->getPendingRequests(),
                'blocked' => $contactModel->getBlockedContacts(),
                'count' => $contactModel->getCount()
            ],

            // Transactions data
            'transactions' => [
                'recent' => $transactionModel->getRecentTransactions(20),
                'sent' => $transactionModel->getSentTransactions(10),
                'received' => $transactionModel->getReceivedTransactions(10),
                'statistics' => $transactionModel->getStatistics()
            ],

            // Security
            'csrfToken' => $this->session->getCSRFToken(),

            // System info
            'isAuthenticated' => $this->session->isAuthenticated(),
            'sessionTimeout' => $this->session->getSessionTimeout()
        ];

        // Make view data available for templates
        extract($this->viewData);

        // Load main wallet view
        require_once(__DIR__ . '/../layout/wallet.html');
    }

    /**
     * Get view data
     *
     * @return array View data array
     */
    public function getViewData(): array
    {
        return $this->viewData;
    }

    /**
     * Set view data
     *
     * @param string $key Data key
     * @param mixed $value Data value
     * @return void
     */
    public function setViewData(string $key, $value): void
    {
        $this->viewData[$key] = $value;
    }

    /**
     * Get wallet summary for quick view
     *
     * @return array Wallet summary data
     */
    public function getSummary(): array
    {
        require_once __DIR__ . '/../models/Balance.php';
        require_once __DIR__ . '/../models/Contact.php';
        require_once __DIR__ . '/../models/Transaction.php';

        $balanceModel = new \Eiou\Gui\Models\Balance($this->serviceContainer);
        $contactModel = new \Eiou\Gui\Models\Contact($this->serviceContainer);
        $transactionModel = new \Eiou\Gui\Models\Transaction($this->serviceContainer);

        return [
            'balance' => $balanceModel->getBalance('USD'),
            'contact_count' => $contactModel->getCount(),
            'pending_requests' => count($contactModel->getPendingRequests()),
            'recent_transaction_count' => count($transactionModel->getRecentTransactions(5)),
            'total_sent' => $transactionModel->getStatistics()['total_sent'],
            'total_received' => $transactionModel->getStatistics()['total_received']
        ];
    }

    /**
     * Handle AJAX requests for wallet data
     *
     * @param string $action Action to perform
     * @return void
     */
    public function handleAjax(string $action): void
    {
        header('Content-Type: application/json');

        try {
            $response = match ($action) {
                'getSummary' => $this->getSummary(),
                'getBalance' => $this->getBalanceData(),
                'getContacts' => $this->getContactsData(),
                'getTransactions' => $this->getTransactionsData(),
                default => ['error' => 'Unknown action']
            };

            echo json_encode($response);
        } catch (\Exception $e) {
            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * Get balance data for AJAX
     *
     * @return array Balance data
     */
    private function getBalanceData(): array
    {
        require_once __DIR__ . '/../models/Balance.php';
        $balanceModel = new \Eiou\Gui\Models\Balance($this->serviceContainer);

        return [
            'balances' => $balanceModel->getAllBalances(),
            'formatted' => [
                'usd' => $balanceModel->getFormattedBalance('USD'),
                'eur' => $balanceModel->getFormattedBalance('EUR')
            ]
        ];
    }

    /**
     * Get contacts data for AJAX
     *
     * @return array Contacts data
     */
    private function getContactsData(): array
    {
        require_once __DIR__ . '/../models/Contact.php';
        $contactModel = new \Eiou\Gui\Models\Contact($this->serviceContainer);

        return [
            'contacts' => $contactModel->getAllContacts(),
            'count' => $contactModel->getCount(),
            'pending' => $contactModel->getPendingRequests()
        ];
    }

    /**
     * Get transactions data for AJAX
     *
     * @return array Transactions data
     */
    private function getTransactionsData(): array
    {
        require_once __DIR__ . '/../models/Transaction.php';
        $transactionModel = new \Eiou\Gui\Models\Transaction($this->serviceContainer);

        return [
            'transactions' => $transactionModel->getRecentTransactions(20),
            'statistics' => $transactionModel->getStatistics()
        ];
    }

    /**
     * Render view with data
     *
     * @param string $viewPath Path to view file
     * @param array $data Data to pass to view
     * @return void
     */
    protected function render(string $viewPath, array $data = []): void
    {
        // Merge with existing view data
        $data = array_merge($this->viewData, $data);

        // Extract data for use in view
        extract($data);

        // Include view file
        require_once($viewPath);
    }

    /**
     * Redirect with message
     *
     * @param string $message Message to display
     * @param string $type Message type (success, error, info)
     * @return void
     */
    protected function redirectWithMessage(string $message, string $type = 'info'): void
    {
        require_once __DIR__ . '/../helpers/MessageHelper.php';
        \MessageHelper::redirectMessage($message, $type);
    }
}
