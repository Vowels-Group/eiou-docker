<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Gui\Includes\SessionKeys;
use Eiou\Services\PaymentRequestService;
use Eiou\Utils\Security;
use Eiou\Gui\Helpers\MessageHelper;

/**
 * Payment Request Controller
 *
 * Handles GUI POST actions for payment requests:
 *   createPaymentRequest  — create and send a request to a contact
 *   approvePaymentRequest — approve an incoming request (triggers sendEiou)
 *   declinePaymentRequest — decline an incoming request
 *   cancelPaymentRequest  — cancel an outgoing request
 */
class PaymentRequestController
{
    private Session $session;
    private PaymentRequestService $paymentRequestService;

    public function __construct(Session $session, PaymentRequestService $paymentRequestService)
    {
        $this->session = $session;
        $this->paymentRequestService = $paymentRequestService;
    }

    /**
     * Route the current POST action to the appropriate handler.
     */
    public function routeAction(): void
    {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'createPaymentRequest':
                $this->handleCreate();
                break;
            case 'approvePaymentRequest':
                $this->handleApprove();
                break;
            case 'declinePaymentRequest':
                $this->handleDecline();
                break;
            case 'cancelPaymentRequest':
                $this->handleCancel();
                break;
            case 'declineAllPaymentRequests':
                $this->handleDeclineAll();
                break;
            case 'cancelAllPaymentRequests':
                $this->handleCancelAll();
                break;
        }
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function handleCreate(): void
    {
        $this->session->verifyCSRFToken();

        $recipient    = Security::sanitizeInput($_POST['recipient']     ?? '');
        $amount       = $_POST['amount']   ?? '';
        $currency     = $_POST['currency'] ?? '';
        $description  = Security::sanitizeInput($_POST['description']   ?? '');
        $addressType  = Security::sanitizeInput($_POST['address_type']  ?? '');

        if (empty($recipient) || empty($amount) || empty($currency)) {
            MessageHelper::redirectMessage('Recipient, amount, and currency are required', 'error');
            return;
        }

        $result = $this->paymentRequestService->create(
            $recipient,
            $amount,
            $currency,
            !empty($description) ? $description : null,
            !empty($addressType) ? $addressType : null
        );

        if ($result['success']) {
            MessageHelper::redirectMessage('Payment request sent successfully', 'success');
        } else {
            MessageHelper::redirectMessage($result['error'] ?? 'Failed to send payment request', 'error');
        }
    }

    private function handleApprove(): void
    {
        $this->session->verifyCSRFToken();

        $requestId = Security::sanitizeInput($_POST['request_id'] ?? '');
        if (empty($requestId)) {
            MessageHelper::redirectMessage('Invalid request ID', 'error');
            return;
        }

        $result = $this->paymentRequestService->approve($requestId);

        if ($result['success']) {
            $msg = $result['message'] ?? 'Payment sent successfully';
            MessageHelper::redirectMessage($msg, 'success');
        } else {
            MessageHelper::redirectMessage($result['error'] ?? 'Failed to approve request', 'error');
        }
    }

    private function handleDecline(): void
    {
        $this->session->verifyCSRFToken();

        $requestId = Security::sanitizeInput($_POST['request_id'] ?? '');
        if (empty($requestId)) {
            MessageHelper::redirectMessage('Invalid request ID', 'error');
            return;
        }

        $result = $this->paymentRequestService->decline($requestId);

        if ($result['success']) {
            MessageHelper::redirectMessage('Payment request declined', 'info');
        } else {
            MessageHelper::redirectMessage($result['error'] ?? 'Failed to decline request', 'error');
        }
    }

    private function handleCancel(): void
    {
        $this->session->verifyCSRFToken();

        $requestId = Security::sanitizeInput($_POST['request_id'] ?? '');
        if (empty($requestId)) {
            MessageHelper::redirectMessage('Invalid request ID', 'error');
            return;
        }

        $result = $this->paymentRequestService->cancel($requestId);

        if ($result['success']) {
            MessageHelper::redirectMessage('Payment request cancelled', 'info');
        } else {
            MessageHelper::redirectMessage($result['error'] ?? 'Failed to cancel request', 'error');
        }
    }

    private function handleDeclineAll(): void
    {
        $this->session->verifyCSRFToken();

        $pending = $this->paymentRequestService->getAllForDisplay(1000)['incoming'] ?? [];
        $pending = array_filter($pending, fn($r) => ($r['status'] ?? '') === 'pending');

        $declined = 0;
        $failed = 0;
        foreach ($pending as $req) {
            $result = $this->paymentRequestService->decline($req['request_id']);
            if ($result['success']) { $declined++; } else { $failed++; }
        }

        $msg = "Declined $declined incoming request" . ($declined !== 1 ? 's' : '');
        if ($failed > 0) { $msg .= " ($failed failed)"; }
        MessageHelper::redirectMessage($msg, $failed > 0 ? 'warning' : 'info');
    }

    private function handleCancelAll(): void
    {
        $this->session->verifyCSRFToken();

        $pending = $this->paymentRequestService->getAllForDisplay(1000)['outgoing'] ?? [];
        $pending = array_filter($pending, fn($r) => ($r['status'] ?? '') === 'pending');

        $cancelled = 0;
        $failed = 0;
        foreach ($pending as $req) {
            $result = $this->paymentRequestService->cancel($req['request_id']);
            if ($result['success']) { $cancelled++; } else { $failed++; }
        }

        $msg = "Cancelled $cancelled outgoing request" . ($cancelled !== 1 ? 's' : '');
        if ($failed > 0) { $msg .= " ($failed failed)"; }
        MessageHelper::redirectMessage($msg, $failed > 0 ? 'warning' : 'info');
    }
}
