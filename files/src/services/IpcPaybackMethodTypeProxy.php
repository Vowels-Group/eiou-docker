<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PaybackMethodTypeContract;

/**
 * IpcPaybackMethodTypeProxy
 *
 * Wallet-pool-side implementation of PaybackMethodTypeContract that
 * bridges to a sandboxed plugin's __dispatch.php. The plugin declares
 * the *static* shape of its rail type in `payback_method_types`
 * manifest entries (id, catalog) and writes a `case 'payback_method':`
 * handler in its dispatcher; this proxy lives inside the wallet pool's
 * `PaybackMethodTypeRegistry` and forwards each dynamic contract method
 * call (validate / mask / defaultPrecision) into the plugin's pool over
 * the same IPC channel events and filters already use.
 *
 * Constructed at wallet boot by `PluginIpcForwarder::registerAll()`,
 * one proxy per `payback_method_types` manifest entry. The IPC dispatch
 * itself is owned by the forwarder so the HTTP client, request timeout,
 * authentication, and log forwarding all match what events / filters get.
 *
 * Errors-in-flight handling:
 *
 *   - validate() — IPC failure returns a single structured error
 *     record `{field: null, code: 'plugin_ipc_failed', message: ...}`.
 *     Treats a dead plugin as a top-level validation failure rather
 *     than silently passing the input through, since "could not check"
 *     is materially different from "no errors found."
 *
 *   - mask() — IPC failure returns the standard 3-dot fallback
 *     (`'•••'`). The list view should never throw to render a row's
 *     redacted display; the absent-info state is acceptable here.
 *
 *   - defaultPrecision() — IPC failure returns null so
 *     SettlementPrecisionService falls back to its generic default.
 *     Same logic: "could not ask the plugin" should degrade to the
 *     core default rather than block precision lookup for everyone.
 *
 * Catalog entry is held in-memory at construction (it's static manifest
 * data — no IPC round-trip needed every render of the type picker).
 *
 * See docs/PLUGINS.md (Registering Payback-Method Rail Types).
 */
class IpcPaybackMethodTypeProxy implements PaybackMethodTypeContract
{
    private string $pluginId;
    private string $typeId;
    private array $catalogEntry;
    /** @var callable(string $pluginId, array $envelope): ?array */
    private $dispatcher;

    /**
     * @param string $pluginId    Plugin id whose dispatcher we'll forward to.
     * @param string $typeId      Rail-type id (`btc`, `paypal`, …).
     * @param array  $catalogEntry The static catalog row from the manifest.
     * @param callable $dispatcher Closure invoking PluginIpcForwarder's
     *                            IPC POST against this plugin. Takes
     *                            (pluginId, envelope) and returns the
     *                            decoded response or null on transport
     *                            / handler failure. Same signature
     *                            other forwarder bridges use.
     */
    public function __construct(
        string $pluginId,
        string $typeId,
        array $catalogEntry,
        callable $dispatcher
    ) {
        $this->pluginId     = $pluginId;
        $this->typeId       = $typeId;
        $this->catalogEntry = $catalogEntry;
        $this->dispatcher   = $dispatcher;
    }

    public function getId(): string
    {
        return $this->typeId;
    }

    public function getCatalogEntry(): array
    {
        return $this->catalogEntry;
    }

    public function validate(string $currency, array $fields): array
    {
        $response = ($this->dispatcher)($this->pluginId, [
            'type'    => 'payback_method',
            'name'    => 'validate',
            'context' => [
                'type_id'  => $this->typeId,
                'currency' => $currency,
                'fields'   => $fields,
            ],
        ]);
        if ($response === null) {
            return [[
                'field'   => null,
                'code'    => 'plugin_ipc_failed',
                'message' => "Plugin '{$this->pluginId}' did not respond to a "
                           . "validate() call for rail type '{$this->typeId}'. "
                           . "Operator should check the plugin's status.",
            ]];
        }
        $result = $response['result'] ?? [];
        // Defensive shape filter: the contract requires a list of
        // {field, code, message} records. A plugin returning anything
        // else gets a generic "plugin response malformed" record
        // rather than letting the caller crash on a structure mismatch.
        if (!is_array($result)) {
            return [[
                'field'   => null,
                'code'    => 'plugin_response_malformed',
                'message' => "Plugin '{$this->pluginId}' returned a non-array "
                           . "from validate() for rail type '{$this->typeId}'.",
            ]];
        }
        return $result;
    }

    public function mask(array $fields): string
    {
        $response = ($this->dispatcher)($this->pluginId, [
            'type'    => 'payback_method',
            'name'    => 'mask',
            'context' => [
                'type_id' => $this->typeId,
                'fields'  => $fields,
            ],
        ]);
        if ($response === null) {
            // List-view fallback. Better to render the row with a
            // blank-ish mask than to surface a fatal error to the
            // operator over a transient plugin-pool blip.
            return '•••';
        }
        $result = $response['result'] ?? null;
        return is_string($result) ? $result : '•••';
    }

    public function defaultPrecision(string $currency): ?array
    {
        $response = ($this->dispatcher)($this->pluginId, [
            'type'    => 'payback_method',
            'name'    => 'defaultPrecision',
            'context' => [
                'type_id'  => $this->typeId,
                'currency' => $currency,
            ],
        ]);
        if ($response === null) {
            return null;
        }
        $result = $response['result'] ?? null;
        if (!is_array($result) || count($result) !== 2) {
            return null;
        }
        if (!is_int($result[0]) || !is_int($result[1])) {
            return null;
        }
        return [$result[0], $result[1]];
    }
}
