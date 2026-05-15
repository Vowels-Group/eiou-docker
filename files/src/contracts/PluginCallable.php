<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

use Attribute;

/**
 * #[PluginCallable]
 *
 * Marks a method as callable from a sandboxed plugin's __dispatch.php
 * via the service gateway. Without this attribute, no plugin can reach
 * the method — even if its manifest declares it in `core_services`.
 * Default-deny: a fresh codebase exposes zero methods.
 *
 * Plugins additionally declare which #[PluginCallable] methods they
 * want to use in their plugin.json:
 *
 *   "core_services": ["Logger.info", "ContactService.lookupByPubkey"]
 *
 * Up to three gates must pass before the gateway dispatches a call:
 *
 *   1. The target method exists and carries #[PluginCallable]
 *   2. The plugin's manifest names the "Service.method" in core_services
 *   3. If the attribute carries a `permission:` key, the plugin's
 *      manifest also names that key in `permissions` — see below
 *
 * This is the security trade-off for opening up service access at all
 * — every method we expose is reviewable in `git grep PluginCallable`
 * and the plugin has to ask for what it wants up front in a manifest
 * field operators can read before they enable.
 *
 * Permission keys — louder consent than core_services:
 *
 *   `core_services` is a per-method allow-list operators read before
 *   enabling a plugin. That works for narrow methods (Logger.info,
 *   per-hash lookups, send-payment) where one entry means roughly
 *   what its name suggests. It works less well for methods that
 *   expand the trust model — `ContactLookupService.listAccepted`
 *   lets a plugin enumerate the operator's entire address book, a
 *   different shape of disclosure than a per-hash lookup, but the
 *   only signal in the manifest line is the method name.
 *
 *   Methods that expand the trust model can carry a `permission:`
 *   key. When set, the gateway requires the plugin's manifest to
 *   *also* declare that key in a top-level `permissions: [...]`
 *   list — separate from `core_services` so it surfaces distinctly
 *   in the install/enable confirmation GUI. The permission catalog
 *   (PluginPermissionCatalog) carries the human-readable label and
 *   description shown to operators. Methods without a permission
 *   key behave as before — `core_services` alone is enough.
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
 * See docs/PLUGINS.md (Sandboxing).
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

    /**
     * Optional permission key. When non-null, the gateway requires the
     * calling plugin's manifest to declare this key in a top-level
     * `permissions: [...]` list, in addition to the usual
     * `core_services` entry. Use for methods whose trust shape goes
     * beyond what a single `core_services` line conveys to a casual
     * reader — bulk enumeration over operator data, broad-scope
     * mutation, anything an operator would meaningfully reconsider
     * granting if shown a second time. Catalogued in
     * PluginPermissionCatalog so the GUI can surface a label /
     * description alongside the raw key.
     */
    public ?string $permission;

    public function __construct(string $description = '', ?int $ratePerMinute = null, ?string $permission = null)
    {
        $this->description = $description;
        $this->ratePerMinute = $ratePerMinute;
        $this->permission = $permission;
    }
}
