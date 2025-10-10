<?php
/**
 * Contact Controller
 *
 * Handles HTTP POST requests for contact-related actions.
 * Extracted from functions.php for clean MVC separation.
 *
 * @package eIOUGUI\Controllers
 * @author Hive Mind Collective
 * @copyright 2025
 */

namespace eIOUGUI\Controllers;

use eIOUGUI\Core\Session;
use eIOUGUI\Helpers\MessageHelper;

class ContactController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var object Contact service from ServiceContainer
     */
    private $contactService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param object $contactService
     */
    public function __construct(Session $session, $contactService)
    {
        $this->session = $session;
        $this->contactService = $contactService;
    }

    /**
     * Handle add contact form submission
     *
     * @return void
     */
    public function handleAddContact(): void
    {
        $address = $_POST['address'] ?? '';
        $name = $_POST['name'] ?? '';
        $fee = $_POST['fee'] ?? '';
        $credit = $_POST['credit'] ?? '';
        $currency = $_POST['currency'] ?? '';

        if (empty($address) || empty($name) || empty($fee) || empty($credit) || empty($currency)) {
            $message = 'All fields are required';
            $messageType = 'error';
        } else {
            // Create argv array for addContact function
            $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];

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
            case 'ublockContact': // Handle typo from original code
                $this->handleUnblockContact();
                break;

            case 'editContact':
                $this->handleEditContact();
                break;
        }
    }
}
