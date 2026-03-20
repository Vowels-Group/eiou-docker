<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Core\Application;
use Eiou\Core\Constants;
use Eiou\Gui\Includes\Session;
use Eiou\Services\ContactService;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Cli\CliOutputManager;
use Eiou\Utils\Logger;
use Eiou\Gui\Helpers\MessageHelper;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\Utilities\CurrencyUtilityService;

/**
 * Contact Controller
 *
 * Handles HTTP POST requests for contact-related actions.
 * Uses JSON output mode for proper message handling.
 */

class ContactController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var ContactService Contact service from ServiceContainer
     */
    private $contactService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param ContactService $contactService
     */
    public function __construct(
        Session $session,
        ContactService $contactService
        )
    {
        $this->session = $session;
        $this->contactService = $contactService;
    }

    /**
     * Handle add contact form submission
     *
     * This method uses InputValidator and Security classes to validate and sanitize
     * all user input before processing the contact addition.
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleAddContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $address = Security::sanitizeInput($_POST['address'] ?? '');
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        $fee = $_POST['fee'] ?? '';
        $credit = $_POST['credit'] ?? '';
        $currency = $_POST['currency'] ?? '';
        $description = Security::sanitizeInput($_POST['description'] ?? '');

        if (empty($address) || empty($name) || $fee === '' || $credit === '' || empty($currency)) {
            $message = 'All fields are required';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($address);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Validate contact name
            $nameValidation = InputValidator::validateContactName($name);
            if (!$nameValidation['valid']) {
                MessageHelper::redirectMessage('Invalid contact name: ' . $nameValidation['error'], 'error');
                return;
            }

            // Validate fee percentage
            $feeValidation = InputValidator::validateFeePercent($fee);
            if (!$feeValidation['valid']) {
                MessageHelper::redirectMessage('Invalid fee: ' . $feeValidation['error'], 'error');
                return;
            }

            // Validate credit limit
            $creditValidation = InputValidator::validateCreditLimit($credit);
            if (!$creditValidation['valid']) {
                MessageHelper::redirectMessage('Invalid credit limit: ' . $creditValidation['error'], 'error');
                return;
            }

            // Validate currency
            $currencyValidation = InputValidator::validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                MessageHelper::redirectMessage('Invalid currency: ' . $currencyValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated values
            $address = $addressValidation['value'];
            $name = $nameValidation['value'];
            $fee = $feeValidation['value'];
            $credit = $creditValidation['value'];
            $currency = $currencyValidation['value'];

            // Create argv array with --json flag for structured output
            $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];
            if (!empty($description)) {
                $argv[] = $description;
            }
            $argv[] = '--json';

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                $this->contactService->addContact($argv, $outputManager);
                $output = ob_get_clean();

                // Parse the JSON output to determine message type and content
                $messageInfo = MessageHelper::parseCliJsonOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle accept contact request
     *
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleAcceptContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');
        $contactName = Security::sanitizeInput($_POST['contact_name'] ?? '');
        $contactFee = $_POST['contact_fee'] ?? '';
        $contactCredit = $_POST['contact_credit'] ?? '';
        $contactCurrency = $_POST['contact_currency'] ?? '';

        if (empty($contactAddress) || empty($contactName) || $contactFee === '' || $contactCredit === '' || empty($contactCurrency)) {
            $message = 'All fields are required to accept a contact';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Validate contact name
            $nameValidation = InputValidator::validateContactName($contactName);
            if (!$nameValidation['valid']) {
                MessageHelper::redirectMessage('Invalid contact name: ' . $nameValidation['error'], 'error');
                return;
            }

            // Validate fee percentage
            $feeValidation = InputValidator::validateFeePercent($contactFee);
            if (!$feeValidation['valid']) {
                MessageHelper::redirectMessage('Invalid fee: ' . $feeValidation['error'], 'error');
                return;
            }

            // Validate credit limit
            $creditValidation = InputValidator::validateCreditLimit($contactCredit);
            if (!$creditValidation['valid']) {
                MessageHelper::redirectMessage('Invalid credit limit: ' . $creditValidation['error'], 'error');
                return;
            }

            // Validate currency
            $currencyValidation = InputValidator::validateCurrency($contactCurrency);
            if (!$currencyValidation['valid']) {
                MessageHelper::redirectMessage('Invalid currency: ' . $currencyValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated values
            $contactAddress = $addressValidation['value'];
            $contactName = $nameValidation['value'];
            $contactFee = $feeValidation['value'];
            $contactCredit = $creditValidation['value'];
            $contactCurrency = $currencyValidation['value'];
            // Create argv array with --json flag for structured output
            $argv = ['eiou', 'add', $contactAddress, $contactName, $contactFee, $contactCredit, $contactCurrency, '--json'];

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                $this->contactService->addContact($argv, $outputManager);
                $output = ob_get_clean();

                // Parse the JSON output to determine message type and content
                $messageInfo = MessageHelper::parseCliJsonOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle delete contact request
     *
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleDeleteContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated value
            $contactAddress = $addressValidation['value'];

            // Create argv with --json flag for structured output
            $argv = ['eiou', 'delete', $contactAddress, '--json'];

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                $this->contactService->deleteContact($contactAddress, $outputManager);
                $output = ob_get_clean();

                // Parse the JSON output to determine message type and content
                $messageInfo = MessageHelper::parseCliJsonOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle block contact request
     *
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleBlockContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated value
            $contactAddress = $addressValidation['value'];

            // Create argv with --json flag for structured output
            $argv = ['eiou', 'block', $contactAddress, '--json'];

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                $this->contactService->blockContact($contactAddress, $outputManager);
                $output = ob_get_clean();

                // Parse the JSON output to determine message type and content
                $messageInfo = MessageHelper::parseCliJsonOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle unblock contact request
     *
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleUnblockContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated value
            $contactAddress = $addressValidation['value'];

            // Create argv with --json flag for structured output
            $argv = ['eiou', 'unblock', $contactAddress, '--json'];

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                $this->contactService->unblockContact($contactAddress, $outputManager);
                $output = ob_get_clean();

                // Parse the JSON output to determine message type and content
                $messageInfo = MessageHelper::parseCliJsonOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle edit contact request
     *
     * Uses JSON output mode for structured error handling.
     *
     * @return void
     */
    public function handleEditContact(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');
        $contactName = Security::sanitizeInput($_POST['contact_name'] ?? '');
        $contactFee = $_POST['contact_fee'] ?? '';
        $contactCredit = $_POST['contact_credit'] ?? '';
        $contactCurrency = $_POST['contact_currency'] ?? '';

        if (empty($contactAddress) || empty($contactName) || $contactFee === '' || $contactCredit === '' || empty($contactCurrency)) {
            $message = 'All fields are required to edit a contact';
            $messageType = 'error';
        } else {
            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                MessageHelper::redirectMessage('Invalid address: ' . $addressValidation['error'], 'error');
                return;
            }

            // Validate contact name
            $nameValidation = InputValidator::validateContactName($contactName);
            if (!$nameValidation['valid']) {
                MessageHelper::redirectMessage('Invalid contact name: ' . $nameValidation['error'], 'error');
                return;
            }

            // Validate fee percentage
            $feeValidation = InputValidator::validateFeePercent($contactFee);
            if (!$feeValidation['valid']) {
                MessageHelper::redirectMessage('Invalid fee: ' . $feeValidation['error'], 'error');
                return;
            }

            // Validate credit limit
            $creditValidation = InputValidator::validateCreditLimit($contactCredit);
            if (!$creditValidation['valid']) {
                MessageHelper::redirectMessage('Invalid credit limit: ' . $creditValidation['error'], 'error');
                return;
            }

            // Validate currency
            $currencyValidation = InputValidator::validateCurrency($contactCurrency);
            if (!$currencyValidation['valid']) {
                MessageHelper::redirectMessage('Invalid currency: ' . $currencyValidation['error'], 'error');
                return;
            }

            // Use sanitized and validated values
            $contactAddress = $addressValidation['value'];
            $contactName = $nameValidation['value'];
            $contactFee = $feeValidation['value'];
            $contactCredit = $creditValidation['value'];
            $contactCurrency = $currencyValidation['value'];

            try {
                // Update contact name in the contacts table
                $argv = ['eiou', 'update', $contactAddress, 'name', $contactName, '--json'];
                CliOutputManager::resetInstance();
                $outputManager = new CliOutputManager($argv);
                ob_start();
                $this->contactService->updateContact($argv, $outputManager);
                $output = ob_get_clean();
                $messageInfo = MessageHelper::parseCliJsonOutput($output);

                if ($messageInfo['type'] === 'error') {
                    $message = $messageInfo['message'];
                    $messageType = 'error';
                } else {
                    // Update fee/credit in contact_currencies for the selected currency
                    $app = Application::getInstance();
                    $contactCurrencyRepo = $app->services->getRepositoryFactory()->get(ContactCurrencyRepository::class);

                    // Look up contact pubkey hash
                    $contact = $this->contactService->lookupContactInfo($contactAddress);
                    if ($contact) {
                        $pubkeyHash = $contact['receiverPublicKeyHash'] ?? '';

                        // Convert to storage units
                        $feeMinor = CurrencyUtilityService::exactMajorToMinor($contactFee, Constants::FEE_CONVERSION_FACTOR);
                        $creditMinor = \Eiou\Core\SplitAmount::fromMajorUnits($contactCredit);

                        $contactCurrencyRepo->updateCurrencyConfig($pubkeyHash, $contactCurrency, [
                            'fee_percent' => $feeMinor,
                            'credit_limit' => $creditMinor,
                        ]);

                        $message = 'Contact updated successfully';
                        $messageType = 'success';
                    } else {
                        $message = 'Contact not found for currency update';
                        $messageType = 'error';
                    }
                }
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                Logger::getInstance()->logException($e, [
                    'controller' => 'ContactController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle adding a new currency to an existing contact (AJAX - returns JSON)
     *
     * @return void
     */
    public function handleAddCurrency(): void
    {
        header('Content-Type: application/json');

        try {
            $this->session->verifyCSRFToken();

            $pubkey = Security::sanitizeInput($_POST['pubkey'] ?? '');
            $currency = strtoupper(Security::sanitizeInput($_POST['currency'] ?? ''));
            $fee = Security::sanitizeInput($_POST['fee'] ?? '');
            $credit = Security::sanitizeInput($_POST['credit'] ?? '');

            if (empty($pubkey) || empty($currency) || $fee === '' || $credit === '') {
                echo json_encode(['success' => false, 'error' => 'missing_fields', 'message' => 'All fields are required']);
                return;
            }

            $feeValidation = InputValidator::validateFeePercent($fee);
            if (!$feeValidation['valid']) {
                echo json_encode(['success' => false, 'error' => 'invalid_fee', 'message' => $feeValidation['error']]);
                return;
            }

            $creditValidation = InputValidator::validateAmount($credit, $currency);
            if (!$creditValidation['valid']) {
                echo json_encode(['success' => false, 'error' => 'invalid_credit', 'message' => $creditValidation['error']]);
                return;
            }

            $app = Application::getInstance();
            $serviceContainer = $app->services;
            $contactService = $serviceContainer->getService('ContactManagementService');

            $result = $contactService->addCurrencyToContact(
                $pubkey,
                $currency,
                $feeValidation['value'],
                $creditValidation['value']
            );

            if ($result) {
                echo json_encode(['success' => true, 'message' => "Currency {$currency} added to contact"]);
            } else {
                echo json_encode(['success' => false, 'error' => 'add_currency_failed', 'message' => 'Failed to add currency. Contact may not be accepted or currency already exists.']);
            }
        } catch (\Exception $e) {
            Logger::getInstance()->logException($e);
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => 'An unexpected error occurred']);
        }
    }

    /**
     * Handle accepting a pending currency for an existing contact
     *
     * Accepts a pending currency request with user-provided fee and credit limit values.
     */
    public function handleAcceptCurrency(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        $pubkeyHash = Security::sanitizeInput($_POST['pubkey_hash'] ?? '');
        $currency = strtoupper(Security::sanitizeInput($_POST['currency'] ?? ''));
        $fee = Security::sanitizeInput($_POST['fee'] ?? '');
        $credit = Security::sanitizeInput($_POST['credit'] ?? '');

        if (empty($pubkeyHash) || empty($currency) || $fee === '' || $credit === '') {
            MessageHelper::redirectMessage('All fields are required to accept a currency.', 'error');
            return;
        }

        $feeValidation = InputValidator::validateFeePercent($fee);
        if (!$feeValidation['valid']) {
            MessageHelper::redirectMessage('Invalid fee: ' . $feeValidation['error'], 'error');
            return;
        }

        $creditValidation = InputValidator::validateAmount($credit, $currency);
        if (!$creditValidation['valid']) {
            MessageHelper::redirectMessage('Invalid credit: ' . $creditValidation['error'], 'error');
            return;
        }

        try {
            $app = Application::getInstance();
            $serviceContainer = $app->services;
            $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(ContactCurrencyRepository::class);

            // Convert to storage units
            $creditMinor = \Eiou\Core\SplitAmount::fromMajorUnits($creditValidation['value']);
            $feeMinor = CurrencyUtilityService::exactMajorToMinor($feeValidation['value'], Constants::FEE_CONVERSION_FACTOR);

            // Update the pending currency with user's fee/credit and set status to accepted
            $contactCurrencyRepo->updateCurrencyConfig($pubkeyHash, $currency, [
                'fee_percent' => $feeMinor,
                'credit_limit' => $creditMinor,
                'status' => 'accepted'
            ], 'incoming');

            // If we also have an outgoing pending request for the same currency (cross-request),
            // mark it as accepted too — their incoming request proves they agree to this currency
            if ($contactCurrencyRepo->hasCurrency($pubkeyHash, $currency, 'outgoing')) {
                $contactCurrencyRepo->updateCurrencyStatus($pubkeyHash, $currency, 'accepted', 'outgoing');
            }

            // Insert initial balance and calculate available credit for the newly accepted currency
            $contactPubkey = $serviceContainer->getRepositoryFactory()->get(ContactRepository::class)->getContactPubkeyFromHash($pubkeyHash);
            if ($contactPubkey) {
                $balanceRepo = $serviceContainer->getRepositoryFactory()->get(BalanceRepository::class);
                $balanceRepo->insertInitialContactBalances($contactPubkey, $currency);
                try {
                    $sentBalance = $balanceRepo->getContactSentBalance($contactPubkey, $currency);
                    $receivedBalance = $balanceRepo->getContactReceivedBalance($contactPubkey, $currency);
                    $balance = $sentBalance - $receivedBalance;
                    $creditLimit = $contactCurrencyRepo->getCreditLimit($pubkeyHash, $currency) ?? 0;
                    $serviceContainer->getRepositoryFactory()->get(ContactCreditRepository::class)->upsertAvailableCredit(
                        $pubkeyHash,
                        (int) ($balance + $creditLimit),
                        $currency
                    );
                } catch (\Exception $e) {
                    Logger::getInstance()->warning("Failed to store initial credit for accepted currency", [
                        'currency' => $currency,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Send acceptance notification to remote side so they mark their outgoing currency as accepted
            // Also upgrades contact status from pending to accepted if needed
            $contactSyncService = $serviceContainer->getContactSyncService();
            $contactSyncService->sendCurrencyAcceptanceNotification($pubkeyHash, $currency);

            MessageHelper::redirectMessage("Currency {$currency} accepted.", 'success');
        } catch (\Exception $e) {
            Logger::getInstance()->logException($e);
            MessageHelper::redirectMessage('An unexpected error occurred.', 'error');
        }
    }

    /**
     * Handle accept all currencies for a contact in a single POST
     */
    public function handleAcceptAllCurrencies(): void
    {
        $this->session->verifyCSRFToken();

        $pubkeyHash = Security::sanitizeInput($_POST['pubkey_hash'] ?? '');
        $currenciesJson = $_POST['currencies'] ?? '[]';
        $currencies = json_decode($currenciesJson, true);
        $isNewContact = !empty($_POST['is_new_contact']);

        if (empty($pubkeyHash) || empty($currencies) || !is_array($currencies)) {
            MessageHelper::redirectMessage('Invalid request to accept currencies.', 'error');
            return;
        }

        try {
            $app = Application::getInstance();
            $serviceContainer = $app->services;
            $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(ContactCurrencyRepository::class);
            $contactPubkey = $serviceContainer->getRepositoryFactory()->get(ContactRepository::class)->getContactPubkeyFromHash($pubkeyHash);
            $contactSyncService = $serviceContainer->getContactSyncService();

            $accepted = [];
            $errors = [];

            // For new contacts: accept the first currency via addContact to establish the contact,
            // then accept remaining currencies via the standard currency acceptance path
            $firstCurrencyHandled = false;
            if ($isNewContact && !empty($contactPubkey)) {
                $contact = $serviceContainer->getRepositoryFactory()->get(ContactRepository::class)->getContactByPubkey($contactPubkey);
                if ($contact && $contact['status'] !== Constants::CONTACT_STATUS_ACCEPTED) {
                    $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');
                    $contactName = Security::sanitizeInput($_POST['contact_name'] ?? '');

                    if (!empty($contactAddress) && !empty($contactName) && !empty($currencies[0])) {
                        $firstEntry = $currencies[0];
                        $firstCurrency = strtoupper(Security::sanitizeInput($firstEntry['currency'] ?? ''));
                        $firstFee = Security::sanitizeInput($firstEntry['fee'] ?? '');
                        $firstCredit = Security::sanitizeInput($firstEntry['credit'] ?? '');

                        if (!empty($firstCurrency) && $firstFee !== '' && $firstCredit !== '') {
                            // Accept the contact with the first currency via CLI addContact
                            $argv = ['eiou', 'add', $contactAddress, $contactName, $firstFee, $firstCredit, $firstCurrency, '--json'];
                            CliOutputManager::resetInstance();
                            $outputManager = new CliOutputManager($argv);

                            ob_start();
                            try {
                                $this->contactService->addContact($argv, $outputManager);
                                ob_end_clean();
                                $accepted[] = $firstCurrency;
                                $firstCurrencyHandled = true;
                            } catch (\Throwable $e) {
                                if (ob_get_level() > 0) { ob_end_clean(); }
                                $errors[] = "{$firstCurrency}: " . $e->getMessage();
                            }
                        }
                    }

                    // Remove the first currency from the list since it was handled
                    if ($firstCurrencyHandled) {
                        array_shift($currencies);
                    }
                }
            }

            // Accept remaining currencies (or all if contact was already accepted)
            foreach ($currencies as $entry) {
                $currency = strtoupper(Security::sanitizeInput($entry['currency'] ?? ''));
                $fee = Security::sanitizeInput($entry['fee'] ?? '');
                $credit = Security::sanitizeInput($entry['credit'] ?? '');

                if (empty($currency) || $fee === '' || $credit === '') {
                    $errors[] = "{$currency}: missing fields";
                    continue;
                }

                $feeValidation = InputValidator::validateFeePercent($fee);
                if (!$feeValidation['valid']) {
                    $errors[] = "{$currency}: invalid fee";
                    continue;
                }

                $creditValidation = InputValidator::validateAmount($credit, $currency);
                if (!$creditValidation['valid']) {
                    $errors[] = "{$currency}: invalid credit";
                    continue;
                }

                // Convert to storage units
                $creditMinor = \Eiou\Core\SplitAmount::fromMajorUnits($creditValidation['value']);
                $feeMinor = CurrencyUtilityService::exactMajorToMinor($feeValidation['value'], Constants::FEE_CONVERSION_FACTOR);

                $contactCurrencyRepo->updateCurrencyConfig($pubkeyHash, $currency, [
                    'fee_percent' => $feeMinor,
                    'credit_limit' => $creditMinor,
                    'status' => 'accepted'
                ], 'incoming');

                if ($contactCurrencyRepo->hasCurrency($pubkeyHash, $currency, 'outgoing')) {
                    $contactCurrencyRepo->updateCurrencyStatus($pubkeyHash, $currency, 'accepted', 'outgoing');
                }

                // Re-fetch pubkey in case it was just established by the first currency acceptance
                $currentPubkey = $contactPubkey ?: $serviceContainer->getRepositoryFactory()->get(ContactRepository::class)->getContactPubkeyFromHash($pubkeyHash);
                if ($currentPubkey) {
                    $balanceRepo = $serviceContainer->getRepositoryFactory()->get(BalanceRepository::class);
                    $balanceRepo->insertInitialContactBalances($currentPubkey, $currency);
                    try {
                        $sentBalance = $balanceRepo->getContactSentBalance($currentPubkey, $currency);
                        $receivedBalance = $balanceRepo->getContactReceivedBalance($currentPubkey, $currency);
                        $balance = $sentBalance - $receivedBalance;
                        $creditLimit = $contactCurrencyRepo->getCreditLimit($pubkeyHash, $currency) ?? 0;
                        $serviceContainer->getRepositoryFactory()->get(ContactCreditRepository::class)->upsertAvailableCredit(
                            $pubkeyHash,
                            (int) ($balance + $creditLimit),
                            $currency
                        );
                    } catch (\Exception $e) {
                        // Non-fatal — credit will be corrected on next ping/pong
                    }
                }

                $contactSyncService->sendCurrencyAcceptanceNotification($pubkeyHash, $currency);
                $accepted[] = $currency;
            }

            if (!empty($accepted)) {
                $msg = 'Accepted: ' . implode(', ', $accepted) . '.';
                if (!empty($errors)) {
                    $msg .= ' Errors: ' . implode('; ', $errors);
                }
                MessageHelper::redirectMessage($msg, 'success');
            } else {
                MessageHelper::redirectMessage('Failed to accept currencies: ' . implode('; ', $errors), 'error');
            }
        } catch (\Exception $e) {
            Logger::getInstance()->logException($e);
            MessageHelper::redirectMessage('An unexpected error occurred.', 'error');
        }
    }

    /**
     * Handle ping contact request (AJAX - returns JSON)
     *
     * @return void
     */
    public function handlePingContact(): void
    {
        // Set JSON header
        header('Content-Type: application/json');

        try {
            // CSRF Protection: Verify token before processing
            $this->session->verifyCSRFToken();

            // Sanitize input data
            $contactAddress = Security::sanitizeInput($_POST['contact_address'] ?? '');

            if (empty($contactAddress)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'missing_address',
                    'message' => 'Contact address is required'
                ]);
                return;
            }

            // Validate address
            $addressValidation = InputValidator::validateAddress($contactAddress);
            if (!$addressValidation['valid']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'invalid_address',
                    'message' => 'Invalid address: ' . $addressValidation['error']
                ]);
                return;
            }

            $contactAddress = $addressValidation['value'];

            // Get ContactStatusService and ping the contact
            $app = Application::getInstance();
            $contactStatusService = $app->services->getContactStatusService();
            $result = $contactStatusService->pingContact($contactAddress);

            echo json_encode($result);

        } catch (\Throwable $e) {
            // Use Logger for exception logging
            Logger::getInstance()->logException($e, [
                'controller' => 'ContactController',
                'action' => __FUNCTION__
            ]);
            echo json_encode([
                'success' => false,
                'error' => 'internal_error',
                'message' => Constants::isDebug()
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error'
            ]);
        }
    }

    /**
     * Route contact actions based on POST data
     *
     * @return void
     */
    public function routeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'addContact':
                $this->handleAddContact();
                break;

            case 'acceptContact':
                $this->handleAcceptContact();
                break;

            case 'deleteContact':
                $this->handleDeleteContact();
                break;

            case 'blockContact':
                $this->handleBlockContact();
                break;
            case 'unblockContact':
                $this->handleUnblockContact();
                break;

            case 'editContact':
                $this->handleEditContact();
                break;

            case 'addCurrency':
                $this->handleAddCurrency();
                break;

            case 'acceptCurrency':
                $this->handleAcceptCurrency();
                break;

            case 'acceptAllCurrencies':
                $this->handleAcceptAllCurrencies();
                break;

            case 'pingContact':
                $this->handlePingContact();
                break;

            case 'proposeChainDrop':
                $this->handleProposeChainDrop();
                break;
            case 'acceptChainDrop':
                $this->handleAcceptChainDrop();
                break;
            case 'rejectChainDrop':
                $this->handleRejectChainDrop();
                break;
        }
    }

    /**
     * Handle propose chain drop request (AJAX - returns JSON)
     *
     * @return void
     */
    public function handleProposeChainDrop(): void
    {
        header('Content-Type: application/json');
        try {
            $this->session->verifyCSRFToken();
            $contactPubkeyHash = Security::sanitizeInput($_POST['contact_pubkey_hash'] ?? '');
            if (empty($contactPubkeyHash)) {
                echo json_encode(['success' => false, 'error' => 'missing_pubkey_hash', 'message' => 'Contact pubkey hash is required']);
                return;
            }
            $app = Application::getInstance();
            $chainDropService = $app->services->getChainDropService();
            $result = $chainDropService->proposeChainDrop($contactPubkeyHash);
            echo json_encode($result);
        } catch (\Throwable $e) {
            Logger::getInstance()->logException($e, ['controller' => 'ContactController', 'action' => __FUNCTION__]);
            echo json_encode([
                'success' => false,
                'error' => 'internal_error',
                'message' => Constants::getAppEnv() !== 'production' ? 'Internal server error: ' . $e->getMessage() : 'Internal server error'
            ]);
        }
    }

    /**
     * Handle accept chain drop request (AJAX - returns JSON)
     *
     * @return void
     */
    public function handleAcceptChainDrop(): void
    {
        header('Content-Type: application/json');
        try {
            $this->session->verifyCSRFToken();
            $proposalId = Security::sanitizeInput($_POST['proposal_id'] ?? '');
            if (empty($proposalId)) {
                echo json_encode(['success' => false, 'error' => 'missing_proposal_id', 'message' => 'Proposal ID is required']);
                return;
            }
            $app = Application::getInstance();
            $chainDropService = $app->services->getChainDropService();
            $result = $chainDropService->acceptProposal($proposalId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            Logger::getInstance()->logException($e, ['controller' => 'ContactController', 'action' => __FUNCTION__]);
            echo json_encode([
                'success' => false,
                'error' => 'internal_error',
                'message' => Constants::getAppEnv() !== 'production' ? 'Internal server error: ' . $e->getMessage() : 'Internal server error'
            ]);
        }
    }

    /**
     * Handle reject chain drop request (AJAX - returns JSON)
     *
     * @return void
     */
    public function handleRejectChainDrop(): void
    {
        header('Content-Type: application/json');
        try {
            $this->session->verifyCSRFToken();
            $proposalId = Security::sanitizeInput($_POST['proposal_id'] ?? '');
            if (empty($proposalId)) {
                echo json_encode(['success' => false, 'error' => 'missing_proposal_id', 'message' => 'Proposal ID is required']);
                return;
            }
            $app = Application::getInstance();
            $chainDropService = $app->services->getChainDropService();
            $result = $chainDropService->rejectProposal($proposalId);
            if ($result['success']) {
                $result['warning'] = 'The chain gap remains unresolved. Transactions with this contact are blocked until a new chain drop proposal is accepted.';
            }
            echo json_encode($result);
        } catch (\Throwable $e) {
            Logger::getInstance()->logException($e, ['controller' => 'ContactController', 'action' => __FUNCTION__]);
            echo json_encode([
                'success' => false,
                'error' => 'internal_error',
                'message' => Constants::getAppEnv() !== 'production' ? 'Internal server error: ' . $e->getMessage() : 'Internal server error'
            ]);
        }
    }
}
