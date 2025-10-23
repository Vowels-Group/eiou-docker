<?php
# Copyright 2025

/**
 * Service Wrappers
 *
 * Backward compatibility wrapper functions that bridge old procedural code
 * to new service-based architecture. These functions maintain the same
 * signatures as the original functions but delegate to service classes.
 *
 * This allows gradual migration without breaking existing code.
 *
 * @package Services
 */

require_once __DIR__ . '/ServiceContainer.php';


// ============================================================================
// TRANSACTION SERVICE WRAPPERS
// ============================================================================

/**
 * Send P2P eIOU (wrapper)
 *
 * @param array $request Request data
 * @return void
 */
function sendP2pEiou($request) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    $service->sendP2pEiou($request);
}

// ============================================================================
// P2P SERVICE WRAPPERS
// ============================================================================


/**
 * Send P2P request (wrapper)
 *
 * @param array $data Request data
 * @return void
 */
function sendP2pRequest($data) {
    $service = ServiceContainer::getInstance()->getP2pService();
    $service->sendP2pRequest($data);
}

/**
 * Send P2P request from failed transaction (wrapper)
 *
 * @param array $message Transaction message
 * @return void
 */
function sendP2pRequestFromFailedDirectTransaction($message) {
    $service = ServiceContainer::getInstance()->getP2pService();
    $service->sendP2pRequestFromFailedDirectTransaction($message);
}


// ============================================================================
// DEBUG SERVICE WRAPPERS
// ============================================================================

/**
 * Output any message to log (wrapper)
 *
 * @param string $message Message to output to user and/or log
 * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log) 
 * @return void
 */
function output($message,$echo = 'ECHO') {
    $service = ServiceContainer::getInstance()->getDebugService();
    $service->output($message,$echo);
}


// ============================================================================
// SYNCH SERVICE WRAPPERS
// ============================================================================

/**
 * Synch a Contact (wrapper)
 *
 * @param string $address Contact address
 * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log) 
 * @return bool True if synchable, False otherwise
 */
function synchContact($address, $echo = 'SILENT') {
    $service = ServiceContainer::getInstance()->getSynchService();
    return $service->synchSingleContact($address, $echo);
}
