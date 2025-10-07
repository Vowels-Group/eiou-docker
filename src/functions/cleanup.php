<?php
# Copyright 2025

require_once dirname(__DIR__,2) . '/src/database/P2pRepository.php';

/**
 * Check if there are any messages that will expire and process them
 *
 * This function retrieves all expiring P2P messages from the database and
 * expires those that have exceeded their expiration time.
 *
 * @return int Number of expiring messages processed
 * @throws PDOException If database query fails
 */
function processCleanupMessages(): int {
    try {
        $expiringMessages = getExpiringP2pMessages();

        // Process each not completed message
        foreach ($expiringMessages as $message) {
            // Validate message structure
            if (!isset($message['expiration']) || !is_numeric($message['expiration'])) {
                error_log("Invalid message expiration: " . json_encode($message));
                continue;
            }

            // If no response after set amount of time, expire the p2p (and potential transaction)
            if (returnMicroTime() > $message['expiration']) {
                expireMessage($message);
            }
        }
        return isset($expiringMessages) ? count($expiringMessages) : 0;
    } catch (PDOException $e) {
        error_log("Error processing cleanup messages: " . $e->getMessage());
        throw $e;
    }
}