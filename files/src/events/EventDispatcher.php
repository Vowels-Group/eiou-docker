<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../contracts/EventDispatcherInterface.php';

/**
 * Event Dispatcher
 *
 * Central event dispatcher that enables loose coupling through event-driven communication.
 * Used to break circular dependencies by allowing services to communicate via events
 * instead of direct dependencies.
 *
 * Example usage:
 *   // Subscribe to an event
 *   EventDispatcher::getInstance()->subscribe(SyncEvents::SYNC_COMPLETED, function($data) {
 *       // Handle sync completion
 *   });
 *
 *   // Dispatch an event
 *   EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_COMPLETED, [
 *       'contact_pubkey' => $pubkey,
 *       'synced_count' => 5
 *   ]);
 */
class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @var EventDispatcher|null Singleton instance
     */
    private static ?EventDispatcher $instance = null;

    /**
     * @var array<string, array<callable>> Registered event listeners
     */
    private array $listeners = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        // Private constructor to enforce singleton
    }

    /**
     * Get singleton instance
     *
     * @return EventDispatcher
     */
    public static function getInstance(): EventDispatcher
    {
        if (self::$instance === null) {
            self::$instance = new EventDispatcher();
        }
        return self::$instance;
    }

    /**
     * Subscribe a listener to an event
     *
     * Registers a callable to be invoked when the specified event is dispatched.
     * Multiple listeners can be registered for the same event and will be called
     * in the order they were registered.
     *
     * @param string $event The event name to subscribe to
     * @param callable $listener The callback function to invoke when event fires
     * @return void
     */
    public function subscribe(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners
     *
     * Invokes all listeners registered for the specified event, passing
     * the event data to each listener. Listeners are called synchronously
     * in the order they were registered.
     *
     * If a listener throws an exception, it is caught and logged, but
     * subsequent listeners will still be called.
     *
     * @param string $event The event name to dispatch
     * @param array $data Optional data to pass to listeners
     * @return void
     */
    public function dispatch(string $event, array $data = []): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener($data);
            } catch (\Exception $e) {
                // Log exception but continue dispatching to other listeners
                // This prevents one failing listener from blocking others
                if (class_exists('SecureLogger')) {
                    \SecureLogger::warning("Event listener exception during dispatch", [
                        'event' => $event,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }
    }

    /**
     * Unsubscribe a listener from an event
     *
     * Removes a previously registered listener from an event.
     * Uses strict comparison to find the listener.
     *
     * @param string $event The event name
     * @param callable $listener The listener to remove
     * @return bool True if listener was found and removed, false otherwise
     */
    public function unsubscribe(string $event, callable $listener): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        $key = array_search($listener, $this->listeners[$event], true);
        if ($key !== false) {
            unset($this->listeners[$event][$key]);
            // Re-index array to prevent gaps
            $this->listeners[$event] = array_values($this->listeners[$event]);
            return true;
        }

        return false;
    }

    /**
     * Check if an event has any listeners
     *
     * @param string $event The event name to check
     * @return bool True if the event has at least one listener
     */
    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && count($this->listeners[$event]) > 0;
    }

    /**
     * Get all listeners for an event
     *
     * @param string $event The event name
     * @return array Array of callable listeners
     */
    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * Clear all listeners for an event
     *
     * Removes all registered listeners for the specified event.
     *
     * @param string $event The event name
     * @return void
     */
    public function clearListeners(string $event): void
    {
        if (isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
    }

    /**
     * Clear all listeners for all events
     *
     * Useful for testing to reset state between tests.
     *
     * @return void
     */
    public function clearAllListeners(): void
    {
        $this->listeners = [];
    }

    /**
     * Get count of listeners for an event
     *
     * @param string $event The event name
     * @return int Number of listeners registered for the event
     */
    public function getListenerCount(string $event): int
    {
        return isset($this->listeners[$event]) ? count($this->listeners[$event]) : 0;
    }

    /**
     * Reset the singleton instance
     *
     * For testing purposes - allows resetting the singleton to get a fresh instance.
     * This also clears all registered listeners.
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Prevent cloning of singleton
     *
     * @return void
     */
    private function __clone(): void
    {
        // Prevent cloning
    }

    /**
     * Prevent unserialization of singleton
     *
     * @throws \Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
