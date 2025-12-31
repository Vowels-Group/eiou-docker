# Cascading Chain Sync Implementation for PR #317

## Overview

This document describes the cascading chain functionality implementation for transaction synchronization in PR #317.

## Problem Statement

Originally, transaction sync would send inquiries directly to end-recipients. For P2P chains (A->B->C->D), this doesn't work because:
1. Original sender (A) may not have end-recipient (D) as a direct contact
2. Intermediaries (B, C) need to verify their leg of the chain completed
3. Partial chain failures need to be detected (e.g., C never forwarded to D)

## Solution: Cascading Chain Inquiries

Instead of sending inquiries directly to the end-recipient, sync sends to the intermediary contact, who forwards through the chain.

### Chain Flow Example (A->B->C->D)

```
1. A sends sync inquiry to B (intermediary, not D)
2. B receives inquiry:
   - Checks if local P2P status is 'completed'
   - Checks if B has destination_address (is B the end-recipient?)
   - B is intermediary, so looks up next hop from rp2p table
   - B forwards inquiry to C
3. C receives inquiry:
   - Same checks as B
   - C forwards to D
4. D receives inquiry:
   - D is end-recipient (no destination_address)
   - D responds with transaction status
5. Responses propagate back:
   - D->C->B->A
   - Each node can mark local P2P as verified
```

## Implementation Changes

### 1. Rp2pRepository.php

**New Method**: `getChainIntermediaryContact(string $hash): ?array`

```php
/**
 * Get intermediary contact used to reach end-recipient in P2P chain
 *
 * For a P2P transaction with a given hash (memo), this finds the direct contact
 * that we sent the transaction to (the next hop in the chain toward the destination).
 *
 * @param string $hash P2P transaction hash (memo)
 * @return array|null Intermediary contact info with 'pubkey', 'address', 'pubkey_hash', or null
 */
public function getChainIntermediaryContact(string $hash): ?array {
    $query = "SELECT
                rp2p.sender_public_key as pubkey,
                rp2p.sender_address as address,
                SHA2(rp2p.sender_public_key, 256) as pubkey_hash
              FROM rp2p
              WHERE rp2p.hash = :hash
              LIMIT 1";

    $stmt = $this->execute($query, [':hash' => $hash]);
    if (!$stmt) {
        return null;
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}
```

**Purpose**: Finds the intermediary contact (next hop) for a P2P transaction. The rp2p table stores who responded to our P2P request, which is our next hop in the chain.

### 2. TransactionRepository.php

**New Method**: `getTransactionsBySenderPubkeyAndStatus(string $senderPubkey, array $statuses): array`

```php
/**
 * Get transactions by sender public key and status
 *
 * Used for transaction sync - gets all transactions sent by user with specific statuses
 *
 * @param string $senderPubkey Sender's public key
 * @param array $statuses Array of statuses to filter by (e.g., ['sent', 'pending'])
 * @return array Array of transactions
 */
public function getTransactionsBySenderPubkeyAndStatus(string $senderPubkey, array $statuses): array {
    if (empty($statuses)) {
        return [];
    }

    $placeholders = str_repeat('?,', count($statuses) - 1) . '?';

    $query = "SELECT * FROM transactions
              WHERE sender_public_key = ?
                AND status IN ($placeholders)
              ORDER BY timestamp DESC";

    $stmt = $this->pdo->prepare($query);

    try {
        $params = array_merge([$senderPubkey], $statuses);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $this->logError("Failed to retrieve transactions by sender and status", $e);
        return [];
    }
}
```

**Purpose**: Retrieves all transactions sent by a user with specific statuses for bulk sync operations.

### 3. SyncService.php

**Updated Method**: `syncAllTransactionsInternal()`

