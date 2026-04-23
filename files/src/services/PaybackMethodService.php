<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\PaybackMethodRepository;
use Eiou\Security\KeyEncryption;
use Eiou\Utils\Logger;
use Eiou\Validators\PaybackMethodTypeValidator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Core CRUD orchestrator for the user's payback-methods profile.
 *
 * Wraps PaybackMethodRepository, PaybackMethodTypeValidator, and KeyEncryption
 * so that callers (CLI, HTTP API, GUI controller) can operate on a clean
 * surface:
 *
 *   list(), get(methodId), getReveal(methodId),
 *   add(type, label, currency, fields, sharePolicy, priority),
 *   update(methodId, changes),
 *   remove(methodId),
 *   setSharePolicy(methodId, policy),
 *   listShareable(currency) — used by the responder on a fetch request.
 *
 * Row shape returned by public list/get (without reveal):
 *   ['method_id', 'type', 'label', 'currency', 'priority', 'enabled',
 *    'share_policy', 'settlement_min_unit', 'settlement_min_unit_exponent',
 *    'created_at', 'updated_at', 'masked_display']
 *
 * Sensitive fields never leave the service unless getReveal() is called.
 */
class PaybackMethodService
{
    public const SHARE_POLICIES = ['auto', 'prompt', 'never'];

    private PaybackMethodRepository $repo;
    private PaybackMethodTypeValidator $validator;
    private SettlementPrecisionService $precision;
    private ?Logger $logger;
    private ?PaybackMethodTypeRegistry $registry;

    public function __construct(
        PaybackMethodRepository $repo,
        ?PaybackMethodTypeValidator $validator = null,
        ?SettlementPrecisionService $precision = null,
        ?Logger $logger = null,
        ?PaybackMethodTypeRegistry $registry = null
    ) {
        $this->repo = $repo;
        $this->registry = $registry;
        $this->validator = $validator ?? new PaybackMethodTypeValidator($registry);
        $this->precision = $precision ?? new SettlementPrecisionService($registry);
        $this->logger = $logger;
    }

    // =========================================================================
    // Public listing (no reveal)
    // =========================================================================

    /**
     * List methods with sensitive field masking applied.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $currency = null, bool $enabledOnly = true): array
    {
        $rows = $this->repo->listMethods($currency, $enabledOnly);
        return array_map(fn($row) => $this->toPublicShape($row), $rows);
    }

    public function get(string $methodId): ?array
    {
        $row = $this->repo->getByMethodId($methodId);
        return $row ? $this->toPublicShape($row) : null;
    }

    /**
     * Return the full decrypted method — caller must have already verified
     * the sensitive-action session gate before invoking this.
     */
    public function getReveal(string $methodId): ?array
    {
        $row = $this->repo->getByMethodId($methodId);
        if ($row === null) {
            return null;
        }
        $public = $this->toPublicShape($row);
        $public['fields'] = $this->decryptFields($row);
        return $public;
    }

    // =========================================================================
    // Mutations
    // =========================================================================

    /**
     * Add a new payback method.
     *
     * @return array{method_id: string, errors: list<array>}
     */
    public function add(
        string $type,
        string $label,
        string $currency,
        array $fields,
        string $sharePolicy = 'auto',
        int $priority = 100
    ): array {
        $errors = $this->prevalidate($type, $label, $currency, $fields, $sharePolicy, $priority);
        if ($errors !== []) {
            return ['method_id' => '', 'errors' => $errors];
        }

        $methodId = $this->generateUuidV4();
        $encrypted = $this->encryptFields($methodId, $fields);

        [$minUnit, $exp] = $this->precision->defaultFor($type, $currency);

        $ok = $this->repo->createMethod([
            'method_id' => $methodId,
            'type' => $type,
            'label' => $label,
            'currency' => $currency,
            'encrypted_fields' => json_encode($encrypted),
            'fields_version' => 1,
            'settlement_min_unit' => $minUnit,
            'settlement_min_unit_exponent' => $exp,
            'priority' => $priority,
            'enabled' => 1,
            'share_policy' => $sharePolicy,
        ]);
        if ($ok === false) {
            throw new RuntimeException('Failed to create payback method');
        }
        $this->log('info', 'payback_method_added', [
            'method_id' => $methodId, 'type' => $type, 'currency' => $currency,
        ]);
        return ['method_id' => $methodId, 'errors' => []];
    }

