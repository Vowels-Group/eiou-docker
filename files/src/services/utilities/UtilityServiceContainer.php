<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\CurrencyUtilityServiceInterface;
use Eiou\Contracts\GeneralUtilityServiceInterface;
use Eiou\Contracts\TimeUtilityServiceInterface;
use Eiou\Contracts\TransportServiceInterface;
use Eiou\Contracts\ValidationUtilityServiceInterface;
use Eiou\Services\ServiceContainer;

/**
 * Utility Service Container
 *
 * Centralized container for utility services.
 * Provides lazy loading and dependency injection for utilities.
 *
 * This class is managed by ServiceContainer and should be obtained via:
 * ServiceContainer::getUtilityContainer()
 */
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
     * @return TimeUtilityServiceInterface
     */
    public function getTimeUtility(): TimeUtilityServiceInterface
    {
        if (!isset($this->utilities['TimeUtilityService'])) {
            $this->utilities['TimeUtilityService'] = new TimeUtilityService();
        }
        return $this->utilities['TimeUtilityService'];
    }

    /**
     * Get CurrencyUtilityService
     *
     * @return CurrencyUtilityServiceInterface
     */
    public function getCurrencyUtility(): CurrencyUtilityServiceInterface
    {
        if (!isset($this->utilities['CurrencyUtilityService'])) {
            $this->utilities['CurrencyUtilityService'] = new CurrencyUtilityService();
        }
        return $this->utilities['CurrencyUtilityService'];
    }

    /**
     * Get ValidationUtilityService
     *
     * @return ValidationUtilityServiceInterface
     */
    public function getValidationUtility(): ValidationUtilityServiceInterface
    {
        if (!isset($this->utilities['ValidationUtilityService'])) {
            $this->utilities['ValidationUtilityService'] = new ValidationUtilityService(
                $this->mainContainer
            );
        }
        return $this->utilities['ValidationUtilityService'];
    }

    /**
     * Get TransportUtilityService
     *
     * @return TransportServiceInterface
     */
    public function getTransportUtility(): TransportServiceInterface
    {
        if (!isset($this->utilities['TransportUtilityService'])) {
            $this->utilities['TransportUtilityService'] = new TransportUtilityService(
                $this->mainContainer
            );
        }
        return $this->utilities['TransportUtilityService'];
    }

    /**
     * Get GeneralUtilityService
     *
     * @return GeneralUtilityServiceInterface
     */
    public function getGeneralUtility(): GeneralUtilityServiceInterface
    {
        if (!isset($this->utilities['GeneralUtilityService'])) {
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
