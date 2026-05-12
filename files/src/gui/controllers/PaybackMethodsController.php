<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Helpers\GuiErrorResponse;
use Eiou\Gui\Includes\Session;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\PaybackMethodService;
use Eiou\Services\ServiceContainer;
use Eiou\Utils\Logger;
use Throwable;

/**
 * GUI AJAX controller for payback-methods management.
 *
 * Every state-changing action and every reveal is gated by the same
 * sensitive-action session grant the API-keys controller uses so a
 * remembered device still re-prompts for the auth code before it can
 * mint/reveal/remove a payback method.
 */
class PaybackMethodsController
{
    private Session $session;
    private PaybackMethodService $svc;

    public function __construct(Session $session, PaybackMethodService $svc)
    {
        $this->session = $session;
        $this->svc = $svc;
    }

    /**
     * Register every owned action with the shared GuiActionRegistry.
     *
     * routeAction() centralizes CSRF + sensitive-access gates and the
     * sentinel-exception unwind, and per-action handlers are private.
     * Rather than expose them all, every entry registers a closure that
     * delegates to routeAction() and catches the
     * PaybackMethodsControllerResponseSent sentinel locally — same as
     * the legacy Functions.php branch did. Tier is TIER_AUTH because
     * routeAction() does its own non-rotating CSRF check; gating CSRF
     * twice would require fighting routeAction()'s 403 envelope shape
     * with the registry's, and the controller's shape is what the JS
     * client expects.
     */
    public function registerActions(GuiActionRegistry $registry): void
    {
        $delegate = function (array $request): void {
            try {
                $this->routeAction();
            } catch (PaybackMethodsControllerResponseSent $sent) {
                // Response already emitted by the controller via respond().
            }
        };
        foreach ([
            'paybackMethodsList',
            'paybackMethodsGet',
            'paybackMethodsReveal',
            'paybackMethodsCreate',
            'paybackMethodsUpdate',
            'paybackMethodsDelete',
            'paybackMethodsSharePolicy',
            'paybackMethodsFetchFromContact',
        ] as $action) {
            $registry->register($action, $delegate, GuiActionRegistry::TIER_AUTH, 'core');
        }
    }

