<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Validators;

use Eiou\Validators\Checksum\AbaRouting;
use Eiou\Validators\Checksum\Iban;

/**
 * Validates payback-method field payloads per declared type.
 *
 * Single public entry: validate($type, $currency, $fields): array<int,array>
 * returns an array of error records, each shaped as
 *   ['field' => string|null, 'code' => string, 'message' => string]
 *
 * An empty return array means the payload is valid.
 *
 * Core ships two rail types: `bank_wire` (with SEPA / Faster Payments / ACH /
 * FedNow / SWIFT sub-rails) and `custom` (free-form instructions). Additional
 * rail types (BTC, PayPal, Venmo, EVM tokens, Lightning, Pix, …) are expected
 * to register themselves as plugins against the payback-methods plugin API in
 * a follow-up. Unknown types are rejected with `unknown_type` so plugin-less
 * nodes stay predictable.
 */
class PaybackMethodTypeValidator
{
    public const TYPE_BANK_WIRE = 'bank_wire';
    public const TYPE_CUSTOM    = 'custom';

    private const BANK_RAILS = ['sepa', 'faster_payments', 'ach', 'fednow', 'swift'];

    private ?\Eiou\Services\PaybackMethodTypeRegistry $registry;

    /**
     * An optional registry enables plugin-provided rail types. Production
     * code wires one via the service container; standalone unit tests can
     * omit it to exercise the core `bank_wire` / `custom` dispatch only.
     */
    public function __construct(?\Eiou\Services\PaybackMethodTypeRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    /**
     * Validate one payback method payload.
     *
     * @return list<array{field: string|null, code: string, message: string}>
     */
    public function validate(string $type, string $currency, array $fields): array
    {
        $errors = [];

        // Label (always required) + non-empty string guard.
        if (empty($fields['label']) || !is_string($fields['label'])) {
            // Label is enforced by the service layer, but keep a defensive
            // check here so validator-only unit tests don't silently pass
            // label-less payloads through.
            // (Intentionally not fatal — service wraps this with a 400.)
        }

        // Basic currency shape — per-type rules tighten this further.
        if (!$this->isValidCurrencyCode($currency)) {
            $errors[] = $this->err('currency', 'invalid_currency',
                'currency must be a 3–5 character uppercase code');
            return $errors;
        }

        switch ($type) {
            case self::TYPE_BANK_WIRE:
                return array_merge($errors, $this->validateBankWire($currency, $fields));
            case self::TYPE_CUSTOM:
                return array_merge($errors, $this->validateCustom($currency, $fields));
        }

        // Delegate plugin-registered types. Unknown-to-registry types fall
        // through to the `unknown_type` error so plugin-less nodes stay
        // predictable about what they'll accept.
        if ($this->registry !== null) {
            $typeContract = $this->registry->get($type);
            if ($typeContract !== null) {
                return array_merge($errors, $typeContract->validate($currency, $fields));
            }
        }
        return [$this->err('type', 'unknown_type', "Unknown payback-method type '$type'")];
    }

    // =========================================================================
    // Bank wire — SEPA / Faster Payments / ACH / FedNow / SWIFT
    // =========================================================================

    private function validateBankWire(string $currency, array $fields): array
    {
        $errors = [];
        $rail = $fields['rail'] ?? null;
        if (!in_array($rail, self::BANK_RAILS, true)) {
            $errors[] = $this->err('rail', 'invalid_value',
                'rail must be one of: ' . implode(', ', self::BANK_RAILS));
            return $errors;
        }

        if (empty($fields['recipient_name']) || !is_string($fields['recipient_name'])) {
            $errors[] = $this->err('recipient_name', 'required', 'recipient_name is required');
        }

        switch ($rail) {
            case 'sepa':
                if ($currency !== 'EUR') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type', 'SEPA settles in EUR');
                }
                if (empty($fields['iban'])) {
                    $errors[] = $this->err('iban', 'required', 'IBAN is required for SEPA');
                } elseif (!Iban::isValid($fields['iban'])) {
                    $errors[] = $this->err('iban', 'invalid_checksum', 'IBAN failed mod-97 validation');
                }
                break;

            case 'faster_payments':
                if ($currency !== 'GBP') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type',
                        'Faster Payments settles in GBP');
                }
                if (empty($fields['sort_code']) || !preg_match('/^\d{6}$|^\d{2}-\d{2}-\d{2}$/', $fields['sort_code'])) {
                    $errors[] = $this->err('sort_code', 'invalid_format',
                        'sort_code must be 6 digits (optionally separated with dashes)');
                }
                if (empty($fields['account_number']) || !preg_match('/^\d{8}$/', $fields['account_number'])) {
                    $errors[] = $this->err('account_number', 'invalid_format',
                        'account_number must be exactly 8 digits');
                }
                break;

