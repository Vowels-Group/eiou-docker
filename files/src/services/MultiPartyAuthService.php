<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\MultiPartyAuthServiceInterface;
use Eiou\Core\UserContext;
use PDO;
use Exception;
use RuntimeException;

/**
 * Multi-Party Authorization Service (Patent Claims 51-56)
 *
 * M-of-N threshold authorization for transactions. Operates entirely within
 * a single node — counterparties see a normal transaction after approval.
 */
class MultiPartyAuthService implements MultiPartyAuthServiceInterface
{
    private PDO $pdo;
    private UserContext $currentUser;

    // Default expiration for auth requests (24 hours)
    const DEFAULT_EXPIRATION_SECONDS = 86400;

    public function __construct(PDO $pdo, UserContext $currentUser)
    {
        $this->pdo = $pdo;
        $this->currentUser = $currentUser;
    }

    /**
     * Check if a transaction requires multi-party authorization (Claim 51)
     */
    public function requiresAuthorization(array $transaction): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM auth_policies 
            WHERE is_active = 1 
            ORDER BY priority DESC
        ");
        $stmt->execute();
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($policies as $policy) {
            if ($this->policyMatches($policy, $transaction)) {
                $requiredM = $this->getRequiredM($policy, $transaction);
                if ($requiredM > 1) {
                    $policy['effective_m'] = $requiredM;
                    return $policy;
                }
            }
        }