    public function routeAction(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? '';

            if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '', false)) {
                $this->respondError('csrf_invalid', 'Invalid CSRF token', 403);
            }

            switch ($action) {
                case 'paybackMethodsList':
                    $this->listMethods();
                    break;
                case 'paybackMethodsGet':
                    $this->getMethod();
                    break;
                case 'paybackMethodsReveal':
                    $this->requireSensitive();
                    $this->revealMethod();
                    break;
                case 'paybackMethodsCreate':
                    $this->requireSensitive();
                    $this->createMethod();
                    break;
                case 'paybackMethodsUpdate':
                    $this->requireSensitive();
                    $this->updateMethod();
                    break;
                case 'paybackMethodsDelete':
                    $this->requireSensitive();
                    $this->deleteMethod();
                    break;
                case 'paybackMethodsSharePolicy':
                    $this->requireSensitive();
                    $this->setSharePolicy();
                    break;
                case 'paybackMethodsFetchFromContact':
                    $this->fetchFromContact();
                    break;
                default:
                    $this->respondError('unknown_action', 'Unknown action', 400);
            }
        } catch (PaybackMethodsControllerResponseSent $sent) {
            throw $sent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'payback_methods_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respondError('server_error', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Action handlers
    // =========================================================================

    private function listMethods(): void
    {
        $currency = isset($_POST['currency']) ? strtoupper((string) $_POST['currency']) : null;
        if ($currency === '') {
            $currency = null;
        }
        $enabledOnly = !(isset($_POST['all']) && $_POST['all'] === '1');
        $rows = $this->svc->list($currency, $enabledOnly);
        $this->respond([
            'success' => true,
            'methods' => $rows,
            'count' => count($rows),
            'sensitive_access' => $this->session->hasSensitiveAccess(),
            'seconds_remaining' => $this->session->sensitiveAccessSecondsRemaining(),
        ]);
    }

    private function getMethod(): void
    {
        $id = $this->requireMethodId();
        $row = $this->svc->get($id);
        if ($row === null) {
            $this->respondError('not_found', 'Payback method not found', 404);
        }
        $this->respond(['success' => true, 'method' => $row]);
    }

    private function revealMethod(): void
    {
        $id = $this->requireMethodId();
        $row = $this->svc->getReveal($id);
        if ($row === null) {
            $this->respondError('not_found', 'Payback method not found', 404);
        }
        $this->respond(['success' => true, 'method' => $row]);
    }

    private function createMethod(): void
    {
        $type = (string) ($_POST['type'] ?? '');
        $label = (string) ($_POST['label'] ?? '');
        $currency = strtoupper((string) ($_POST['currency'] ?? ''));
        $fields = isset($_POST['fields']) && is_string($_POST['fields'])
            ? (json_decode($_POST['fields'], true) ?? [])
            : ($_POST['fields'] ?? []);
        if (!is_array($fields)) {
            $fields = [];
        }
        $sharePolicy = (string) ($_POST['share_policy'] ?? 'auto');
        $priority = isset($_POST['priority']) ? (int) $_POST['priority'] : 100;

        $result = $this->svc->add($type, $label, $currency, $fields, $sharePolicy, $priority);
        if ($result['errors'] !== []) {
            $this->respondError(
                'validation_failed',
                'Validation failed',
                400,
                ['errors' => $result['errors']]
            );
        }
        Logger::getInstance()->info('payback_method_create_via_gui', [
            'method_id' => $result['method_id'], 'type' => $type, 'currency' => $currency,
        ]);
        $this->respond(['success' => true, 'method_id' => $result['method_id']]);
    }

    private function updateMethod(): void
    {
        $id = $this->requireMethodId();
        $changes = [];
        foreach (['label', 'share_policy', 'priority', 'enabled'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $changes[$key] = $key === 'priority' ? (int) $_POST[$key]
                    : ($key === 'enabled' ? (int) (bool) $_POST[$key] : $_POST[$key]);
            }
        }
        if (isset($_POST['fields']) && is_string($_POST['fields'])) {
            $parsed = json_decode($_POST['fields'], true);
            if (is_array($parsed)) {
                $changes['fields'] = $parsed;
            }
        }
        $errors = $this->svc->update($id, $changes);
        if ($errors !== []) {
            $codes = array_column($errors, 'code');
            if (in_array('not_found', $codes, true)) {
                $this->respondError('not_found', 'Payback method not found', 404);
            }
            $this->respondError(
                'validation_failed',
                'Validation failed',
                400,
                ['errors' => $errors]
            );
        }
        Logger::getInstance()->info('payback_method_update_via_gui', ['method_id' => $id]);
        $this->respond(['success' => true, 'method_id' => $id]);
    }

    private function deleteMethod(): void
    {
        $id = $this->requireMethodId();
        if (!$this->svc->remove($id)) {
            $this->respondError('not_found', 'Payback method not found', 404);
        }
        Logger::getInstance()->info('payback_method_delete_via_gui', ['method_id' => $id]);
        $this->respond(['success' => true, 'method_id' => $id, 'deleted' => true]);
    }

    private function setSharePolicy(): void
    {
        $id = $this->requireMethodId();
        $policy = (string) ($_POST['share_policy'] ?? '');
        $errors = $this->svc->setSharePolicy($id, $policy);
        if ($errors !== []) {
            $codes = array_column($errors, 'code');
            if (in_array('not_found', $codes, true)) {
                $this->respondError('not_found', 'Payback method not found', 404);
            }
            $this->respondError(
                'validation_failed',
                'Validation failed',
                400,
                ['errors' => $errors]
            );
        }
        Logger::getInstance()->info('payback_method_share_policy_via_gui', [
            'method_id' => $id, 'share_policy' => $policy,
        ]);
        $this->respond(['success' => true, 'method_id' => $id, 'share_policy' => $policy]);
    }

    /**
     * Ephemerally fetch a contact's shareable payback methods via a
     * synchronous E2E round-trip. Nothing about the response is persisted
     * on this node — the data is returned inline to the GUI and forgotten.
     * Separate from ReceivedPaybackMethodService's async/persist path.
     */
    private function fetchFromContact(): void
    {
        $address = trim((string) ($_POST['address'] ?? ''));
        if ($address === '') {
            $this->respondError('missing_address', 'Address is required', 400);
        }
        $currency = isset($_POST['currency']) ? strtoupper((string) $_POST['currency']) : null;
        if ($currency === '' || $currency === 'ALL') {
            $currency = null;
        }

        $container = ServiceContainer::getInstance();
        $currentUser = $container->getCurrentUser();
        $delivery = $container->getMessageDeliveryService();
        $transport = $container->getUtilityContainer()->getTransportUtility();

        $requestId = $this->generateUuidV4();
        $payload = [
            'type'            => 'message',
            'typeMessage'     => 'payback_methods',
            'action'          => 'request',
            'senderAddress'   => $transport->resolveUserAddressForTransport($address),
            'senderPublicKey' => $currentUser->getPublicKey() ?? '',
            'payload'         => [
                'request_id'      => $requestId,
                'currency'        => $currency,
                'max_age_seconds' => 0,
            ],
        ];

        $messageId = 'payback_methods-fetch-' . $requestId;
        $result = $delivery->sendMessage('payback_methods', $address, $payload, $messageId, false);

        if (empty($result['success'])) {
            $this->respondError(
                'delivery_failed',
                'Delivery to contact failed',
                502,
                ['detail' => $result['tracking']['stage'] ?? 'unknown']
            );
        }

        // Receiver echoes {success, status: 'received', response: {...}}. We
        // want the inner "response" with the actual methods list.
        $outer = $result['response'] ?? [];
        $inner = is_array($outer) ? ($outer['response'] ?? null) : null;
        if (!is_array($inner)) {
            $this->respondError(
                'unexpected_response',
                'Unexpected response shape from contact',
                502,
                ['raw' => $outer]
            );
        }

        $this->respond([
            'success'     => true,
            'status'      => $inner['status'] ?? 'unknown',
            'methods'     => $inner['methods'] ?? [],
            'ttl_seconds' => (int) ($inner['ttl_seconds'] ?? 0),
        ]);
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireMethodId(): string
    {
        $id = (string) ($_POST['method_id'] ?? '');
        if ($id === '') {
            $this->respondError('missing_method_id', 'Method id is required', 400);
        }
        // Method ids are uuid v4.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            $this->respondError('invalid_method_id', 'Invalid method id', 400);
        }
        return $id;
    }

    private function requireSensitive(): void
    {
        if (!$this->session->hasSensitiveAccess()) {
            $this->respondError(
                'sensitive_access_required',
                'Please re-enter your auth code to continue.',
                403
            );
        }
    }

    /**
     * Respond with JSON and short-circuit handling via a typed exception so
     * tests can observe the emission without `exit`ing the worker.
     *
     * @return never
     */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
        throw new PaybackMethodsControllerResponseSent($status);
    }

    /**
     * Emit a canonical GUI error envelope through the test-seam `respond()`.
     * Same contract as `ApiKeysController::respondError`. Optional extras
     * cover legacy fields (`errors` for the validation-failed shape, etc.).
     *
     * @param array<string,mixed> $extras
     */
    private function respondError(string $code, string $message, int $status, array $extras = []): void
    {
        $payload = GuiErrorResponse::make($code, $message);
        if ($extras !== []) {
            $payload = array_merge($payload, $extras);
        }
        $this->respond($payload, $status);
    }
}

/**
 * Sentinel exception so routeAction can unwind after emitting a response.
 * Mirrors ApiKeysControllerResponseSent.
 */
class PaybackMethodsControllerResponseSent extends \RuntimeException
{
}