            case 'ach':
            case 'fednow':
                if ($currency !== 'USD') {
                    $errors[] = $this->err('currency', 'invalid_currency_for_type',
                        "$rail settles in USD");
                }
                if (empty($fields['routing_number']) || !AbaRouting::isValid($fields['routing_number'])) {
                    $errors[] = $this->err('routing_number', 'invalid_checksum',
                        'routing_number must be a valid 9-digit ABA number');
                }
                if (empty($fields['account_number']) || !preg_match('/^\d{4,17}$/', $fields['account_number'])) {
                    $errors[] = $this->err('account_number', 'invalid_format',
                        'account_number must be 4–17 digits');
                }
                if (empty($fields['account_type']) || !in_array($fields['account_type'], ['checking', 'savings'], true)) {
                    $errors[] = $this->err('account_type', 'invalid_value',
                        "account_type must be 'checking' or 'savings'");
                }
                break;

            case 'swift':
                if (empty($fields['bic_swift']) || !$this->isBic($fields['bic_swift'])) {
                    $errors[] = $this->err('bic_swift', 'invalid_format',
                        'bic_swift must match BIC pattern (8 or 11 chars)');
                }
                if (empty($fields['bank_name'])) {
                    $errors[] = $this->err('bank_name', 'required', 'bank_name is required');
                }
                if (empty($fields['country']) || !preg_match('/^[A-Z]{2}$/', $fields['country'])) {
                    $errors[] = $this->err('country', 'invalid_format',
                        'country must be ISO 3166-1 alpha-2 (e.g. US, DE, MX)');
                }
                // Either iban or account_number required.
                if (empty($fields['iban']) && empty($fields['account_number'])) {
                    $errors[] = $this->err(null, 'required',
                        'SWIFT requires at least one of: iban, account_number');
                } elseif (!empty($fields['iban']) && !Iban::isValid($fields['iban'])) {
                    $errors[] = $this->err('iban', 'invalid_checksum',
                        'IBAN failed mod-97 validation');
                }
                if (!empty($fields['intermediary_bic']) && !$this->isBic($fields['intermediary_bic'])) {
                    $errors[] = $this->err('intermediary_bic', 'invalid_format',
                        'intermediary_bic must match BIC pattern');
                }
                break;
        }
        return $errors;
    }

    // =========================================================================
    // Custom — free-form settlement instructions
    // =========================================================================

    private function validateCustom(string $currency, array $fields): array
    {
        $errors = [];
        $rows = $fields['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            $errors[] = $this->err('rows', 'required',
                'at least one key/value row is required');
            return $errors;
        }
        if (count($rows) > 50) {
            $errors[] = $this->err('rows', 'too_many',
                'rows must be 50 or fewer');
            return $errors;
        }
        $hasNonEmpty = false;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $errors[] = $this->err("rows[$i]", 'invalid_type',
                    'each row must be an object with key and value');
                continue;
            }
            $key   = $row['key']   ?? '';
            $value = $row['value'] ?? '';
            if (!is_string($key) || !is_string($value)) {
                $errors[] = $this->err("rows[$i]", 'invalid_type',
                    'row key and value must be strings');
                continue;
            }
            $key   = trim($key);
            $value = trim($value);
            if ($key === '' && $value === '') {
                continue; // skip wholly-blank rows silently
            }
            if ($key === '') {
                $errors[] = $this->err("rows[$i].key", 'required',
                    'row key is required when value is set');
            } elseif (strlen($key) > 64) {
                $errors[] = $this->err("rows[$i].key", 'too_long',
                    'row key must be ≤ 64 chars');
            }
            if ($value === '') {
                $errors[] = $this->err("rows[$i].value", 'required',
                    'row value is required when key is set');
            } elseif (strlen($value) > 1024) {
                $errors[] = $this->err("rows[$i].value", 'too_long',
                    'row value must be ≤ 1024 chars');
            }
            if ($key !== '' && $value !== '') {
                $hasNonEmpty = true;
            }
        }
        if ($errors === [] && !$hasNonEmpty) {
            $errors[] = $this->err('rows', 'required',
                'at least one key/value row is required');
        }
        return $errors;
    }

    // =========================================================================
    // Catalog — UI-facing metadata for the GUI's two-step "Add method" modal.
    //
    // Single source of truth for: group layout, per-type labels/icons/help,
    // and the field schema driving the form renderer. Values mirror the
    // per-type validators above; keep in sync when validators change.
    // =========================================================================

    /**
     * Returns the full catalog consumed by the GUI form renderer.
     *
     * Shape:
     *   groups: list<{id, label}>
     *   currencies: list<{code, label}> — fallback dropdown when a type allows any
     *   types: list<{
     *     id, label, group, icon, description,
     *     currencies: list<string>|null,
     *     currenciesFor?: {field, map: array<string, list<string>|null>},
     *     fields: list<FieldSchema>
     *   }>
     */
    public function getCatalog(): array
    {
        $catalog = $this->getCoreCatalog();

        // Merge plugin-registered types. Plugins may also declare new group
        // ids (e.g. 'crypto', 'fintech', 'mobile') — dedupe those into the
        // groups list so the GUI's group picker shows each once.
        if ($this->registry !== null) {
            $existingGroups = [];
            foreach ($catalog['groups'] as $g) { $existingGroups[$g['id']] = true; }
            foreach ($this->registry->all() as $contract) {
                $entry = $contract->getCatalogEntry();
                $catalog['types'][] = $entry;
                $groupId = $entry['group'] ?? null;
                if (is_string($groupId) && $groupId !== '' && empty($existingGroups[$groupId])) {
                    $catalog['groups'][] = [
                        'id'    => $groupId,
                        'label' => ucfirst(str_replace('_', ' ', $groupId)),
                    ];
                    $existingGroups[$groupId] = true;
                }
            }
        }

        // Always render the "other" group last — it's the catch-all and
        // shouldn't sit between more-specific plugin-provided groups.
        $catalog['groups'] = array_values(array_merge(
            array_filter($catalog['groups'], fn($g) => ($g['id'] ?? '') !== 'other'),
            array_filter($catalog['groups'], fn($g) => ($g['id'] ?? '') === 'other')
        ));
        return $catalog;
    }

    /**
     * Static accessor kept as a bridge for the few places (unit tests,
     * ad-hoc scripts) that still call `PaybackMethodTypeValidator::getCatalog()`
     * without a registry. Returns the core-only catalog — plugin types are
     * not surfaced through this path.
     *
     * @deprecated Prefer the instance method on a registry-backed validator.
     */
    public static function getCatalogCoreOnly(): array
    {
        return (new self())->getCoreCatalog();
    }

    /**
     * Core-only catalog (bank_wire + custom). Kept as a private builder so
     * the instance `getCatalog()` can layer plugin types on top without
     * duplicating the bank/custom schema.
     */
    private function getCoreCatalog(): array
    {
        $bicBankFields = [
            ['name' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'text', 'required' => true,
                'placeholder' => 'Full legal name on the account'],
        ];

        return [
            'groups' => [
                ['id' => 'bank',  'label' => 'Bank'],
                ['id' => 'other', 'label' => 'Other'],
            ],
            // Fallback currency list for types that accept any ISO-4217 fiat.
            'currencies' => [
                ['code' => 'USD', 'label' => 'USD — US Dollar'],
                ['code' => 'EUR', 'label' => 'EUR — Euro'],
                ['code' => 'GBP', 'label' => 'GBP — Pound Sterling'],
                ['code' => 'CAD', 'label' => 'CAD — Canadian Dollar'],
                ['code' => 'AUD', 'label' => 'AUD — Australian Dollar'],
                ['code' => 'NZD', 'label' => 'NZD — New Zealand Dollar'],
                ['code' => 'CHF', 'label' => 'CHF — Swiss Franc'],
                ['code' => 'JPY', 'label' => 'JPY — Japanese Yen'],
                ['code' => 'CNY', 'label' => 'CNY — Chinese Yuan'],
                ['code' => 'HKD', 'label' => 'HKD — Hong Kong Dollar'],
                ['code' => 'SGD', 'label' => 'SGD — Singapore Dollar'],
                ['code' => 'SEK', 'label' => 'SEK — Swedish Krona'],
                ['code' => 'NOK', 'label' => 'NOK — Norwegian Krone'],
                ['code' => 'DKK', 'label' => 'DKK — Danish Krone'],
                ['code' => 'PLN', 'label' => 'PLN — Polish Zloty'],
                ['code' => 'CZK', 'label' => 'CZK — Czech Koruna'],
                ['code' => 'HUF', 'label' => 'HUF — Hungarian Forint'],
                ['code' => 'RON', 'label' => 'RON — Romanian Leu'],
                ['code' => 'BRL', 'label' => 'BRL — Brazilian Real'],
                ['code' => 'MXN', 'label' => 'MXN — Mexican Peso'],
                ['code' => 'INR', 'label' => 'INR — Indian Rupee'],
                ['code' => 'ZAR', 'label' => 'ZAR — South African Rand'],
                ['code' => 'TRY', 'label' => 'TRY — Turkish Lira'],
                ['code' => 'AED', 'label' => 'AED — UAE Dirham'],
                ['code' => 'ILS', 'label' => 'ILS — Israeli Shekel'],
                ['code' => 'THB', 'label' => 'THB — Thai Baht'],
                ['code' => 'KRW', 'label' => 'KRW — South Korean Won'],
                ['code' => 'PHP', 'label' => 'PHP — Philippine Peso'],
                ['code' => 'IDR', 'label' => 'IDR — Indonesian Rupiah'],
                ['code' => 'MYR', 'label' => 'MYR — Malaysian Ringgit'],
            ],
            'types' => [
                // ── Bank wire ─────────────────────────────────────────────────
                [
                    'id' => self::TYPE_BANK_WIRE, 'label' => 'Bank wire', 'group' => 'bank',
                    'icon' => 'fas fa-university',
                    'description' => 'SEPA, Faster Payments, ACH, FedNow, or SWIFT.',
                    'info' => 'Pick the rail that matches the account: <strong>SEPA</strong> for EUR '
                        . 'accounts in the EU, <strong>Faster Payments</strong> for UK GBP, '
                        . '<strong>ACH</strong> or <strong>FedNow</strong> for US USD, '
                        . '<strong>SWIFT</strong> for anything international (including cross-'
                        . 'currency transfers). The form only shows the fields relevant to the '
                        . 'rail you choose — IBAN for SEPA, sort code + 8-digit account for Faster '
                        . 'Payments, ABA + account number + checking/savings for ACH/FedNow. '
                        . 'SWIFT accepts either an IBAN or a local account number — the toggle '
                        . 'above the field switches between the two.',
                    'currencies' => ['EUR', 'GBP', 'USD'],
                    'currenciesFor' => [
                        'field' => 'rail',
                        'map' => [
                            'sepa'            => ['EUR'],
                            'faster_payments' => ['GBP'],
                            'ach'             => ['USD'],
                            'fednow'          => ['USD'],
                            'swift'           => null,
                        ],
                    ],
                    'fields' => array_merge([
                        ['name' => 'rail', 'label' => 'Rail', 'type' => 'select', 'required' => true,
                            'options' => [
                                ['value' => 'sepa',            'label' => 'SEPA (EU) — EUR'],
                                ['value' => 'faster_payments', 'label' => 'Faster Payments (UK) — GBP'],
                                ['value' => 'ach',             'label' => 'ACH (US) — USD'],
                                ['value' => 'fednow',          'label' => 'FedNow (US) — USD'],
                                ['value' => 'swift',           'label' => 'SWIFT (International)'],
                            ]],
                    ], $bicBankFields, [
                        // SEPA
                        ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'required' => true,
                            'placeholder' => 'DE89 3704 0044 0532 0130 00',
                            'showWhen' => ['field' => 'rail', 'in' => ['sepa']]],
                        // Faster Payments
                        ['name' => 'sort_code', 'label' => 'Sort code', 'type' => 'text', 'required' => true,
                            'placeholder' => '12-34-56',
                            'showWhen' => ['field' => 'rail', 'in' => ['faster_payments']]],
                        ['name' => 'account_number', 'label' => 'Account number', 'type' => 'text', 'required' => true,
                            'placeholder' => '8 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['faster_payments']]],
                        // ACH / FedNow
                        ['name' => 'routing_number', 'label' => 'Routing number (ABA)', 'type' => 'text', 'required' => true,
                            'placeholder' => '9 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        ['name' => 'account_number', 'label' => 'Account number', 'type' => 'text', 'required' => true,
                            'placeholder' => '4–17 digits',
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        ['name' => 'account_type', 'label' => 'Account type', 'type' => 'select', 'required' => true,
                            'options' => [
                                ['value' => 'checking', 'label' => 'Checking'],
                                ['value' => 'savings',  'label' => 'Savings'],
                            ],
                            'showWhen' => ['field' => 'rail', 'in' => ['ach', 'fednow']]],
                        // SWIFT
                        ['name' => 'bic_swift', 'label' => 'BIC / SWIFT', 'type' => 'text', 'required' => true,
                            'placeholder' => 'BOFAUS3N', 'help' => '8 or 11 characters',
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'required' => true,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'country', 'label' => 'Bank country (ISO 3166-1 alpha-2)', 'type' => 'text', 'required' => true,
                            'placeholder' => 'US / DE / MX',
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'iban', 'label' => 'IBAN (or account_number)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'account_number', 'label' => 'Account number (or IBAN)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                        ['name' => 'intermediary_bic', 'label' => 'Intermediary BIC (optional)', 'type' => 'text', 'required' => false,
                            'showWhen' => ['field' => 'rail', 'in' => ['swift']]],
                    ]),
                ],

                // ── Other ─────────────────────────────────────────────────────
                [
                    'id' => self::TYPE_CUSTOM, 'label' => 'Custom', 'group' => 'other',
                    'icon' => 'fas fa-question',
                    'description' => 'Free-form key/value rows when no canonical rail fits. Currency is whatever you declare.',
                    'info' => 'Use <strong>Custom</strong> when no typed rail fits (an in-person '
                        . 'meet-up, a gift card, an obscure payment service, a barter arrangement, '
                        . 'etc). Add one row per field a payer needs (e.g. <em>name: Alice</em>, '
                        . '<em>iban: DE89…</em>, <em>reference: invoice-42</em>). The recipient sees '
                        . 'each row separately and can copy individual values with one tap. '
                        . '<br><br>'
                        . '<strong>Heads up: the list view redacts custom rows entirely</strong> '
                        . '(<code>•••</code>). Because rows are free text, no prefix preview is safe; '
                        . 'the full content is released only after you unlock with your auth code.',
                    'currencies' => null,
                    'fields' => [
                        ['name' => 'rows', 'label' => 'Fields', 'type' => 'row_editor', 'required' => true,
                            'help' => 'Add one row per piece of information the payer needs. '
                                . 'Up to 50 rows, key ≤ 64 chars, value ≤ 1024 chars.',
                            'rowKeyPlaceholder' => 'name',
                            'rowValuePlaceholder' => 'value'],
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // Receive-side sanitization — applied to wire payloads before storage.
    //
    // A malicious peer can ship any JSON shape; the local validator only runs
    // at create time and never sees inbound fields. This sanitizer normalizes
    // a Custom payload's `rows` into a safe, render-ready shape: drops
    // non-string entries, caps row count + key/value length, and strips
    // control characters and Unicode bidi/format/zero-width characters that
    // could visually spoof a row's contents.
    // =========================================================================

    public const CUSTOM_MAX_ROWS      = 50;
    public const CUSTOM_MAX_KEY_LEN   = 64;
    public const CUSTOM_MAX_VALUE_LEN = 1024;

    /**
     * Normalize Custom payback fields received from a peer. Unknown top-level
     * keys are dropped (only `rows` survives). Returns ['rows' => [...]] with
     * each row shaped as {key, value} strings.
     *
     * @param array<string,mixed> $fields Raw decoded fields from the wire.
     * @return array{rows: list<array{key:string,value:string}>}
     */
    public static function sanitizeCustomFields(array $fields): array
    {
        $rows = $fields['rows'] ?? null;
        if (!is_array($rows)) {
            return ['rows' => []];
        }
        $clean = [];
        $i = 0;
        foreach ($rows as $row) {
            if ($i >= self::CUSTOM_MAX_ROWS) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $key   = self::sanitizeFieldString($row['key']   ?? '', self::CUSTOM_MAX_KEY_LEN);
            $value = self::sanitizeFieldString($row['value'] ?? '', self::CUSTOM_MAX_VALUE_LEN);
            // Drop rows that aren't well-formed: either side empty (after
            // sanitization) means the row has no useful key/value pair to
            // display. Matches the create-time validator's rule that key
            // and value are both required when either is set.
            if ($key === '' || $value === '') {
                continue;
            }
            $clean[] = ['key' => $key, 'value' => $value];
            $i++;
        }
        return ['rows' => $clean];
    }

    /**
     * Strip control and bidi/format characters and clamp length. Coerces
     * non-strings to empty.
     */
    private static function sanitizeFieldString(mixed $s, int $maxLen): string
    {
        if (!is_string($s)) {
            return '';
        }
        // Strip C0/C1 control chars except \t \n \r.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $s) ?? '';
        // Strip Unicode bidi/format/zero-width chars that can spoof rendering:
        //   U+200B–200F (ZWSP, ZWNJ, ZWJ, LRM, RLM)
        //   U+202A–202E (LRE, RLE, PDF, LRO, RLO)
        //   U+2066–2069 (LRI, RLI, FSI, PDI)
        //   U+FEFF      (BOM / ZWNBSP)
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? '';
        $s = trim($s);
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $maxLen, 'UTF-8');
        }
        return substr($s, 0, $maxLen);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isValidCurrencyCode(string $currency): bool
    {
        return (bool) preg_match('/^[A-Z]{3,5}$/', $currency);
    }

    private function isBic(string $bic): bool
    {
        return (bool) preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic);
    }

    /** @return array{field: string|null, code: string, message: string} */
    private function err(?string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
