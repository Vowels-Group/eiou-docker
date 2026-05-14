<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Database\PaybackMethodReceivedRepository;
use Eiou\Database\PaybackMethodRepository;
use Eiou\Services\Plugins\PluginLoader;
use RuntimeException;

/**
 * PaybackMethodLookupService
 *
 * Read-only facade over PaybackMethodRepository (operator's own
 * configured methods) and PaybackMethodReceivedRepository (cache
 * of methods received from contacts). PluginCallerAware-backed
 * so the calling plugin's manifest is the scope authority — a
 * plugin can only see payback methods of rail types it itself
 * declared in `payback_method_types`. A plugin granted the
 * permissions but declaring no rail types sees nothing; a
 * BTC-rail plugin sees BTC methods but not, say, bank_wire.
 *
 * Trust shape:
 *
 *   - The projection deliberately omits `encrypted_fields` (the
 *     operator's own payback-method ciphertext) and `fields_json`
 *     (the contact's decrypted account identifiers). Plugins get
 *     capability metadata — "this operator has a USD BTC method
 *     enabled," "this contact prefers BTC for USD" — sufficient
 *     for orchestration (decide whether to engage, surface a
 *     hint in the UI). Actually using a payback method to settle
 *     a payment requires a different surface that does not yet
 *     exist; when it lands, it will be a write-side service with
 *     its own permission key (write-side surfaces — message-send,
 *     settlement-record — are deliberately scoped out of the
 *     current pass until a real plugin shapes their signatures).
 *
 *   - Cross-rail isolation lives at the type filter, not at the
 *     repository: the method walks the repository's full results
 *     and discards rows whose type isn't in the calling plugin's
 *     declared rail types. A plugin that quietly adds a new
 *     `payback_method_types` entry post-install doesn't unlock
 *     more methods than the operator approved — the host's
 *     install/upgrade flow gates manifest changes.
 *
 * Both methods refuse with RuntimeException if the calling plugin
 * id isn't set — defence in depth against any path that reaches
 * the method outside the gateway (the gateway always sets the id
 * via setCallingPluginId before dispatching).
 */
class PaybackMethodLookupService implements PluginCallerAware
{
    private PaybackMethodRepository $ownRepo;
    private PaybackMethodReceivedRepository $receivedRepo;
    private PluginLoader $loader;
    private ?string $callingPluginId = null;

    public function __construct(
        PaybackMethodRepository $ownRepo,
        PaybackMethodReceivedRepository $receivedRepo,
        PluginLoader $loader
    ) {
        $this->ownRepo = $ownRepo;
        $this->receivedRepo = $receivedRepo;
        $this->loader = $loader;
    }

    public function setCallingPluginId(?string $pluginId): void
    {
        $this->callingPluginId = $pluginId;
    }

