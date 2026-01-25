<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Utility Service Container
 *
 * Centralized container for utility services.
 * Provides lazy loading and dependency injection for utilities.
 *
 * This class is managed by ServiceContainer and should be obtained via:
 * ServiceContainer::getUtilityContainer()
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
     * @var array Cached utility service instances
     */
    private array $utilities = [];

    /**
     * @var ServiceContainer Main service container
     */
    private ServiceContainer $mainContainer;

    /**
     * Constructor
     *
     * @param ServiceContainer $mainContainer Main service container for dependency injection
     */
    public function __construct(ServiceContainer $mainContainer)
    {
        $this->mainContainer = $mainContainer;
    }

    /**
     * Get TimeUtilityService
     *
     * @return TimeUtilityService
     */
    public function getTimeUtility(): TimeUtilityService
    {
        if (!isset($this->utilities['TimeUtilityService'])) {
             require_once __DIR__ . '/TimeUtilityService.php';
            $this->utilities['TimeUtilityService'] = new TimeUtilityService();
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
             require_once __DIR__ . '/CurrencyUtilityService.php';
            $this->utilities['CurrencyUtilityService'] = new CurrencyUtilityService();
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
             require_once __DIR__ . '/ValidationUtilityService.php';
            $this->utilities['ValidationUtilityService'] = new ValidationUtilityService(
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
             require_once __DIR__ . '/TransportUtilityService.php';
            $this->utilities['TransportUtilityService'] = new TransportUtilityService(
                $this->mainContainer
            );
        }
        return $this->utilities['TransportUtilityService'];
    }

    /**
     * Get GeneralUtilityService
     *
     * @return GeneralUtilityService
     */
    public function getGeneralUtility(): GeneralUtilityService
    {
        if (!isset($this->utilities['GeneralUtilityService'])) {
             require_once __DIR__ . '/GeneralUtilityService.php';
            $this->utilities['GeneralUtilityService'] = new GeneralUtilityService(
                $this->mainContainer
            );
        }
        return $this->utilities['GeneralUtilityService'];
    }

    /**
     * Clear all cached utilities (useful for testing)
     */
    public function clearUtilities(): void
    {
        $this->utilities = [];
    }
}
