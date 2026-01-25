<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Transport Utility Service
 *
 * Handles Transport
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../../core/Constants.php';

use Eiou\Contracts\GeneralUtilityServiceInterface;

class GeneralUtilityService implements GeneralUtilityServiceInterface
{
    /**
     * @var ServiceContainer Service container for accessing repositories
     */
    private ServiceContainer $container;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Constructor
     *
     * @param ServiceContainer $container Service container
     */
    public function __construct(
        ServiceContainer $container
        )
    {
        $this->container = $container;
        $this->currentUser = $this->container->getCurrentUser();
    }

    /**
     * Truncate address for easier display
     *
     * @param string $address The address
     * @param int $length Point of truncation
     * @return string Truncated address
     */
    public function truncateAddress(string $address, int $length = 10): string
    {
        if (strlen($address) <= $length) {
            return $address;
        }
        return substr($address, 0, $length) . '...';
    }
}