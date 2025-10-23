<?php
# Copyright 2025

/**
 * Utility Service Container
 *
 * Centralized container for utility services.
 * Provides lazy loading and dependency injection for utilities.
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../ServiceContainer.php';
require_once __DIR__ . '/TimeUtilityService.php';
require_once __DIR__ . '/CurrencyUtilityService.php';
require_once __DIR__ . '/ValidationUtilityService.php';

class UtilityServiceContainer
{
    /**
     * @var UtilityServiceContainer|null Singleton instance
     */
    private static ?UtilityServiceContainer $instance = null;

    /**
     * @var array Cached utility service instances
     */
    private array $utilities = [];

    /**
     * @var ServiceContainer Main service container
     */
    private ServiceContainer $mainContainer;

    /**
     * Private constructor for singleton
     */
    public function __construct(ServiceContainer $mainContainer)
    {
        $this->mainContainer = $mainContainer;
    }

    /**
     * Get singleton instance
     *
     * @param ServiceContainer $container Main service container
     * @return UtilityServiceContainer
     */
    public static function getInstance(ServiceContainer $container): UtilityServiceContainer
    {
        if (self::$instance === null) {
            self::$instance = new self($container);
        }
        return self::$instance;
    }

    /**
     * Get TimeUtilityService
     *
     * @return TimeUtilityService
     */
    public function getTimeUtility(): TimeUtilityService
    {
        if (!isset($this->utilities['TimeUtilityService'])) {
            $this->utilities['TimeUtilityService'] = new TimeUtilityService(
                Constants::getInstance()
            );
        }
        return $this->utilities['TimeUtilityService'];
    }

    /**
     * Get CurrencyUtilityService
     *
     * @return CurrencyUtilityService
     */
    public function getCurrencyUtility(): CurrencyUtilityService
    {
        if (!isset($this->utilities['CurrencyUtilityService'])) {
            $this->utilities['CurrencyUtilityService'] = new CurrencyUtilityService(
                Constants::getInstance()
            );
        }
        return $this->utilities['CurrencyUtilityService'];
    }

    /**
     * Get ValidationUtilityService
     *
     * @return ValidationUtilityService
     */
    public function getValidationUtility(): ValidationUtilityService
    {
        if (!isset($this->utilities['ValidationUtilityService'])) {
            $this->utilities['ValidationUtilityService'] = new ValidationUtilityService(
                Constants::getInstance(),
                $this->mainContainer
            );
        }
        return $this->utilities['ValidationUtilityService'];
    }

    /**
     * Get TransportUtilityService
     *
     * @return TransportUtilityService
     */
    public function getTransportUtility(): TransportUtilityService
    {
        if (!isset($this->utilities['TransportUtilityService'])) {
            $this->utilities['TransportUtilityService'] = new TransportUtilityService(
                Constants::getInstance(),
                $this->mainContainer
            );
        }
        return $this->utilities['TransportUtilityService'];
    }

    /**
     * Clear all cached utilities (useful for testing)
     */
    public function clearUtilities(): void
    {
        $this->utilities = [];
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
