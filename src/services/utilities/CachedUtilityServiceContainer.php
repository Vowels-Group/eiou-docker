<?php
/**
 * Cached Utility Service Container
 *
 * Extends UtilityServiceContainer to provide cached versions of utility services.
 *
 * @package Services\Utilities
 * @copyright 2025
 */

require_once __DIR__ . '/UtilityServiceContainer.php';
require_once __DIR__ . '/CachedTransportUtilityService.php';

class CachedUtilityServiceContainer extends UtilityServiceContainer {
    /**
     * Get TransportUtilityService instance (cached version)
     *
     * @return TransportUtilityService
     */
    public function getTransportUtility(): TransportUtilityService {
        if (!isset($this->services['TransportUtilityService'])) {
            require_once __DIR__ . '/CachedTransportUtilityService.php';
            $this->services['TransportUtilityService'] = new CachedTransportUtilityService(
                $this->container
            );
        }
        return $this->services['TransportUtilityService'];
    }
}