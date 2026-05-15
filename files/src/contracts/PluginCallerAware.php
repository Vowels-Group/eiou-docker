<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

/**
 * PluginCallerAware
 *
 * Implemented by gateway-callable services that need to know which plugin
 * is currently invoking them. The gateway sets the calling plugin's id
 * via setCallingPluginId() immediately before invoking the
 * #[PluginCallable] method, and clears it (passes null) immediately after
 * — whether the call returned normally or threw — so the field is never
 * left populated for a subsequent call by an unrelated plugin in the same
 * FPM worker.
 *
 * The id is the trusted identifier resolved by the gateway from the
 * bearer cookie/token in the request headers. Implementations must NOT
 * trust an id passed in the method arguments and must NOT accept the
 * field being left null at invocation time for methods that depend on
 * caller identity — throw \RuntimeException or similar in that case.
 *
 * Use cases:
 *
 *   - PluginEventPublisher: namespaces published events as
 *     plugin.<callingPluginId>.<event> so subscribers can distinguish
 *     which plugin emitted the event.
 *   - Any future spending / state-mutation surface that scopes actions to
 *     the calling plugin's own resources.
 *
 * Services that don't need caller identity simply don't implement the
 * interface; the gateway skips the setter call entirely.
 */
interface PluginCallerAware
{
    /**
     * Set the calling plugin's id for the next gateway-dispatched call.
     * Called by the gateway with the resolved plugin id before the
     * method invocation, and again with null after, regardless of
     * outcome.
     */
    public function setCallingPluginId(?string $pluginId): void;
}