        return null;
    }

    /**
     * Determine required M based on tiered thresholds (Claim 52)
     */
    private function getRequiredM(array $policy, array $transaction): int
    {
        // Check for tiered thresholds first
        $stmt = $this->pdo->prepare("
            SELECT * FROM auth_policy_tiers 
            WHERE policy_id = :policy_id 
            AND (currency IS NULL OR currency = :currency)
            ORDER BY min_amount DESC
        ");
        $stmt->execute([
            'policy_id' => $policy['id'],
            'currency' => $transaction['currency'] ?? 'USD',
        ]);
        $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $amount = (float)($transaction['amount'] ?? 0);

        foreach ($tiers as $tier) {
            $minAmount = (float)($tier['min_amount'] ?? 0);
            $maxAmount = $tier['max_amount'] !== null ? (float)$tier['max_amount'] : PHP_FLOAT_MAX;

            if ($amount >= $minAmount && $amount <= $maxAmount) {
                return (int)$tier['required_m'];
            }
        }

        // No tier matched, use policy default
        return (int)$policy['threshold_m'];
    }

    /**
     * Check if policy scope matches transaction (Claim 53)
     */
    private function policyMatches(array $policy, array $transaction): bool
    {
        return match($policy['scope_type']) {
            'global' => true,
            'amount' => ($transaction['amount'] ?? 0) >= (float)$policy['scope_value'],
            'currency' => ($transaction['currency'] ?? '') === $policy['scope_value'],
            'counterparty' => ($transaction['contact_pubkey'] ?? '') === $policy['scope_value'],
            'tx_type' => ($transaction['type'] ?? '') === $policy['scope_value'],
            default => false,
        };
    }

    /**
     * Create authorization request (Claim 51, step 2-3)
     */
    public function createRequest(array $transaction, array $policy): array
    {
        $txHash = hash('sha256', json_encode($transaction));
        $requestId = $this->generateUUID();
        $requiredM = $policy['effective_m'] ?? $policy['threshold_m'];
        $expiresAt = date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRATION_SECONDS);

        // Encrypt transaction data (only decryptable by this node)
        $encryptedData = $this->encryptTransactionData($transaction);

        $stmt = $this->pdo->prepare("
            INSERT INTO auth_requests 
                (id, policy_id, transaction_hash, transaction_data, required_m, status, expires_at, created_at)
            VALUES 
                (:id, :policy_id, :tx_hash, :tx_data, :required_m, 'pending', :expires_at, NOW(6))
        ");
        $stmt->execute([
            'id' => $requestId,
            'policy_id' => $policy['id'],
            'tx_hash' => $txHash,
            'tx_data' => $encryptedData,
            'required_m' => $requiredM,
            'expires_at' => $expiresAt,
        ]);

        // Audit log
        $this->auditLog($requestId, 'request_created', null, [
            'policy_id' => $policy['id'],
            'required_m' => $requiredM,
            'transaction_hash' => $txHash,
        ]);

        Logger::info("Auth request created: {$requestId} (M={$requiredM}, policy={$policy['id']})");

        return [
            'request_id' => $requestId,
            'required_m' => $requiredM,
            'expires_at' => $expiresAt,
            'transaction_hash' => $txHash,
        ];
    }

    /**
     * Submit approval or rejection (Claim 51, step 4-5)
     */
    public function submitApproval(string $requestId, string $approverPubkey, string $signature, bool $approved, ?string $reason = null): array
    {
        $request = $this->getRequestInternal($requestId);

        if (!$request) {
            throw new RuntimeException("Auth request not found: {$requestId}");
        }
        if ($request['status'] !== 'pending') {
            throw new RuntimeException("Auth request not pending (status: {$request['status']})");
        }
        if (strtotime($request['expires_at']) < time()) {
            $this->expireRequest($requestId);
            throw new RuntimeException("Auth request expired");
        }

        // Verify approver is authorized for this policy
        $stmt = $this->pdo->prepare("
            SELECT * FROM authorized_keys 
            WHERE policy_id = :policy_id AND public_key = :pubkey AND is_active = 1
        ");
        $stmt->execute(['policy_id' => $request['policy_id'], 'pubkey' => $approverPubkey]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$key) {
            throw new RuntimeException("Key not authorized for this policy");
        }

        // TODO: Verify cryptographic signature over (requestId + transactionHash)
        // For now, store the signature for verification
        $approvalHash = hash('sha256', $requestId . $request['transaction_hash']);

        // Record approval (unique per approver per request)
        $stmt2 = $this->pdo->prepare("
            INSERT INTO auth_approvals (request_id, approver_pubkey, approval_hash, signature, approved, reason, created_at)
            VALUES (:request_id, :pubkey, :hash, :sig, :approved, :reason, NOW(6))
            ON DUPLICATE KEY UPDATE 
                signature = :sig2, approved = :approved2, reason = :reason2, created_at = NOW(6)
        ");
        $stmt2->execute([
            'request_id' => $requestId,
            'pubkey' => $approverPubkey,
            'hash' => $approvalHash,
            'sig' => $signature, 'sig2' => $signature,
            'approved' => $approved ? 1 : 0, 'approved2' => $approved ? 1 : 0,
            'reason' => $reason, 'reason2' => $reason,
        ]);

        $this->auditLog($requestId, $approved ? 'approval' : 'rejection', $approverPubkey, [
            'reason' => $reason,
            'key_label' => $key['label'],
        ]);

        // Check thresholds
        $approvalCount = $this->countApprovals($requestId, true);
        $rejectCount = $this->countApprovals($requestId, false);
        $totalKeys = $this->countActiveKeys($request['policy_id']);
        $requiredM = (int)$request['required_m'];

        // Enough approvals → execute
        if ($approvalCount >= $requiredM) {
            $this->resolveRequest($requestId, 'approved');
            Logger::info("Auth request approved: {$requestId} ({$approvalCount}/{$requiredM})");
            return ['status' => 'approved', 'approvals' => $approvalCount, 'required' => $requiredM];
        }

        // Too many rejections → impossible to reach M
        if ($rejectCount > ($totalKeys - $requiredM)) {
            $this->resolveRequest($requestId, 'rejected');
            Logger::info("Auth request rejected: {$requestId} (too many rejections)");
            return ['status' => 'rejected', 'approvals' => $approvalCount, 'required' => $requiredM];
        }

        return ['status' => 'pending', 'approvals' => $approvalCount, 'required' => $requiredM];
    }

    /**
     * Emergency override (Claim 55)
     * Bypasses normal M-of-N threshold. Mandatory audit trail.
     */
    public function emergencyOverride(string $requestId, string $emergencyPubkey, string $signature): array
    {
        $request = $this->getRequestInternal($requestId);
        if (!$request) throw new RuntimeException("Request not found");
        if ($request['status'] !== 'pending') throw new RuntimeException("Request not pending");

        // Verify emergency key
        $stmt = $this->pdo->prepare("
            SELECT * FROM authorized_keys 
            WHERE policy_id = :policy_id AND public_key = :pubkey AND is_emergency = 1 AND is_active = 1
        ");
        $stmt->execute(['policy_id' => $request['policy_id'], 'pubkey' => $emergencyPubkey]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$key) {
            throw new RuntimeException("Not an authorized emergency key");
        }

        // MANDATORY audit trail (Claim 55)
        $this->auditLog($requestId, 'emergency_override', $emergencyPubkey, [
            'key_label' => $key['label'],
            'normal_threshold' => $request['required_m'],
            'timestamp' => time(),
        ]);

        $this->resolveRequest($requestId, 'approved');
        Logger::warning("EMERGENCY OVERRIDE: Auth request {$requestId} approved by emergency key {$emergencyPubkey}");

        return ['status' => 'approved', 'override' => true];
    }

    /**
     * Key replacement (Claim 54)
     */
    public function initiateKeyReplacement(int $policyId, string $lostPubkey, string $newPubkey): string
    {
        // Deactivate lost key
        $stmt = $this->pdo->prepare("
            UPDATE authorized_keys SET is_active = 0 WHERE policy_id = :policy AND public_key = :pubkey
        ");
        $stmt->execute(['policy' => $policyId, 'pubkey' => $lostPubkey]);

        // Create replacement request (this itself could require M-1 approval)
        $recoveryId = $this->generateUUID();

        $this->auditLog(null, 'key_replacement_initiated', null, [
            'recovery_id' => $recoveryId,
            'policy_id' => $policyId,
            'lost_key' => $lostPubkey,
            'new_key' => $newPubkey,
        ]);

        // Add new key as pending (needs votes to activate)
        $stmt2 = $this->pdo->prepare("
            INSERT INTO authorized_keys (policy_id, public_key, label, is_active, added_at)
            VALUES (:policy, :pubkey, 'Replacement (pending)', 0, NOW(6))
        ");
        $stmt2->execute(['policy' => $policyId, 'pubkey' => $newPubkey]);

        Logger::info("Key replacement initiated for policy {$policyId}: {$lostPubkey} → {$newPubkey}");
        return $recoveryId;
    }

    /**
     * Get pending requests
     */
    public function getPendingRequests(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, 
                (SELECT COUNT(*) FROM auth_approvals WHERE request_id = r.id AND approved = 1) as approval_count
            FROM auth_requests r
            WHERE r.status = 'pending' AND r.expires_at > NOW()
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get request details
     */
    public function getRequest(string $requestId): ?array
    {
        $request = $this->getRequestInternal($requestId);
        if (!$request) return null;

        // Add approvals
        $stmt = $this->pdo->prepare("
            SELECT a.*, k.label as key_label 
            FROM auth_approvals a
            LEFT JOIN authorized_keys k ON a.approver_pubkey = k.public_key AND k.policy_id = :policy_id
            WHERE a.request_id = :request_id
        ");
        $stmt->execute(['request_id' => $requestId, 'policy_id' => $request['policy_id']]);
        $request['approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $request;
    }

    /**
     * Expire stale requests
     */
    public function expireStaleRequests(): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE auth_requests 
            SET status = 'expired', resolved_at = NOW(6) 
            WHERE status = 'pending' AND expires_at < NOW()
        ");
        $stmt->execute();
        $count = $stmt->rowCount();
        if ($count > 0) {
            Logger::info("Expired {$count} stale auth requests");
        }
        return $count;
    }

    // --- Policy Management ---

    public function createPolicy(array $policy): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO auth_policies (name, scope_type, scope_value, threshold_m, threshold_n, priority, created_at)
            VALUES (:name, :scope_type, :scope_value, :m, :n, :priority, NOW(6))
        ");
        $stmt->execute([
            'name' => $policy['name'] ?? null,
            'scope_type' => $policy['scope_type'] ?? 'global',
            'scope_value' => $policy['scope_value'] ?? null,
            'm' => $policy['threshold_m'],
            'n' => $policy['threshold_n'],
            'priority' => $policy['priority'] ?? 0,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        $this->auditLog(null, 'policy_created', null, ['policy_id' => $id, 'policy' => $policy]);
        return $id;
    }

    public function addAuthorizedKey(int $policyId, string $publicKey, string $label, bool $isEmergency = false): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO authorized_keys (policy_id, public_key, label, is_emergency, is_active, added_at)
            VALUES (:policy, :pubkey, :label, :emergency, 1, NOW(6))
        ");
        $stmt->execute([
            'policy' => $policyId,
            'pubkey' => $publicKey,
            'label' => $label,
            'emergency' => $isEmergency ? 1 : 0,
        ]);

        $id = (int)$this->pdo->lastInsertId();

        // Update threshold_n on policy
        $this->updatePolicyN($policyId);

        $this->auditLog(null, 'key_added', null, [
            'policy_id' => $policyId, 'key_id' => $id, 'label' => $label, 'emergency' => $isEmergency,
        ]);
        return $id;
    }

    public function removeAuthorizedKey(int $keyId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE authorized_keys SET is_active = 0 WHERE id = :id");
        $stmt->execute(['id' => $keyId]);

        $this->auditLog(null, 'key_removed', null, ['key_id' => $keyId]);
        return $stmt->rowCount() > 0;
    }

    // --- Private helpers ---

    private function getRequestInternal(string $requestId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM auth_requests WHERE id = :id");
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function countApprovals(string $requestId, bool $approved): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM auth_approvals 
            WHERE request_id = :id AND approved = :approved
        ");
        $stmt->execute(['id' => $requestId, 'approved' => $approved ? 1 : 0]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? (int)$r['cnt'] : 0;
    }

    private function countActiveKeys(int $policyId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM authorized_keys 
            WHERE policy_id = :id AND is_active = 1
        ");
        $stmt->execute(['id' => $policyId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? (int)$r['cnt'] : 0;
    }

    private function resolveRequest(string $requestId, string $status): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE auth_requests SET status = :status, resolved_at = NOW(6) WHERE id = :id
        ");
        $stmt->execute(['status' => $status, 'id' => $requestId]);
    }

    private function expireRequest(string $requestId): void
    {
        $this->resolveRequest($requestId, 'expired');
    }

    private function updatePolicyN(int $policyId): void
    {
        $n = $this->countActiveKeys($policyId);
        $stmt = $this->pdo->prepare("UPDATE auth_policies SET threshold_n = :n WHERE id = :id");
        $stmt->execute(['n' => $n, 'id' => $policyId]);
    }

    private function encryptTransactionData(array $transaction): string
    {
        // Simple encryption with node's key — only this node can decrypt
        // In production, use proper envelope encryption
        $json = json_encode($transaction);
        $key = hash('sha256', $this->currentUser->getPrivateKey(), true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function auditLog(?string $requestId, string $action, ?string $actorPubkey, array $details = []): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO auth_audit_log (request_id, action, actor_pubkey, details, created_at)
            VALUES (:request_id, :action, :actor, :details, NOW(6))
        ");
        $stmt->execute([
            'request_id' => $requestId,
            'action' => $action,
            'actor' => $actorPubkey,
            'details' => json_encode($details),
        ]);
    }

    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