    #[PluginCallable(
        description: 'List the operator\'s configured payback methods, filtered to the rail types this plugin declared in its manifest\'s `payback_method_types`. Returns capability metadata only (type, label, currency, method_id, priority, enabled, share_policy, settlement_min_unit / settlement_min_unit_exponent) — `encrypted_fields` is NOT exposed. Plugins use this to learn "does the operator have a method of my rail type at all, and in what currencies." Requires the payback_method_read_own permission. Strictly scoped: a plugin that declared no `payback_method_types` sees an empty list regardless of how many methods the operator has configured.',
        ratePerMinute: 30,
        permission: 'payback_method_read_own'
    )]
    public function getMyConfiguredMethods(): array
    {
        $pluginId = $this->requireCallerId();
        $allowedTypes = $this->declaredRailTypesForPlugin($pluginId);
        if ($allowedTypes === []) {
            return [];
        }
        $rows = $this->ownRepo->listMethods(null, true);
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, $allowedTypes, true)) {
                continue;
            }
            $out[] = $this->projectOwn($row);
        }
        return $out;
    }

    #[PluginCallable(
        description: 'Read a contact\'s declared payback-method preferences, filtered to the rail types this plugin declared in its manifest. Pass the contact\'s pubkey_hash and optionally a currency filter. Returns capability metadata (type, label, currency, remote_method_id, priority, received_at, expires_at) — `fields_json` (the decrypted account identifiers) is NOT exposed by this surface; a plugin needing the unmasked identifier uses the operator-confirmed flow elsewhere. Requires the payback_method_read_contact permission. Strictly scoped by the plugin\'s `payback_method_types`.',
        ratePerMinute: 30,
        permission: 'payback_method_read_contact'
    )]
    public function getContactPaybackPreference(string $pubkeyHash, ?string $currency = null): array
    {
        $pluginId = $this->requireCallerId();
        $allowedTypes = $this->declaredRailTypesForPlugin($pluginId);
        if ($allowedTypes === []) {
            return [];
        }
        $hash = strtolower(trim($pubkeyHash));
        if ($hash === '') {
            return [];
        }
        $normalisedCurrency = null;
        if ($currency !== null) {
            $trimmed = trim($currency);
            if ($trimmed === '') {
                return [];
            }
            $normalisedCurrency = $trimmed;
        }
        $rows = $this->receivedRepo->listFreshForContact($hash, $normalisedCurrency);
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, $allowedTypes, true)) {
                continue;
            }
            $out[] = $this->projectContact($row);
        }
        return $out;
    }

    /**
     * Resolve the calling plugin's declared rail-type ids from its
     * manifest row. `payback_method_types` entries each carry an
     * `id` field; this method flattens them to a list of strings.
     * An empty list (plugin declares no rail types, or the row
     * doesn't carry the field) means the plugin's effective scope
     * is empty — the lookup methods return [] without hitting the
     * repository.
     *
     * @return list<string>
     */
    private function declaredRailTypesForPlugin(string $pluginId): array
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) !== $pluginId) {
                continue;
            }
            $entries = $row['payback_method_types'] ?? [];
            if (!is_array($entries)) {
                return [];
            }
            $ids = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['id']) && is_string($entry['id'])) {
                    $ids[] = $entry['id'];
                }
            }
            return array_values(array_unique($ids));
        }
        return [];
    }

    /**
     * Project a payback_methods row down to the plugin-facing
     * capability metadata. encrypted_fields / fields_version are
     * deliberately omitted — the raw ciphertext is useless to a
     * plugin and the version field exists for host-side re-keying,
     * not plugin consumption.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectOwn(array $row): array
    {
        return [
            'method_id'                    => (string) ($row['method_id'] ?? ''),
            'type'                         => (string) ($row['type'] ?? ''),
            'label'                        => (string) ($row['label'] ?? ''),
            'currency'                     => (string) ($row['currency'] ?? ''),
            'priority'                     => (int) ($row['priority'] ?? 0),
            'enabled'                      => (bool) ($row['enabled'] ?? false),
            'share_policy'                 => (string) ($row['share_policy'] ?? ''),
            'settlement_min_unit'          => (int) ($row['settlement_min_unit'] ?? 1),
            'settlement_min_unit_exponent' => (int) ($row['settlement_min_unit_exponent'] ?? -8),
        ];
    }

    /**
     * Project a payback_methods_received row to the plugin-facing
     * shape. fields_json (the decrypted account identifiers) is
     * deliberately omitted — see class docblock.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectContact(array $row): array
    {
        return [
            'remote_method_id'             => (string) ($row['remote_method_id'] ?? ''),
            'contact_pubkey_hash'          => (string) ($row['contact_pubkey_hash'] ?? ''),
            'type'                         => (string) ($row['type'] ?? ''),
            'label'                        => (string) ($row['label'] ?? ''),
            'currency'                     => (string) ($row['currency'] ?? ''),
            'priority'                     => (int) ($row['priority'] ?? 0),
            'settlement_min_unit'          => (int) ($row['settlement_min_unit'] ?? 1),
            'settlement_min_unit_exponent' => (int) ($row['settlement_min_unit_exponent'] ?? -8),
            'received_at'                  => $this->toEpoch($row['received_at'] ?? null),
            'expires_at'                   => $this->toEpoch($row['expires_at'] ?? null),
        ];
    }

    private function toEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts === false ? null : $ts;
        }
        return null;
    }

    private function requireCallerId(): string
    {
        if ($this->callingPluginId === null) {
            throw new RuntimeException(
                'PaybackMethodLookupService requires gateway-injected caller id'
            );
        }
        return $this->callingPluginId;
    }
}
