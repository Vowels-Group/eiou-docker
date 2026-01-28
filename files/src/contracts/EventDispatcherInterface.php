<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Event Dispatcher Interface
 *
 * Defines the contract for event-driven communication between services.
 * Enables loose coupling by allowing services to communicate via events
 * instead of direct dependencies, helping to break circular dependencies.
 *
 * Common events (see SyncEvents class for sync-related events):
 * - 'transaction.created': When a new transaction is created
 * - 'transaction.received': When a transaction is received
 * - 'contact.added': When a new contact is added
 * - 'contact.accepted': When a contact is accepted
 * - 'sync.completed': When a sync operation completes
 * - 'chain.repaired': When chain integrity is restored
 */
interface EventDispatcherInterface
{
    /**
     * Subscribe a listener to an event
     *
     * Registers a callable to be invoked when the specified event is dispatched.
     * Multiple listeners can be registered for the same event.
     *
     * @param string $event The event name to subscribe to
     * @param callable $listener The callback function to invoke when event fires
     * @return void
     */
    public function subscribe(string $event, callable $listener): void;

    /**
     * Dispatch an event to all registered listeners
     *
     * Invokes all listeners registered for the specified event, passing
     * the event data to each listener.
     *
     * @param string $event The event name to dispatch
     * @param array $data Optional data to pass to listeners
     * @return void
     */
    public function dispatch(string $event, array $data = []): void;

    /**
     * Unsubscribe a listener from an event
     *
     * Removes a previously registered listener from an event.
     * If the listener is not found, this method does nothing.
     *
     * @param string $event The event name
     * @param callable $listener The listener to remove
     * @return bool True if listener was found and removed, false otherwise
     */
    public function unsubscribe(string $event, callable $listener): bool;

    /**
     * Check if an event has any listeners
     *
     * @param string $event The event name to check
     * @return bool True if the event has at least one listener
     */
    public function hasListeners(string $event): bool;

    /**
     * Get all listeners for an event
     *
     * @param string $event The event name
     * @return array Array of callable listeners
     */
    public function getListeners(string $event): array;

    /**
     * Clear all listeners for an event
     *
     * Removes all registered listeners for the specified event.
     *
     * @param string $event The event name
     * @return void
     */
    public function clearListeners(string $event): void;
}
