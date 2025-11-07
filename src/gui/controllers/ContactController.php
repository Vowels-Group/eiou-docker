<?php
/**
 * Contact Controller
 *
 * Copyright 2025
 * Handles HTTP POST requests for contact-related actions using MVC pattern.
 */

namespace Eiou\Gui\Controllers;

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../models/Contact.php';
require_once __DIR__ . '/../helpers/MessageHelper.php';
require_once __DIR__ . '/../../utils/InputValidator.php';
require_once __DIR__ . '/../../utils/Security.php';

use Session;
use Eiou\Gui\Models\Contact as ContactModel;
use MessageHelper;
use InputValidator;
use Security;

class ContactController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var ContactModel Contact model
     */
    private ContactModel $contactModel;

    /**
     * @var mixed ContactService Contact service from ServiceContainer (legacy)
     */
    private $contactService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param mixed $contactService
     */
    public function __construct(
        Session $session,
        $contactService
    )
    {
        $this->session = $session;
        $this->contactService = $contactService;

        // Initialize Contact Model
        require_once __DIR__ . '/../../services/ServiceContainer.php';
        $serviceContainer = \ServiceContainer::getInstance();
        $this->contactModel = new ContactModel($serviceContainer);
    }

    /**
     * Handle add contact form submission
     *
     * This method uses InputValidator and Security classes to validate and sanitize
     * all user input before processing the contact addition using the Contact Model.
     *
     * @return void
     */
    public function handleAddContact(): void
    {
        // Sanitize input data
        $address = Security::sanitizeInput($_POST['address'] ?? '');
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        $fee = $_POST['fee'] ?? '';
        $credit = $_POST['credit'] ?? '';
        $currency = $_POST['currency'] ?? '';

        // Validate using Contact Model
        $validation = $this->contactModel->validate([
            'address' => $address,
            'name' => $name,
            'fee' => $fee,
            'credit' => $credit,
            'currency' => $currency
        ]);

        if (!$validation['valid']) {
            $errors = $validation['errors'];
            $message = 'Validation failed: ' . implode(', ', $errors);
            MessageHelper::redirectMessage($message, 'error');
            return;
        }

        // Add contact using Model
        $success = $this->contactModel->add(
            $address,
            $name,
            (float)$fee,
            (float)$credit,
            $currency
        );

        if ($success) {
            MessageHelper::redirectMessage('Contact added successfully', 'success');
        } else {
            MessageHelper::redirectMessage('Failed to add contact', 'error');
        }
    }

    /**
     * Handle accept contact request
     *
     * @return void
     */
    public function handleAcceptContact(): void
    {
        $contactAddress = $_POST['contact_address'] ?? '';
        $contactName = $_POST['contact_name'] ?? '';
        $contactFee = $_POST['contact_fee'] ?? '';
        $contactCredit = $_POST['contact_credit'] ?? '';
        $contactCurrency = $_POST['contact_currency'] ?? '';

        if (empty($contactAddress) || empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
            $message = 'All fields are required to accept a contact';
            $messageType = 'error';
        } else {
            // Create argv array for addContact function
            $argv = ['eiou', 'add', $contactAddress, $contactName, $contactFee, $contactCredit, $contactCurrency];

            // Capture output
            ob_start();
            try {
                $this->contactService->addContact($argv);
                $output = ob_get_clean();

                // Parse the output to determine message type and content
                $messageInfo = MessageHelper::parseContactOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle delete contact request
     *
     * @return void
     */
    public function handleDeleteContact(): void
    {
        $contactAddress = $_POST['contact_address'] ?? '';

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Capture output
            ob_start();
            try {
                $this->contactService->deleteContact($contactAddress);
                $output = ob_get_clean();

                // Parse the output to determine message type and content
                $messageInfo = MessageHelper::parseContactOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle block contact request
     *
     * @return void
     */
    public function handleBlockContact(): void
    {
        $contactAddress = $_POST['contact_address'] ?? '';

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Capture output
            ob_start();
            try {
                $this->contactService->blockContact($contactAddress);
                $output = ob_get_clean();

                // Parse the output to determine message type and content
                $messageInfo = MessageHelper::parseContactOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle unblock contact request
     *
     * @return void
     */
    public function handleUnblockContact(): void
    {
        $contactAddress = $_POST['contact_address'] ?? '';

        if (empty($contactAddress)) {
            $message = 'Contact address is required';
            $messageType = 'error';
        } else {
            // Capture output
            ob_start();
            try {
                $this->contactService->unblockContact($contactAddress);
                $output = ob_get_clean();

                // Parse the output to determine message type and content
                $messageInfo = MessageHelper::parseContactOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle edit contact request
     *
     * @return void
     */
    public function handleEditContact(): void
    {
        $contactAddress = $_POST['contact_address'] ?? '';
        $contactName = $_POST['contact_name'] ?? '';
        $contactFee = $_POST['contact_fee'] ?? '';
        $contactCredit = $_POST['contact_credit'] ?? '';
        $contactCurrency = $_POST['contact_currency'] ?? '';

        if (empty($contactAddress) || empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
            $message = 'All fields are required to edit a contact';
            $messageType = 'error';
        } else {
            // Create argv array for updateContact function
            $argv = ['eiou', 'update', $contactAddress, 'all', $contactName, $contactFee, $contactCredit];

            // Capture output
            ob_start();
            try {
                $this->contactService->updateContact($argv);
                $output = ob_get_clean();

                // Parse the output to determine message type and content
                $messageInfo = MessageHelper::parseContactOutput($output);
                $message = $messageInfo['message'];
                $messageType = $messageInfo['type'];
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
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
        }
    }
}