    /**
     * Update fields / label / share_policy / priority / enabled on an existing row.
     *
     * Accepted keys in $changes: 'label', 'share_policy', 'priority', 'enabled', 'fields'.
     * Type and currency are immutable (delete + recreate to change those).
     *
     * @return list<array> validation errors (empty = success)
     */
    public function update(string $methodId, array $changes): array
    {
        $existing = $this->repo->getByMethodId($methodId);
        if ($existing === null) {
            return [['field' => 'method_id', 'code' => 'not_found', 'message' => 'No such method']];
        }

        $db = [];
        $errors = [];

        if (array_key_exists('label', $changes)) {
            if (!is_string($changes['label']) || $changes['label'] === '' || strlen($changes['label']) > 128) {
                $errors[] = ['field' => 'label', 'code' => 'invalid_value', 'message' => 'label must be 1–128 chars'];
            } else {
                $db['label'] = $changes['label'];
            }
        }
        if (array_key_exists('share_policy', $changes)) {
            if (!in_array($changes['share_policy'], self::SHARE_POLICIES, true)) {
                $errors[] = ['field' => 'share_policy', 'code' => 'invalid_value',
                    'message' => 'share_policy must be one of: ' . implode(', ', self::SHARE_POLICIES)];
            } else {
                $db['share_policy'] = $changes['share_policy'];
            }
        }
        if (array_key_exists('priority', $changes)) {
            if (!is_int($changes['priority']) || $changes['priority'] < 0 || $changes['priority'] > 9999) {
                $errors[] = ['field' => 'priority', 'code' => 'invalid_value',
                    'message' => 'priority must be an integer 0–9999'];
            } else {
                $db['priority'] = $changes['priority'];
            }
        }
        if (array_key_exists('enabled', $changes)) {
            $db['enabled'] = $changes['enabled'] ? 1 : 0;
        }
        if (array_key_exists('fields', $changes)) {
            if (!is_array($changes['fields'])) {
                $errors[] = ['field' => 'fields', 'code' => 'invalid_type',
                    'message' => 'fields must be an object'];
            } else {
                $fieldErrors = $this->validator->validate(
                    $existing['type'], $existing['currency'], $changes['fields']
                );
                if ($fieldErrors !== []) {
                    $errors = array_merge($errors, $fieldErrors);
                } else {
                    $encrypted = $this->encryptFields($methodId, $changes['fields']);
                    $db['encrypted_fields'] = json_encode($encrypted);
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }
        if ($db === []) {
            return [];
        }

        $this->repo->updateByMethodId($methodId, $db);
        $this->log('info', 'payback_method_updated', [
            'method_id' => $methodId, 'fields_changed' => array_keys($db),
        ]);
        return [];
    }

    public function remove(string $methodId): bool
    {
        $existing = $this->repo->getByMethodId($methodId);
        if ($existing === null) {
            return false;
        }
        $deleted = $this->repo->deleteByMethodId($methodId);
        if ($deleted > 0) {
            $this->log('info', 'payback_method_removed', [
                'method_id' => $methodId, 'type' => $existing['type'],
            ]);
            return true;
        }
        return false;
    }

    public function setSharePolicy(string $methodId, string $policy): array
    {
        if (!in_array($policy, self::SHARE_POLICIES, true)) {
            return [['field' => 'share_policy', 'code' => 'invalid_value',
                'message' => 'share_policy must be one of: ' . implode(', ', self::SHARE_POLICIES)]];
        }
        if ($this->repo->getByMethodId($methodId) === null) {
            return [['field' => 'method_id', 'code' => 'not_found', 'message' => 'No such method']];
        }
        $this->repo->updateByMethodId($methodId, ['share_policy' => $policy]);
        $this->log('info', 'payback_method_share_policy_changed', [
            'method_id' => $methodId, 'share_policy' => $policy,
        ]);
        return [];
    }

    // =========================================================================
    // Shared-with-contacts views (used by the responder)
    // =========================================================================

    /**
     * Return every shareable method matching a currency, fully decrypted.
     * The caller (responder service) applies further per-contact filtering.
     *
     * @return list<array<string, mixed>>
     */
    public function listShareable(?string $currency = null): array
    {
        $rows = $currency !== null
            ? $this->repo->listShareableForCurrency($currency)
            : $this->repo->listAllShareable();
        return array_map(function ($row) {
            return [
                'method_id' => $row['method_id'],
                'type' => $row['type'],
                'label' => $row['label'],
                'currency' => $row['currency'],
                'fields' => $this->decryptFields($row),
                'priority' => (int) ($row['priority'] ?? 100),
                'share_policy' => $row['share_policy'] ?? 'auto',
                'settlement_min_unit' => (int) ($row['settlement_min_unit'] ?? 1),
                'settlement_min_unit_exponent' => (int) ($row['settlement_min_unit_exponent'] ?? -8),
            ];
        }, $rows);
    }

    // =========================================================================
    // Shaping + encryption helpers (protected so tests can override)
    // =========================================================================

    /**
     * Public-facing row shape. Sensitive fields replaced with a short mask.
     */
    protected function toPublicShape(array $row): array
    {
        $fields = [];
        try {
            $fields = $this->decryptFields($row);
        } catch (\Throwable $e) {
            // Masking path shouldn't crash the list view — log and carry on.
            $this->log('warning', 'payback_method_decrypt_failed', [
                'method_id' => $row['method_id'] ?? '?', 'error' => $e->getMessage(),
            ]);
            $fields = [];
        }
        return [
            'method_id' => $row['method_id'],
            'type' => $row['type'],
            'label' => $row['label'],
            'currency' => $row['currency'],
            'priority' => (int) ($row['priority'] ?? 100),
            'enabled' => (int) ($row['enabled'] ?? 1) === 1,
            'share_policy' => $row['share_policy'] ?? 'auto',
            'settlement_min_unit' => (int) ($row['settlement_min_unit'] ?? 1),
            'settlement_min_unit_exponent' => (int) ($row['settlement_min_unit_exponent'] ?? -8),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'masked_display' => $this->maskForType($row['type'], $fields),
        ];
    }

    /**
     * Short redacted string shown on list rows before reveal.
     *
     * Core-shipped types: `bank_wire` (shows the last 4 of the IBAN or
     * account number) and `custom` (shows the first 8 chars of the free-
     * text details). Plugin-registered types fall back to a generic mask
     * until plugins can register their own masker — for now they see
     * `•••` which is safe but uninformative.
     */
    protected function maskForType(string $type, array $fields): string
    {
        switch ($type) {
            case 'bank_wire':
                $tail = $fields['iban'] ?? $fields['account_number'] ?? '';
                return '••••' . substr($tail, -4);
            case 'custom':
                // `details` is user-authored free text — not sensitive like an
                // IBAN, so show a wide preview and let the table's own CSS
                // ellipsis handle truncation at the column boundary.
                $details = (string) ($fields['details'] ?? '');
                return mb_strlen($details) > 80 ? mb_substr($details, 0, 80) . '…' : $details;
        }
        // Plugin-registered types get to define their own masked display.
        if ($this->registry !== null) {
            $typeContract = $this->registry->get($type);
            if ($typeContract !== null) {
                return $typeContract->mask($fields);
            }
        }
        return '•••';
    }

    /**
     * Encrypt fields JSON with KeyEncryption, AAD bound to method_id.
     * Protected so tests can override without needing a master key file.
     *
     * @return array KeyEncryption::encrypt() return value
     */
    protected function encryptFields(string $methodId, array $fields): array
    {
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to JSON-encode payback method fields');
        }
        return KeyEncryption::encrypt($json, "payback:$methodId");
    }

    /**
     * Decrypt the row's encrypted_fields JSON column.
     * Protected so tests can override.
     */
    protected function decryptFields(array $row): array
    {
        $raw = $row['encrypted_fields'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $blob = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($blob)) {
            throw new RuntimeException('encrypted_fields is malformed');
        }
        $plain = KeyEncryption::decrypt($blob);
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Run validation for add() — wraps the type validator and the common shape rules.
     *
     * @return list<array>
     */
    private function prevalidate(
        string $type,
        string $label,
        string $currency,
        array $fields,
        string $sharePolicy,
        int $priority
    ): array {
        $errors = [];
        if ($label === '' || strlen($label) > 128) {
            $errors[] = ['field' => 'label', 'code' => 'invalid_value', 'message' => 'label must be 1–128 chars'];
        }
        if (!in_array($sharePolicy, self::SHARE_POLICIES, true)) {
            $errors[] = ['field' => 'share_policy', 'code' => 'invalid_value',
                'message' => 'share_policy must be one of: ' . implode(', ', self::SHARE_POLICIES)];
        }
        if ($priority < 0 || $priority > 9999) {
            $errors[] = ['field' => 'priority', 'code' => 'invalid_value',
                'message' => 'priority must be an integer 0–9999'];
        }
        return array_merge($errors, $this->validator->validate($type, $currency, $fields));
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function log(string $level, string $event, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->{$level}($event, $ctx);
    }
}
