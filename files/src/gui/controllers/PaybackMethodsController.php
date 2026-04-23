<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Services\PaybackMethodService;
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

    public function routeAction(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? '';

            if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '', false)) {
                $this->respond(['success' => false, 'error' => 'csrf_error'], 403);
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
                default:
                    $this->respond(['success' => false, 'error' => 'unknown_action'], 400);
            }
        } catch (PaybackMethodsControllerResponseSent $sent) {
            throw $sent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'payback_methods_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respond(
                ['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
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
        $this->respond(['success' => true, 'methods' => $rows, 'count' => count($rows)]);
    }

    private function getMethod(): void
    {
        $id = $this->requireMethodId();
        $row = $this->svc->get($id);
        if ($row === null) {
            $this->respond(['success' => false, 'error' => 'not_found'], 404);
        }
        $this->respond(['success' => true, 'method' => $row]);
    }

    private function revealMethod(): void
    {
        $id = $this->requireMethodId();
        $row = $this->svc->getReveal($id);
        if ($row === null) {
            $this->respond(['success' => false, 'error' => 'not_found'], 404);
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
            $this->respond([
                'success' => false,
                'error' => 'validation_failed',
                'errors' => $result['errors'],
            ], 400);
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
                $this->respond(['success' => false, 'error' => 'not_found'], 404);
            }
            $this->respond([
                'success' => false, 'error' => 'validation_failed', 'errors' => $errors,
            ], 400);
        }
        Logger::getInstance()->info('payback_method_update_via_gui', ['method_id' => $id]);
        $this->respond(['success' => true, 'method_id' => $id]);
    }

    private function deleteMethod(): void
    {
        $id = $this->requireMethodId();
        if (!$this->svc->remove($id)) {
            $this->respond(['success' => false, 'error' => 'not_found'], 404);
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
                $this->respond(['success' => false, 'error' => 'not_found'], 404);
            }
            $this->respond([
                'success' => false, 'error' => 'validation_failed', 'errors' => $errors,
            ], 400);
        }
        Logger::getInstance()->info('payback_method_share_policy_via_gui', [
            'method_id' => $id, 'share_policy' => $policy,
        ]);
        $this->respond(['success' => true, 'method_id' => $id, 'share_policy' => $policy]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireMethodId(): string
    {
        $id = (string) ($_POST['method_id'] ?? '');
        if ($id === '') {
            $this->respond(['success' => false, 'error' => 'missing_method_id'], 400);
        }
        // Method ids are uuid v4.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            $this->respond(['success' => false, 'error' => 'invalid_method_id'], 400);
        }
        return $id;
    }

    private function requireSensitive(): void
    {
        if (!$this->session->hasSensitiveAccess()) {
            $this->respond(['success' => false, 'error' => 'sensitive_access_required'], 403);
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
}

/**
 * Sentinel exception so routeAction can unwind after emitting a response.
 * Mirrors ApiKeysControllerResponseSent.
 */
class PaybackMethodsControllerResponseSent extends \RuntimeException
{
}