```php
private function syncAllTransactionsInternal(): array {
    // Get all transactions where we are the sender and status is 'sent' or 'pending'
    // Exclude contact transactions (handled by contact sync)
    $userPubkey = $this->currentUser->getPublicKey();
    $transactions = $this->transactionRepository->getTransactionsBySenderPubkeyAndStatus(
        $userPubkey,
        ['sent', 'pending']
    );

    // Filter out contact transactions
    $transactions = array_filter($transactions, function($tx) {
        return $tx['tx_type'] !== 'contact';
    });

    $results = [
        'total' => count($transactions),
        'synced' => 0,
        'failed' => 0,
        'details' => []
    ];

    foreach ($transactions as $transaction) {
        $success = $this->syncSingleTransaction($transaction);
        if ($success) {
            $results['synced']++;
            $results['details'][] = [
                'txid' => $transaction['txid'],
                'status' => 'synced'
            ];
        } else {
            $results['failed']++;
            $results['details'][] = [
                'txid' => $transaction['txid'],
                'status' => 'failed'
            ];
        }
    }

    return $results;
}
```

**New Method**: `syncSingleTransaction(array $transaction): bool`

```php
/**
 * Sync single transaction with cascading chain inquiry
 *
 * For P2P transactions, this sends the inquiry to the direct intermediary contact,
 * not to the end-recipient. The inquiry cascades through the chain:
 * A->B->C->D: A sends inquiry to B, B forwards to C, C forwards to D, D responds back
 *
 * @param array $transaction Transaction data
 * @return bool True if synced successfully, false otherwise
 */
public function syncSingleTransaction(array $transaction): bool {
    $txType = $transaction['tx_type'];
    $status = $transaction['status'];

    // Only sync sent/pending transactions
    if (!in_array($status, ['sent', 'pending'])) {
        return true; // Already synced or not applicable
    }

    try {
        if ($txType === 'standard') {
            // Direct transaction to known contact - send inquiry directly to receiver
            return $this->syncDirectTransaction($transaction);
        } elseif ($txType === 'p2p') {
            // P2P transaction - use cascading inquiry through chain
            return $this->syncP2pTransaction($transaction);
        }

        return false;
    } catch (Exception $e) {
        if (function_exists('output')) {
            output("[SyncService] Transaction sync error: " . $e->getMessage(), 'SILENT');
        }
        return false;
    }
}
```

**New Method**: `syncDirectTransaction(array $transaction): bool`

Handles sync for direct (standard) transactions - sends inquiry directly to receiver.

**New Method**: `syncP2pTransaction(array $transaction): bool`

