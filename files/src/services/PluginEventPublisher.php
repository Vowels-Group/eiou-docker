<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Events\EventDispatcher;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * PluginEventPublisher
 *
 * Lets a sandboxed plugin emit an event that other plugins or in-process
 * subscribers can react to. The host namespaces the event with the calling
 * plugin's id (resolved by the gateway from the bearer token, not from a
 * value the plugin passes) so subscribers can distinguish plugin-origin
 * events from core-origin events and from each other.
 *
 * Dispatched event name:
 *
 *     plugin.<callingPluginId>.<eventName>
 *
 * Subscribers declare the full namespaced name in their manifest's
 * `subscribes_to` list (regex already admits the dotted form). The
 * EventDispatcher fans it out to:
 *
 *   - In-process subscribers (controllers, processors, other plugins
 *     that haven't been moved out of the wallet pool).
 *   - Sandboxed-plugin subscribers via PluginIpcForwarder, which already
 *     POSTs an "event"-typed envelope to each subscribed plugin's
 *     __dispatch.php.
 *
 * The publishing plugin's id is appended to the payload as `_source_plugin`
 * so a subscriber that reads several plugin-origin events into one bucket
 * can still tell them apart without parsing the event name.
 *
 * Why the round-trip:
 *
 *   Sandboxed plugins run in their own FPM pool — they can't share an
 *   EventDispatcher instance with the wallet pool, so emit and subscribe
 *   are necessarily separate IPC paths. Emit goes plugin → wallet via
 *   the gateway (this service); subscribe goes wallet → plugin via the
 *   IPC forwarder.
 *
 * Trust model:
 *
 *   - Caller identity is gateway-resolved; plugins can't spoof a
 *     different plugin's id by passing it in the call.
 *   - Event names are constrained to `^[a-z][a-z0-9_-]{0,63}$` so a
 *     malicious caller can't inject control characters or collide with
 *     core event prefixes (the `plugin.` prefix on the dispatched name
 *     is host-emitted).
 *   - Payload is validated to be a JSON-serialisable array with a size
 *     cap, so a misbehaving plugin can't spam a 100 MiB payload that
 *     blocks subscribers or fills the log.
 *   - Per-plugin rate cap (via the gateway's #[PluginCallable]
 *     ratePerMinute) prevents a tight loop from drowning subscribers.
 */
class PluginEventPublisher implements PluginCallerAware
{
    /** Max bytes in the JSON-encoded payload. 16 KiB is generous for
     *  structured event metadata (txids, addresses, opaque keys) and
     *  rejects accidental dumps. */
    public const MAX_PAYLOAD_BYTES = 16 * 1024;

    /** Pattern for the unnamespaced event name. The host prepends
     *  `plugin.<callerPluginId>.` to form the dispatched name. */
    public const EVENT_NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    private EventDispatcher $dispatcher;
    private Logger $logger;
    private ?string $callingPluginId = null;

    public function __construct(EventDispatcher $dispatcher, Logger $logger)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function setCallingPluginId(?string $pluginId): void
    {
        $this->callingPluginId = $pluginId;
    }

    /**
     * Publish a plugin-origin event.
     *
     * @param string $eventName Local event name (no namespace prefix).
     *                          Host prepends `plugin.<callerPluginId>.`.
     * @param array  $payload   Event payload. Must JSON-encode to ≤
     *                          MAX_PAYLOAD_BYTES bytes after the host
     *                          adds `_source_plugin`.
     *
     * @throws InvalidArgumentException on bad event name or oversize payload.
     * @throws RuntimeException         when called outside the gateway
     *                                  (caller id missing).
     */
    #[PluginCallable(
        description: 'Publish a plugin-origin event. The host namespaces it as plugin.<your-plugin-id>.<eventName> and fans it out to all subscribers (in-process and sandboxed). Subscribers declare the full namespaced name in their manifest subscribes_to list. Payload must be JSON-serialisable and stay under 16 KiB.',
        ratePerMinute: 600
    )]
    public function publish(string $eventName, array $payload): bool
    {
        if ($this->callingPluginId === null) {
            // Defence in depth: only the gateway invokes this method,
            // and the gateway always sets the caller id first. If we
            // ever reach this branch, something has bypassed the
            // gateway — refuse rather than fan out an un-attributed
            // event.
            throw new RuntimeException('PluginEventPublisher::publish requires gateway-injected caller id');
        }

        if (preg_match(self::EVENT_NAME_PATTERN, $eventName) !== 1) {
            throw new InvalidArgumentException(
                "event name '{$eventName}' must match " . self::EVENT_NAME_PATTERN
            );
        }

        $payload['_source_plugin'] = $this->callingPluginId;
        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new InvalidArgumentException('payload is not JSON-serialisable');
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                'payload exceeds ' . self::MAX_PAYLOAD_BYTES . ' bytes (got ' . strlen($encoded) . ')'
            );
        }

        $dispatchedName = 'plugin.' . $this->callingPluginId . '.' . $eventName;
        $this->logger->debug('plugin_event_publish', [
            'plugin' => $this->callingPluginId,
            'event' => $dispatchedName,
            'bytes' => strlen($encoded),
        ]);
        $this->dispatcher->dispatch($dispatchedName, $payload);
        return true;
    }
}
