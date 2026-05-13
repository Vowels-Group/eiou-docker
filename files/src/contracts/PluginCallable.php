<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

use Attribute;

/**
 * #[PluginCallable]
 *
 * Marks a method as callable from a sandboxed plugin's __dispatch.php
 * via the Phase 4 service gateway. Without this attribute, no plugin
 * can reach the method — even if its manifest declares it in
 * `core_services`. Default-deny: a fresh codebase exposes zero methods.
 *
 * Plugins additionally declare which #[PluginCallable] methods they
 * want to use in their plugin.json:
 *
 *   "core_services": ["Logger.info", "ContactService.lookupByPubkey"]
 *
 * Both gates must pass before the gateway dispatches a call:
 *
 *   1. The target method exists and carries #[PluginCallable]
 *   2. The plugin's manifest names the "Service.method" in core_services
 *
 * This is the security trade-off for opening up service access at all
 * — every method we expose is reviewable in `git grep PluginCallable`
 * and the plugin has to ask for what it wants up front in a manifest
 * field operators can read before they enable.
 *
 * Constraints on attributed methods:
 *
 *   - Args MUST be scalar (string|int|float|bool) or array of scalars.
 *     The gateway rejects callables, objects, resources. Plugin authors
 *     pass structured data as arrays.
 *
 *   - Returns MUST be scalar, array, or null. Throwing is fine — the
 *     gateway surfaces the message + class. Returning an object becomes
 *     a 500 because it can't cross the wire.
 *
 *   - Side effects are the author's responsibility. A method tagged
 *     PluginCallable becomes operator-visible attack surface; treat
 *     it like a public REST endpoint.
 *
 * See docs/PLUGIN_SANDBOXING.md.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PluginCallable
{
    /**
     * Optional rate limit (calls per minute per plugin). If null, the
     * gateway applies the default rate cap. Override only when a
     * method is genuinely high-frequency (still cap it — a runaway
     * plugin must not be able to DDoS core).
     */
    public ?int $ratePerMinute;

    /**
     * Free-form description that becomes part of the doc surface. Not
     * read at runtime; surfaces in `eiou plugin list-callable` /
     * docs generation.
     */
    public string $description;

    public function __construct(string $description = '', ?int $ratePerMinute = null)
    {
        $this->description = $description;
        $this->ratePerMinute = $ratePerMinute;
    }
}