```php
/**
 * Sync P2P transaction using cascading chain inquiry
 *
 * Instead of sending inquiry directly to end-recipient (which we may not have as contact),
 * we send to our intermediary contact who forwards it through the chain.
 *
 * Chain example: A->B->C->D
 * - A sends inquiry to B (intermediary)
 * - B receives, checks local status, forwards to C
 * - C receives, checks local status, forwards to D
 * - D receives, checks local status, responds 'completed' back to C
 * - C marks complete, responds back to B
 * - B marks complete, responds back to A
 * - A marks complete
 *
 * @param array $transaction Transaction data
 * @return bool True if synced successfully
 */
private function syncP2pTransaction(array $transaction): bool {
    $memo = $transaction['memo'];

    if (!$memo) {
        return false;
    }

    // Get P2P record
    $p2p = $this->p2pRepository->getByHash($memo);
    if (!$p2p) {
        return false;
    }

    // Check if we're the original sender (have destination_address set)
    $isOriginalSender = !empty($p2p['destination_address']);

    if ($isOriginalSender) {
        // Original sender: find intermediary contact from rp2p table
        $intermediary = $this->rp2pRepository->getChainIntermediaryContact($memo);

        if (!$intermediary) {
            // No intermediary found - transaction never propagated
            if (function_exists('output')) {
                output("[SyncService] No intermediary found for P2P transaction {$memo}", 'SILENT');
            }
            return false;
        }

        // Build cascading inquiry payload
        $inquiryPayload = $this->messagePayload->buildTransactionCompletedInquiry([
            'hash' => $memo,
            'hashType' => 'memo',
            'description' => $p2p['description'] ?? null,
            'cascading' => true // Flag for intermediaries to forward
        ]);

        // Send inquiry to intermediary (not end-recipient)
        $response = json_decode(
            $this->transportUtility->send($intermediary['address'], $inquiryPayload),
            true
        );

        if ($response && $response['status'] === 'completed') {
            // Chain completed successfully
            $this->p2pRepository->updateStatus($memo, 'completed', true);
            $this->transactionRepository->updateStatus($memo, 'completed');

            // Get all transactions with this memo for balance update
            $transactions = $this->transactionRepository->getByMemo($memo);
            $this->balanceRepository->updateBalanceGivenTransactions($transactions);

            if (function_exists('output') && function_exists('outputTransactionP2pSentSuccesfully')) {
                output(outputTransactionP2pSentSuccesfully($p2p), 'SILENT');
            }

            return true;
        } elseif ($response && isset($response['chain_status'])) {
            // Partial chain failure - intermediary responded but chain incomplete
            if (function_exists('output')) {
                output("[SyncService] P2P chain incomplete at: " . ($response['failed_at'] ?? 'unknown'), 'SILENT');
            }
            return false;
        }

        return false;
    } else {
        // We're an intermediary/relay - check local status only
        // (sync is driven by original sender)
        return $p2p['status'] === 'completed';
    }
}
```

### 4. MessageService.php

**Updated Method**: `handleTransactionMessageInquiryRequest(array $decodedMessage): void`

Now checks for `cascading` flag in inquiry and delegates to `handleCascadingInquiry()` if present.

**New Method**: `handleCascadingInquiry(array $decodedMessage): void`

```php
/**
 * Handle cascading inquiry for P2P transaction chain
 *
 * When an intermediary receives a cascading inquiry:
 * 1. Check if local P2P is completed
 * 2. If completed, check if we have destination_address (we're not end-recipient)
 * 3. If we have destination, we're intermediary - forward inquiry to next hop
 * 4. Relay response back to sender
 *
 * Chain flow: A->B->C->D
 * - B receives from A, forwards to C, relays C's response to A
 * - C receives from B, forwards to D, relays D's response to B
 * - D receives from C, responds 'completed'
 *
 * @param array $decodedMessage Decoded message data
 * @return void
 */
private function handleCascadingInquiry(array $decodedMessage): void {
    require_once '/etc/eiou/src/database/Rp2pRepository.php';
    $rp2pRepository = new Rp2pRepository();

    $hash = $decodedMessage['hash'];

    // Get local P2P record
    $p2p = $this->p2pRepository->getByHash($hash);

    if (!$p2p) {
        // No P2P record found - we never received this transaction
        echo json_encode([
            'status' => 'unknown',
            'message' => 'Transaction not found',
            'chain_status' => 'broken',
            'failed_at' => 'intermediary_no_record'
        ]);
        return;
    }

    // Check local status
    if ($p2p['status'] !== 'completed') {
        // Our leg of the chain isn't complete
        echo json_encode([
            'status' => 'pending',
            'message' => 'Transaction not completed at this hop',
            'chain_status' => 'incomplete',
            'failed_at' => 'intermediary_not_completed',
            'current_status' => $p2p['status']
        ]);
        return;
    }

    // Check if we're the end-recipient or an intermediary
    $isEndRecipient = empty($p2p['destination_address']);

    if ($isEndRecipient) {
        // We're the end-recipient - respond with completed status
        $status = $this->transactionRepository->getStatusByMemo($hash);
        if ($status !== null) {
            echo $this->messagePayload->buildTransactionStatusResponse($decodedMessage, $status);
        } else {
            echo $this->messagePayload->buildTransactionNotFound($decodedMessage);
        }
        return;
    }

    // We're an intermediary - forward inquiry to next hop
    $nextHop = $rp2pRepository->getChainIntermediaryContact($hash);

    if (!$nextHop) {
        // Chain broken - we have no record of forwarding this
        echo json_encode([
            'status' => 'error',
            'message' => 'Next hop not found',
            'chain_status' => 'broken',
            'failed_at' => 'intermediary_no_next_hop'
        ]);
        return;
    }

    // Forward inquiry to next hop
    $forwardedInquiry = $this->messagePayload->buildTransactionCompletedInquiry($decodedMessage);

    try {
        $response = json_decode(
            $this->transportUtility->send($nextHop['address'], $forwardedInquiry),
            true
        );

        if (!$response) {
            // Next hop didn't respond
            echo json_encode([
                'status' => 'error',
                'message' => 'Next hop not responding',
                'chain_status' => 'broken',
                'failed_at' => 'intermediary_forwarding_failed'
            ]);
            return;
        }

        // Relay response back to sender
        echo json_encode($response);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to forward inquiry: ' . $e->getMessage(),
            'chain_status' => 'broken',
            'failed_at' => 'intermediary_exception'
        ]);
    }
}
```

