<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Services\ContactService;
use Eiou\Services\TransactionService;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Services\P2pApprovalService;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Utils\Logger;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Gui\Helpers\MessageHelper;
use Eiou\Formatters\TransactionFormatter;

/**
 * Transaction Controller
 *
 * Handles HTTP POST requests for transaction-related actions.
 */

class TransactionController
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
     * @var TransactionService Transaction service from ServiceContainer
     */
    private $transactionService;

    /**
     * @var P2pRepository|null P2P repository for approval gate
     */
    private ?P2pRepository $p2pRepository = null;

    /**
     * @var Rp2pRepository|null RP2P repository for approval gate
     */
    private ?Rp2pRepository $rp2pRepository = null;

    /**
     * @var P2pTransactionSenderInterface|null Sender for approved P2P transactions
     */
    private ?P2pTransactionSenderInterface $p2pTransactionSender = null;

    /**
     * @var P2pServiceInterface|null P2P service for cancel notification propagation
     */
    private ?P2pServiceInterface $p2pService = null;

    /**
     * @var Rp2pCandidateRepository|null RP2P candidate repository for multi-candidate selection
     */
    private ?Rp2pCandidateRepository $rp2pCandidateRepository = null;

    /**
     * @var P2pApprovalService|null Shared approve/reject commit point — CLI, API, and GUI all route through this so P2P_APPROVED / P2P_REJECTED fire once per operator decision regardless of entry surface.
     */
    private ?P2pApprovalService $approvalService = null;

    /**
     * Constructor
     *
     * @param Session $session
     * @param ContactService $contactService
     * @param TransactionService $transactionService
     */
    public function __construct(
        Session $session,
        ContactService $contactService,
        TransactionService $transactionService
        )
    {
        $this->session = $session;
        $this->contactService = $contactService;
        $this->transactionService = $transactionService;
    }

    /**
     * Set P2P approval gate dependencies (setter injection)
     *
     * @param P2pRepository $p2pRepository
     * @param Rp2pRepository $rp2pRepository
     * @param P2pTransactionSenderInterface $sender
     * @param P2pServiceInterface $p2pService
     * @return void
     */
    public function setP2pApprovalDependencies(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        P2pTransactionSenderInterface $sender,
        P2pServiceInterface $p2pService,
        ?Rp2pCandidateRepository $rp2pCandidateRepository = null
    ): void {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->p2pTransactionSender = $sender;
        $this->p2pService = $p2pService;
        $this->rp2pCandidateRepository = $rp2pCandidateRepository;
    }

    /**
     * Inject the shared approve/reject commit-point service so the GUI
     * delegates to the same logic as CLI/API. When set, handleApproveP2p()
     * and handleRejectP2p() route through it; otherwise they error out so
     * bootstrap misconfigurations surface immediately instead of silently
     * skipping the event dispatch.
     */
    public function setApprovalService(P2pApprovalService $service): void
    {
        $this->approvalService = $service;
    }

    /**
     * Handle send eIOU form submission
     *
     * This method uses InputValidator and Security classes to validate and sanitize
     * all user input before processing the transaction.
     * Uses JSON output mode for proper message handling.
     *
     * @return void
     */
    public function handleSendEIOU(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes

        // Sanitize input data
        $recipient = Security::sanitizeInput($_POST['recipient'] ?? '');
        $manualRecipient = Security::sanitizeInput($_POST['manual_recipient'] ?? '');
        $addressType = Security::sanitizeInput($_POST['address_type'] ?? '');
        $amount = $_POST['amount'] ?? '';
        $currency = $_POST['currency'] ?? '';

        // Determine final recipient based on input method
        if (!empty($manualRecipient)) {
            // Manual address entry - use as-is
            $finalRecipient = $manualRecipient;
        } elseif (!empty($recipient) && !empty($addressType)) {
            // Contact selected with address type - check for duplicate names
            $allMatches = $this->contactService->lookupAllByName($recipient);
            if (count($allMatches) > 1) {
                MessageHelper::redirectMessage('Multiple contacts named "' . htmlspecialchars($recipient) . '". Please use an address directly.', 'error');
                return;
            }
            $contactInfo = $allMatches[0] ?? null;
            if ($contactInfo && isset($contactInfo[$addressType]) && !empty($contactInfo[$addressType])) {
                $finalRecipient = $contactInfo[$addressType];
            } else {
                MessageHelper::redirectMessage('Selected address type not available for contact', 'error');
                return;
            }
        } else {
            // Fallback to recipient name (will be resolved by backend)
            $finalRecipient = $recipient;
        }

        // Validate required fields
        if (empty($finalRecipient) || empty($amount) || empty($currency)) {
            $message = 'All fields are required';
            $messageType = 'error';
        } else {
            // Validate amount using InputValidator
            $amountValidation = InputValidator::validateAmount($amount, $currency);
            if (!$amountValidation['valid']) {
                MessageHelper::redirectMessage('Invalid amount: ' . $amountValidation['error'], 'error');
                return;
            }

            // Validate currency
            $currencyValidation = InputValidator::validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                MessageHelper::redirectMessage('Invalid currency: ' . $currencyValidation['error'], 'error');
                return;
            }

            // Validate recipient address or contact name
            $addressValidation = InputValidator::validateAddress($finalRecipient);
            $contactNameValidation = InputValidator::validateContactName($finalRecipient);

            if (!$addressValidation['valid'] && !$contactNameValidation['valid']) {
                MessageHelper::redirectMessage('Invalid recipient: must be a valid address or contact name', 'error');
                return;
            }

            // Check if recipient is one of user's own addresses (self-send prevention)
            if ($addressValidation['valid']) {
                $userContext = UserContext::getInstance();
                $selfSendValidation = InputValidator::validateNotSelfSend($finalRecipient, $userContext);
                if (!$selfSendValidation['valid']) {
                    Logger::getInstance()->warning("Self-send transaction attempted", [
                        'recipient' => $finalRecipient,
                        'error' => $selfSendValidation['error']
                    ]);
                    MessageHelper::redirectMessage('Cannot send to yourself: ' . $selfSendValidation['error'], 'error');
                    return;
                }
            }

            // Use sanitized values
            $amount = $amountValidation['value'];
            $currency = $currencyValidation['value'];

            // Get optional description (sanitized)
            $description = Security::sanitizeInput($_POST['description'] ?? '');
            $description = !empty($description) ? $description : null;

            // Check best-fee route option (experimental)
            $bestFee = !empty($_POST['best_fee']);

            // Create argv array with --json flag for structured output
            $argv = ['eiou', 'send', $finalRecipient, $amount, $currency, $description, '--json'];

            if ($bestFee) {
                $argv[] = '--best';
            }

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                if (method_exists($this->transactionService, 'sendEiou')) {
                    $this->transactionService->sendEiou($argv, $outputManager);
                    $output = ob_get_clean();

                    // Parse JSON output using MessageHelper
                    $messageInfo = MessageHelper::parseCliJsonOutput($output);
                    $message = $messageInfo['message'];
                    $messageType = $messageInfo['type'];
                } else {
                    ob_end_clean();
                    $message = 'Transaction service not available';
                    $messageType = 'error';
                }
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use Logger for exception logging
                Logger::getInstance()->logException($e, [
                    'controller' => 'TransactionController',
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
     * Handle update checking requests (for Tor Browser polling)
     *
     * @return void
     */
    public function handleCheckUpdates(): void
    {
        if (!isset($_GET['check_updates']) || $_GET['check_updates'] !== '1') {
            return;
        }

        $lastCheckTime = $_GET['last_check'] ?? 0;

        // We need to check for new transactions and contact requests
        if (function_exists('checkForNewTransactions') && function_exists('checkForNewContactRequests')) {
            $newTransactions = $this->transactionService->checkForNewTransactions($lastCheckTime);
            $newContactRequests = $this->contactService->checkForNewContactRequests($lastCheckTime);

            if ($newTransactions || $newContactRequests) {
                echo "new_transaction:" . ($newTransactions ? '1' : '0') . "\n";
                echo "new_contact_request:" . ($newContactRequests ? '1' : '0') . "\n";
                echo "timestamp:" . time() . "\n";
            } else {
                echo "no_updates\n";
            }
        } else {
            echo "no_updates\n";
        }
        exit;
    }

    /**
     * Handle P2P transaction approval (AJAX)
     *
     * @return void
     */
    public function handleApproveP2p(): void
    {
        header('Content-Type: application/json');

        $this->session->verifyCSRFToken(false);

        if ($this->approvalService === null) {
            echo json_encode(['success' => false, 'error' => 'missing_dependencies', 'message' => 'P2P approval not configured']);
            return;
        }

        $hash = Security::sanitizeInput($_POST['hash'] ?? '');
        if (empty($hash)) {
            echo json_encode(['success' => false, 'error' => 'missing_hash', 'message' => 'Transaction hash is required']);
            return;
        }

        $candidateId = isset($_POST['candidate_id']) ? (int) $_POST['candidate_id'] : null;

        $result = $this->approvalService->approve($hash, null, $candidateId);
        if (!$result['success']) {
            Logger::getInstance()->info('P2P approval rejected by service', [
                'hash' => $hash,
                'candidate_id' => $candidateId,
                'code' => $result['code'],
            ]);
            echo json_encode([
                'success' => false,
                'error' => $result['code'],
                'message' => $result['message'],
            ]);
            return;
        }

        Logger::getInstance()->info('P2P transaction approved by user', [
            'hash' => $hash,
            'candidate_id' => $candidateId,
            'mode' => $result['mode'] ?? null,
        ]);
        echo json_encode(['success' => true]);
    }

    /**
     * Handle P2P transaction rejection (AJAX)
     *
     * @return void
     */
    public function handleRejectP2p(): void
    {
        header('Content-Type: application/json');

        $this->session->verifyCSRFToken(false);

        if ($this->approvalService === null) {
            echo json_encode(['success' => false, 'error' => 'missing_dependencies', 'message' => 'P2P approval not configured']);
            return;
        }

        $hash = Security::sanitizeInput($_POST['hash'] ?? '');
        if (empty($hash)) {
            echo json_encode(['success' => false, 'error' => 'missing_hash', 'message' => 'Transaction hash is required']);
            return;
        }

        $result = $this->approvalService->reject($hash);
        if (!$result['success']) {
            echo json_encode([
                'success' => false,
                'error' => $result['code'],
                'message' => $result['message'],
            ]);
            return;
        }

        Logger::getInstance()->info('P2P transaction rejected by user', ['hash' => $hash]);
        echo json_encode(['success' => true]);
    }

    /**
     * Handle fetching P2P candidates for multi-candidate approval (AJAX)
     *
     * @return void
     */
    public function handleGetP2pCandidates(): void
    {
        header('Content-Type: application/json');

        $this->session->verifyCSRFToken(false);

        if ($this->p2pRepository === null || $this->rp2pCandidateRepository === null) {
            echo json_encode(['success' => false, 'error' => 'missing_dependencies', 'message' => 'Candidate lookup not configured']);
            return;
        }

        $hash = Security::sanitizeInput($_POST['hash'] ?? '');
        if (empty($hash)) {
            echo json_encode(['success' => false, 'error' => 'missing_hash', 'message' => 'Transaction hash is required']);
            return;
        }

        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'Transaction not found or not awaiting approval']);
            return;
        }

        if (empty($p2p['destination_address'])) {
            echo json_encode(['success' => false, 'error' => 'not_originator', 'message' => 'Only the transaction originator can view candidates']);
            return;
        }

        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);
        $baseAmount = ($p2p['amount'] instanceof \Eiou\Core\SplitAmount) ? $p2p['amount'] : \Eiou\Core\SplitAmount::from($p2p['amount']);
        $myFeeAmount = isset($p2p['my_fee_amount']) && $p2p['my_fee_amount'] instanceof \Eiou\Core\SplitAmount ? $p2p['my_fee_amount'] : \Eiou\Core\SplitAmount::zero();

        $result = [];
        for ($i = 0; $i < count($candidates); $i++) {
            $c = $candidates[$i];
            $result[] = [
                'id' => (int) $c['id'],
                'amount' => ($c['amount'] instanceof \Eiou\Core\SplitAmount) ? $c['amount'] : \Eiou\Core\SplitAmount::from($c['amount']),
                'fee_amount' => ($c['fee_amount'] instanceof \Eiou\Core\SplitAmount) ? $c['fee_amount'] : \Eiou\Core\SplitAmount::from($c['fee_amount']),
                'sender_address' => $c['sender_address'],
            ];
        }

        echo json_encode([
            'success' => true,
            'base_amount' => $baseAmount,
            'my_fee_amount' => $myFeeAmount,
            'candidates' => $result,
        ]);
    }

    /**
     * Route transaction actions based on POST data
     *
     * @return void
     */
    public function routeAction(): void
    {
        // Handle GET requests for update checking
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
            $this->handleCheckUpdates();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'sendEIOU':
                $this->handleSendEIOU();
                break;
            case 'approveP2pTransaction':
                $this->handleApproveP2p();
                break;
            case 'rejectP2pTransaction':
                $this->handleRejectP2p();
                break;
            case 'getP2pCandidates':
                $this->handleGetP2pCandidates();
                break;
            case 'getTransactionByTxid':
                $this->handleGetTransactionByTxid();
                break;
        }
    }

    /**
     * AJAX: return the full formatted transaction data for a given txid.
     * Used by the GUI to open the transaction modal from payment request rows.
     */
    private function handleGetTransactionByTxid(): void
    {
        header('Content-Type: application/json');

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCSRFToken($csrfToken, false)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }

        $txid = trim($_POST['txid'] ?? '');
        if (empty($txid)) {
            echo json_encode(['success' => false, 'error' => 'Missing txid']);
            return;
        }

        $rows = $this->transactionService->getByTxid($txid);
        $tx   = is_array($rows) ? ($rows[0] ?? null) : null;

        if (!$tx) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            return;
        }

        $userContext   = UserContext::getInstance();
        $userAddresses = array_values($userContext->getUserLocaters());
        $formatted     = TransactionFormatter::formatHistory($tx, $userAddresses);

        // Format the date the same way the wallet page does
        $formatted['date'] = !empty($formatted['date']) ? formatTimestamp((string)$formatted['date']) : '';

        echo json_encode(['success' => true, 'transaction' => $formatted]);
    }
}
