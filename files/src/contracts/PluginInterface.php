<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Contracts;

use Eiou\Services\ServiceContainer;

/**
 * Plugin Interface
 *
 * Contract for third-party plugins. A plugin lives in its own directory under
 * the configured plugin root (default: /etc/eiou/plugins/<name>/) with a
 * plugin.json manifest and an entry class implementing this interface.
 *
 * Lifecycle (called by PluginLoader during Application boot):
 *
 *   register()  — runs BEFORE ServiceContainer::wireAllServices().
 *                 Use it to add new services, custom repositories, or
 *                 register custom database tables. Other plugins' services
 *                 may not yet be available.
 *
 *   boot()      — runs AFTER ServiceContainer::wireAllServices().
 *                 All core services are wired and ready. Use it to
 *                 subscribe to events, decorate existing services, or
 *                 register CLI/API extensions.
 *
 * Example:
 *
 *   class MyPlugin implements PluginInterface
 *   {
 *       public function getName(): string    { return 'my-plugin'; }
 *       public function getVersion(): string { return '1.0.0'; }
 *
 *       public function register(ServiceContainer $container): void {
 *           $container->registerService('MyService', new MyService());
 *       }
 *
 *       public function boot(ServiceContainer $container): void {
 *           EventDispatcher::getInstance()->subscribe(
 *               SyncEvents::SYNC_COMPLETED,
 *               fn($data) => doSomething($data)
 *           );
 *       }
 *   }
 */
interface PluginInterface
{
    /**
     * Plugin's machine-readable name (kebab-case, must match plugin.json).
     */
    public function getName(): string;

    /**
     * Plugin's semver version string (must match plugin.json).
     */
    public function getVersion(): string;

    /**
     * Phase 1 — register services and resources.
     *
     * Runs BEFORE wireAllServices(). The container has core services
     * registered but circular dependencies are not yet wired.
     *
     * @param ServiceContainer $container Application service container
     */
    public function register(ServiceContainer $container): void;

    /**
     * Phase 2 — subscribe to events and decorate services.
     *
     * Runs AFTER wireAllServices(). All core services are fully available.
     *
     * @param ServiceContainer $container Application service container
     */
    public function boot(ServiceContainer $container): void;
}
