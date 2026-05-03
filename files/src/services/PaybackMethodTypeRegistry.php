<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PaybackMethodTypeContract;
use InvalidArgumentException;

/**
 * Process-local registry of plugin-provided payback-method rail types.
 *
 * Core ships `bank_wire` and `custom` in `PaybackMethodTypeValidator` itself.
 * Every other rail (BTC, Lightning, PayPal, Bizum, etc.) arrives via a
 * plugin calling `register()` during its `register()` phase.
 *
 * The registry is a plain in-memory map — there is no persistence. Each
 * process (PHP-FPM worker, CLI, background processors) builds the registry
 * fresh during plugin boot, so enabling a plugin requires a node restart
 * (see docs/PLUGINS.md).
 *
 * Consumers:
 *   - `PaybackMethodTypeValidator::getCatalog()` merges registered entries
 *     alongside the core entries for the GUI type picker.
 *   - `PaybackMethodTypeValidator::validate()` delegates to the registered
 *     contract when it doesn't recognize the type id.
 *   - `PaybackMethodService::maskForType()` delegates redacted-display.
 *   - `SettlementPrecisionService::defaultFor()` checks the contract
 *     before falling back to the generic fiat/crypto default.
 */
class PaybackMethodTypeRegistry
{
    /** Ids reserved for core types — plugins cannot shadow them. */
    private const CORE_TYPE_IDS = ['bank_wire', 'custom'];

    /** @var array<string, PaybackMethodTypeContract> */
    private array $types = [];

    /**
     * Register a plugin-provided rail type. Throws on:
     *   - malformed id (must match `^[a-z][a-z0-9_]{0,31}$`)
     *   - collision with a core id
     *   - duplicate registration of the same id
     *
     * Throwing surfaces as a plugin `failed` status (see PluginLoader) — the
     * node stays up; just this plugin is disabled for the rest of boot.
     */
    public function register(PaybackMethodTypeContract $type): void
    {
        $id = $type->getId();
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id)) {
            throw new InvalidArgumentException(
                "payback-method type id must match /^[a-z][a-z0-9_]{0,31}$/, got '$id'"
            );
        }
        if (in_array($id, self::CORE_TYPE_IDS, true)) {
            throw new InvalidArgumentException(
                "payback-method type id '$id' is reserved for core"
            );
        }
        if (isset($this->types[$id])) {
            throw new InvalidArgumentException(
                "payback-method type '$id' is already registered by another plugin"
            );
        }
        $this->types[$id] = $type;
    }

    /**
     * Look up a registered type by id. Returns null for unknown ids —
     * callers should fall through to core dispatch rather than throwing,
     * so plugins that register a type don't become a hard dependency on
     * every read path.
     */
    public function get(string $id): ?PaybackMethodTypeContract
    {
        return $this->types[$id] ?? null;
    }

    /**
     * True when this id has a registered contract. Equivalent to
     * `$this->get($id) !== null` but reads cleaner at call sites.
     */
    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    /**
     * All registered types, keyed by id. Used by the catalog builder to
     * project each contract's `getCatalogEntry()` into the GUI payload.
     *
     * @return array<string, PaybackMethodTypeContract>
     */
    public function all(): array
    {
        return $this->types;
    }
}
