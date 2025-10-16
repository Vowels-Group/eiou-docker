<?php

/**
 * Backward compatibility layer for payload functions
 *
 * This file provides wrapper functions that maintain backward compatibility
 * with the old procedural payload functions while using the new class-based
 * payload builders internally.
 *
 * @deprecated These functions are deprecated and will be removed in a future version.
 *             Use the class-based payload builders directly instead.
 */

use EIOU\Context\UserContext;
use EIOU\Schemas\Payloads\ContactPayload;
use EIOU\Schemas\Payloads\TransactionPayload;
use EIOU\Schemas\Payloads\P2pPayload;
use EIOU\Schemas\Payloads\UtilPayload;

// Helper function to get UserContext - either from parameter or global state
function getCompatibilityUserContext(?UserContext $userContext = null): UserContext
{
    if ($userContext !== null) {
        return $userContext;
    }

    // Try to get from global state or create a default one
    // This maintains backward compatibility with code using global $user
    global $user;

    if (class_exists('\EIOU\Context\UserContext')) {
        // Try to get current context or create from global user
        try {
            return UserContext::current() ?? UserContext::createFromGlobalUser($user);
        } catch (Exception $e) {
            // Fallback to creating from global
            return UserContext::createFromGlobalUser($user);
        }
    }

    // Last resort - create a minimal context
    return new UserContext();
}

// ============================================================================
// Contact Payload Functions
// ============================================================================

/**
 * @deprecated Use ContactPayload::buildCreateRequest() instead
 */