## Test Scenarios

1. **Direct Transaction Sync (A->B)**
   - Alice sends to Bob, syncs directly
   - Expected: Alice queries Bob directly, gets 'completed' status

2. **2-Hop P2P Sync (A->B->C)**
   - Alice sends P2P through Bob to Carol
   - Expected: Alice queries Bob (intermediary), Bob forwards to Carol, relays response

3. **3-Hop P2P Sync (A->B->C->D)**
   - Alice sends P2P through Bob->Carol to Dave
   - Expected: Alice->Bob->Carol->Dave chain inquiry

4. **Chain Break - Missing Intermediary**
   - Alice sends P2P, but intermediary has no rp2p record
   - Expected: Sync fails with 'intermediary_no_record' error

5. **Partial Chain Failure (A->B->C where C never forwarded)**
   - Alice sends to Dave via Bob->Carol, but Carol never forwarded to Dave
   - Expected: Sync gets 'chain_incomplete' from Carol

6. **Intermediary Not Completed Status**
   - P2P chain exists but intermediary status is 'sent' not 'completed'
   - Expected: Sync gets 'intermediary_not_completed' response

7. **Multiple Sync Calls (Idempotency)**
   - Run sync multiple times on same completed transaction
   - Expected: All return 'completed', no errors

8. **Sync All Transactions**
   - Test syncAllTransactions with mix of direct and P2P
   - Expected: All applicable transactions synced correctly

## Error Handling

The implementation includes comprehensive error detection:

- `chain_status: 'broken'` - Chain is broken at some point
- `chain_status: 'incomplete'` - Chain exists but not all hops completed
- `failed_at: 'intermediary_no_record'` - Intermediary has no P2P record
- `failed_at: 'intermediary_not_completed'` - Intermediary's leg not complete
- `failed_at: 'intermediary_no_next_hop'` - Intermediary can't find next hop
- `failed_at: 'intermediary_forwarding_failed'` - Next hop not responding
- `failed_at: 'intermediary_exception'` - Exception during forwarding

## Benefits

1. **Correct Routing**: Inquiries follow the same path as the original transaction
2. **Partial Failure Detection**: Can identify exactly where in the chain a problem occurred
3. **Privacy Preserved**: Original sender doesn't need direct contact with end-recipient
4. **Scalable**: Works for chains of any length
5. **Backwards Compatible**: Direct transactions still work the same way

## Next Steps

To complete the implementation:

1. Apply the code changes documented above to the actual source files
2. Run comprehensive tests with Docker topology
3. Verify cascading works through 2-hop and 3-hop chains
4. Test partial failure scenarios
5. Update PR #317 description with cascading functionality details