function createContactPayload(?UserContext $userContext = null): array
{
    trigger_error(
        'createContactPayload() is deprecated. Use ContactPayload::buildCreateRequest() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new ContactPayload($context);
    return $payload->buildCreateRequest();
}

/**
 * @deprecated Use ContactPayload::buildAccepted() instead
 */
function buildContactIsAcceptedPayload(string $address, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildContactIsAcceptedPayload() is deprecated. Use ContactPayload::buildAccepted() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new ContactPayload($context);
    return $payload->buildAccepted($address);
}

/**
 * @deprecated Use ContactPayload::buildAlreadyExists() instead
 */
function buildContactAlreadyExistsPayload(?UserContext $userContext = null): string
{
    trigger_error(
        'buildContactAlreadyExistsPayload() is deprecated. Use ContactPayload::buildAlreadyExists() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new ContactPayload($context);
    // Return as JSON string to maintain backward compatibility
    return json_encode($payload->buildAlreadyExists());
}

// ============================================================================
// Transaction Payload Functions
// ============================================================================

/**
 * @deprecated Use TransactionPayload::buildSend() instead
 */
function buildSendPayload(array $data, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildSendPayload() is deprecated. Use TransactionPayload::buildSend() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new TransactionPayload($context);
    return $payload->buildSend($data);
}

/**
 * @deprecated Use TransactionPayload::buildSendFromDatabase() instead
 */
function buildSendDatabasePayload(array $data, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildSendDatabasePayload() is deprecated. Use TransactionPayload::buildSendFromDatabase() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new TransactionPayload($context);
    return $payload->buildSendFromDatabase($data);
}

/**
 * @deprecated Use TransactionPayload::buildForwarding() instead
 */
function buildForwardingTransactionPayload(array $message, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildForwardingTransactionPayload() is deprecated. Use TransactionPayload::buildForwarding() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new TransactionPayload($context);

    // Need to get RP2P data - this would need to be refactored
    // For now, create minimal RP2P data from the message
    $rp2pData = [
        'time' => time(),
        'sender_address' => $message['sender_address'] ?? null,
        'sender_public_key' => $message['sender_public_key'] ?? null,
        'currency' => $message['currency'] ?? 'EIOU',
        'hash' => $message['memo'] ?? null,
    ];

    return $payload->buildForwarding($message, $rp2pData);
}

/**
 * @deprecated Use TransactionPayload::buildAcceptance() instead
 */
function buildSendAcceptancePayload(array $request): string
{
    trigger_error(
        'buildSendAcceptancePayload() is deprecated. Use TransactionPayload::buildAcceptance() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext();
    $payload = new TransactionPayload($context);
    // Return as JSON string to maintain backward compatibility
    return json_encode($payload->buildAcceptance($request));
}

/**
 * @deprecated Use TransactionPayload::buildCompleted() instead
 */
function buildSendCompletedPayload(array $request, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildSendCompletedPayload() is deprecated. Use TransactionPayload::buildCompleted() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new TransactionPayload($context);
    return $payload->buildCompleted($request);
}

/**
 * @deprecated Use TransactionPayload::buildRejection() instead
 */
function buildSendRejectionPayload(array $request): string
{
    trigger_error(
        'buildSendRejectionPayload() is deprecated. Use TransactionPayload::buildRejection() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext();
    $payload = new TransactionPayload($context);
    // Return as JSON string to maintain backward compatibility
    return json_encode($payload->buildRejection($request));
}

// ============================================================================
// P2P Payload Functions
// ============================================================================

/**
 * @deprecated Use P2pPayload::build() instead
 */
function buildP2pPayload(array $data, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildP2pPayload() is deprecated. Use P2pPayload::build() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new P2pPayload($context);
    return $payload->build($data);
}

/**
 * @deprecated Use P2pPayload::buildFromDatabase() instead
 */
function buildP2pPayloadDatabase(array $data, ?UserContext $userContext = null): array
{
    trigger_error(
        'buildP2pPayloadDatabase() is deprecated. Use P2pPayload::buildFromDatabase() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext($userContext);
    $payload = new P2pPayload($context);
    return $payload->buildFromDatabase($data);
}

/**
 * @deprecated Use P2pPayload::buildAcceptance() instead
 */
function buildP2pAcceptancePayload(array $request): void
{
    trigger_error(
        'buildP2pAcceptancePayload() is deprecated. Use P2pPayload::buildAcceptance() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext();
    $payload = new P2pPayload($context);
    // Echo JSON to maintain backward compatibility with old behavior
    echo json_encode($payload->buildAcceptance($request));
}

/**
 * @deprecated Use P2pPayload::buildRejection() instead
 */
function buildP2pRejectionPayload(array $request): string
{
    trigger_error(
        'buildP2pRejectionPayload() is deprecated. Use P2pPayload::buildRejection() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext();
    $payload = new P2pPayload($context);
    // Return as JSON string to maintain backward compatibility
    return json_encode($payload->buildRejection($request));
}

// ============================================================================
// Utility Payload Functions
// ============================================================================

/**
 * @deprecated Use UtilPayload::buildInsufficientBalance() instead
 */
function buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold): string
{
    trigger_error(
        'buildInsufficientBalancePayload() is deprecated. Use UtilPayload::buildInsufficientBalance() instead.',
        E_USER_DEPRECATED
    );

    // Return as JSON string to maintain backward compatibility
    return json_encode(UtilPayload::buildInsufficientBalance(
        (float) $availableFunds,
        (float) $requestedAmount,
        (float) $creditLimit,
        (float) $fundsOnHold
    ));
}

/**
 * @deprecated Use UtilPayload::buildInvalidTransactionId() instead
 */
function buildInvalidTransactionIDPayload(array $previousTxResult, array $request): string
{
    trigger_error(
        'buildInvalidTransactionIDPayload() is deprecated. Use UtilPayload::buildInvalidTransactionId() instead.',
        E_USER_DEPRECATED
    );

    // Return as JSON string to maintain backward compatibility
    return json_encode(UtilPayload::buildInvalidTransactionId($previousTxResult, $request));
}

/**
 * @deprecated Use UtilPayload::buildInvalidRequestLevel() instead
 */
function buildInvalidRequestLevelPayload(array $request): string
{
    trigger_error(
        'buildInvalidRequestLevelPayload() is deprecated. Use UtilPayload::buildInvalidRequestLevel() instead.',
        E_USER_DEPRECATED
    );

    // Return as JSON string to maintain backward compatibility
    return json_encode(UtilPayload::buildInvalidRequestLevel($request));
}

/**
 * @deprecated Use UtilPayload::buildInvalidSource() instead
 */
function buildMessageInvalidSourcePayload(array $message): string
{
    trigger_error(
        'buildMessageInvalidSourcePayload() is deprecated. Use UtilPayload::buildInvalidSource() instead.',
        E_USER_DEPRECATED
    );

    $context = getCompatibilityUserContext();
    // Return as JSON string to maintain backward compatibility
    return json_encode(UtilPayload::buildInvalidSource($message, $context));
}