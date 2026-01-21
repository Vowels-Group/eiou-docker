<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Contact Repository
 *
 * Manages all database interactions for the contacts table.
 *
 * @package Database\Repository
 */
class ContactRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'contacts';
        $this->primaryKey = 'pubkey';
    }

    /**
     * Validate transport index to prevent SQL injection
     * Input is lowercased for case-insensitive matching
     *
     * @param string $transportIndex The transport index to validate
     * @return bool True if valid
     */
    private function isValidTransportIndex(string $transportIndex): bool {
        return in_array(strtolower($transportIndex), Constants::VALID_TRANSPORT_INDICES, true);
    }

    /**
     * Accept a contact request
     *
     * @param string $senderPublicKey pubkey of Sender
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    public function acceptContact(string $senderPublicKey, string $name, float $fee, float $credit, string $currency): bool {
        $data = [
            'name' => $name,
            'status' => 'accepted',
            'fee_percent' => $fee,
            'credit_limit' => $credit,
            'currency' => $currency
        ];

        $affectedRows = $this->update($data, $this->primaryKey, $senderPublicKey);
        return $affectedRows > 0;
    }

    /**
     * Add a pending contact (incoming request)
     *
     * @param string $senderPublicKey Sender's public key
     * @return string JSON response
     */
    public function addPendingContact(string $senderPublicKey): string {
        $data = [
            'contact_id' => $this->generateContactId(),
            'pubkey' => $senderPublicKey,
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $senderPublicKey),
            'name' => null,
            'status' => 'pending',
            'fee_percent' => null,
            'credit_limit' => null,
            'currency' => null
        ];
        return $this->insert($data);
    }

    /**
     * Get Contact Pubkey through address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
    */
    public function getPublicKeyFromAddress(string $transportIndex, string $address) {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT pubkey FROM {$this->tableName} c JOIN addresses a
                  ON c.pubkey_hash = a.pubkey_hash
                  AND a.{$transportIndex} = :address";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Block a contact
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return bool Success status
     */
    public function blockContact(string $transportIndex, string $address): bool {
        $affectedRows = $this->update(['status' => 'blocked'], $this->primaryKey, $this->getPublicKeyFromAddress($transportIndex, $address));
        return $affectedRows > 0;
    }

    /**
     * Unblock a contact
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return bool Success status
     */
    public function unblockContact(string $transportIndex, string $address): bool {
        $affectedRows = $this->update(['status' => 'accepted'], $this->primaryKey, $this->getPublicKeyFromAddress($transportIndex, $address));
        return $affectedRows > 0;
    }

    /**
     * Delete a contact
     *
     * @param string $pubkey Contact pubkey
     * @return bool Success status
     */
    public function deleteContact(string $pubkey): bool {
        $deletedRows = $this->delete($this->primaryKey, $pubkey);
        return $deletedRows > 0;
    }

    /**
     * Update contact status
     *
     * @param string $pubkey Contact pubkey
     * @param string $status
     * @return bool
     */
    public function updateContactStatus(string $pubkey, string $status): bool
    {
        $query ="UPDATE {$this->tableName} 
                    SET status = ? 
                    WHERE $this->primaryKey = ?";
        $stmt = $this->execute($query,[$status, $pubkey]);
        if(!$stmt){
            return false;
        }
        return true;
    }

    /**
     * Check if contact is accepted (by pubkey)
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if accepted
     */
    public function isAcceptedContactPubkey(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName} 
                    WHERE {$this->primaryKey} = :pubkey 
                    AND status = 'accepted'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetchColumn();
        return ((int) ($result ?? 0)) > 0;
    }

        /**
     * Check if contact is accepted (by address)
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return bool True if accepted
     */
    public function isAcceptedContactAddress(string $transportIndex, string $address): bool {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return false;
        }
        $query = "SELECT COUNT(*) as count
                    FROM {$this->tableName} c JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND a.{$transportIndex} = :address
                    AND status = 'accepted'";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetchColumn();
        return ((int) ($result ?? 0)) > 0;
    }



    /**
     * Check how many contacts are accepted
     *
     * @return int amount accepted contacts
     */
    public function countAcceptedContacts(): int {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName} 
                    WHERE status = 'accepted'";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetchColumn();
        return (int) ($result ?? 0);
    }

    /**
     * Check if contact exists
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return bool True if exists
     */
    public function contactExists(string $transportIndex, string $address): bool {
        return $this->exists($this->primaryKey, $this->getPublicKeyFromAddress($transportIndex, $address));
    }

    /**
     * Check if contact exists
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if exists
     */
    public function contactExistsPubkey(string $pubkey): bool {
        return $this->exists($this->primaryKey, $pubkey);
    }

    /**
     * Check for new contact requests since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewContactRequests(int $lastCheckTime): bool
    {   
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                    WHERE name IS NULL AND status = 'pending'
                    AND created_at > ?";
        $stmt = $this->execute($query,[date(Constants::DISPLAY_DATE_FORMAT, $lastCheckTime)]);
        if(!$stmt){
            return false;
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Check if contact is blocked
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if NOT blocked (returns result suitable for validation)
     */
    public function isNotBlocked(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName} 
                    WHERE {$this->primaryKey} = :pubkey 
                    AND status = 'blocked'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return true; // Allow if query fails (fail open for validation)
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0) <= 0;
    }

    /**
     * Check contact status (returns true if status is NOT accepted)
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if status is not 'accepted'
     */
    public function hasNonAcceptedStatus(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName} 
                    WHERE {$this->primaryKey} = :pubkey 
                    AND status != 'accepted'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if there's a pending contact (non-user initiated)
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if pending
     */
    public function hasPendingContact(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName}
                    WHERE {$this->primaryKey} = :pubkey 
                    AND name IS NULL 
                    AND status = 'pending'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if there's a pending contact (user initiated)
     *
     * @param @param string $pubkey Contact pubkey
     * @return bool True if pending with name
     */
    public function hasPendingContactInserted(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count 
                    FROM {$this->tableName}
                    WHERE {$this->primaryKey} = :pubkey 
                    AND name IS NOT NULL 
                    AND status = 'pending'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0) > 0;
    }


    /**
     * Get status of contact
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return string|null status of contact or null if not found
     */
    public function getContactStatus(string $transportIndex, string $address): ?string {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT c.status
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND a.{$transportIndex} = :address";

        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Get all pending contact requests (non-user initiated)
     *
     * @return array Array of pending contacts
     */
    public function getPendingContactRequests(): array {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    WHERE c.name IS NULL 
                    AND c.status = 'pending'";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all contacts by status (non-user initiated)
     *
     * @param string $status Contact status (default: 'pending')
     * @return array Array of contacts with status
     */
    public function getContactsByStatus(string $status): array {
        $query = "SELECT * 
                    FROM {$this->tableName}
                    WHERE status = :status";
        $stmt = $this->execute($query,[':status' => $status]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user pending contacts (requests sent by user)
     *
     * @return array Array of pending contacts
     */
    public function getUserPendingContactRequests(): array
    {
        // Get all pending contact requests (where name IS NOT NULL and status = 'pending')
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    WHERE c.name IS NOT NULL 
                    AND c.status = 'pending'";
        $stmt = $this->execute($query);
        if(!$stmt){
            return [];
        } 
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all accepted contacts
     *
     * @return array
     */
    public function getAcceptedContacts(): array
    {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND c.status = 'accepted'";

        $stmt = $this->execute($query);
        if(!$stmt){
            return [];
        } 
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all blocked contacts
     *
     * @return array
     */
    public function getBlockedContacts(): array
    {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND c.status = 'blocked'";

        $stmt = $this->execute($query);
        if(!$stmt){
            return [];
        } 
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all pending contact requests (non-user initiated) and display information
     */
    public function getPendingContactRequestsInfo(){
        $results = $this->getPendingContactRequests();
        $pending_count = count($results);
        
        // If there are pending contacts without a default fee, provide guidance
        if ($pending_count > 0) {
            echo "\n\nYou have {$pending_count} contact request(s) pending acceptance.\n";
            foreach ($results as $contact) {
                $contactAddress = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? 'Unknown';
                echo "Pending contact request from: " . $contactAddress . "\n";
                echo "To accept this contact request, use the command:\n";
                echo "eiou add " . $contactAddress . " [name] [fee percent] [credit] [currency]\n";
                echo "Example: eiou add " . $contactAddress . " Bob 0.1 100 USD\n\n";
            }
        }
    }

    /**
     * Get credit limit for a contact by public key
     *
     * @param string $senderPublicKey Sender's public key
     * @return float Credit limit (0 if not found)
     */
    public function getCreditLimit(string $senderPublicKey): float {
        $query = "SELECT credit_limit 
                    FROM {$this->tableName} 
                    WHERE pubkey_hash = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $senderPublicKey)]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['credit_limit'] ?? 0);
    }

    /**
     * Insert a new contact
     *
     * @param string $contactPublicKey Contact's public key
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    public function insertContact(
        string $contactPublicKey,
        string $name,
        float $fee,
        float $credit,
        string $currency
    ): bool {
        $data = [
            'contact_id' => $this->generateContactId(),
            'pubkey' => $contactPublicKey,
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPublicKey),
            'name' => $name,
            'status' => 'pending',
            'fee_percent' => $fee,
            'credit_limit' => $credit,
            'currency' => $currency
        ];

        $result = $this->insert($data);
        return $result !== false;
    }

    /**
     * Generate a unique random contact ID
     *
     * @return string A random 128-character alphanumeric string
     */
    private function generateContactId(): string {
        // Generate 64 bytes of random data and convert to hex (128 characters)
        return bin2hex(random_bytes(64));
    }

    /**
     * Lookup contact by name
     *
     * @param string $name Contact name (case-insensitive)
     * @return array|null Contact data or null
     */
    public function lookupByName(string $name): ?array {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND LOWER(c.name) = LOWER(:name)
                    Limit 1";

        $stmt = $this->execute($query, [':name' => $name]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact by address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupByAddress(string $transportIndex, string $address): ?array {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND a.{$transportIndex} = :address
                    LIMIT 1";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact by pubkey
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function lookupByPubkey(string $pubkey): ?array {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND pubkey = :pubkey
                    LIMIT 1";
            
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact by pubkey hash
     *
     * @param string $pubkeyHash Contact pubkey hash
     * @return array|null Contact data or null
     */
    public function lookupByPubkeyHash(string $pubkeyHash): ?array {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND c.pubkey_hash = :pubkey_hash
                    LIMIT 1";
            
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact address by name
     *
     * @param string $name Contact name (case-insensitive)
     * @return array|null Contact addresses or null
     */
    public function lookupAddressesByName(string $name): ?array {
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND LOWER(c.name) = LOWER(:name)";

        $stmt = $this->execute($query, [':name' => $name]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup specific contact address by name
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $name Contact name (case-insensitive)
     * @return string|null Contact address or null
     */
    public function lookupSpecificAddressByName(string $transportIndex, string $name): ?string {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT {$transportIndex}
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND LOWER(c.name) = LOWER(:name)";

        $stmt = $this->execute($query, [':name' => $name]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Lookup contact name by address
     *
     * @param string|null $transportIndex Address type, i.e. http, https, tor (null returns null gracefully)
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(?string $transportIndex, string $address): ?string {
        // Handle null transport index gracefully (can occur when determineTransportType returns null)
        if ($transportIndex === null || !$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT name
                    FROM {$this->tableName} c JOIN addresses a
                    ON a.pubkey_hash = c.pubkey_hash
                    AND a.{$transportIndex} = :address";

        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Retrieve all accepted contact addresses
     *
     * @return array Array of accepted addresses
     */
    public function getAllAcceptedAddresses(): array {
        $query = "SELECT * 
                    FROM addresses a JOIN {$this->tableName} c 
                    ON a.pubkey_hash = c.pubkey_hash
                    AND c.status = 'accepted'";
        
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all accepted contact addresses of singular type
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string|null $exclude Address to exclude
     * @return array Array of accepted addresses
     */
    public function getAllSingleAcceptedAddresses(string $transportIndex, ?string $exclude = null): array {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return [];
        }
        $query = "SELECT {$transportIndex}
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND status = 'accepted'";
        if ($exclude) {
            $query .= " AND {$transportIndex} != :toExclude";
            $stmt = $this->execute($query, [':toExclude' => $exclude]);
        } else {
            $stmt = $this->execute($query);
        }

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Retrieve all contact addresses and their status
     *
     * @param string|null $transportIndex Address type, i.e. http, tor
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddressesWithStatus(?string $transportIndex, ?string $exclude = null): array {
        $query = "SELECT a.*, c.status
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash";
        if ($transportIndex && $exclude) {
            if (!$this->isValidTransportIndex($transportIndex)) {
                return [];
            }
            $query .= " AND {$transportIndex} != :toExclude";
            $stmt = $this->execute($query, [':toExclude' => $exclude]);
        } else {
            $stmt = $this->execute($query);
        }

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve contact information by address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return array|null Full contact data or null
     */
    public function getContactByAddress(string $transportIndex, string $address): ?array {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    AND a.{$transportIndex} = :address
                    LIMIT 1";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Retrieve contact information by pubkey
     *
     * @param string $address Contact address
     * @return array|null Full contact data or null
     */
    public function getContactByPubkey(string $pubkey): ?array {
        return $this->findByColumn('pubkey', $pubkey);
    }

    /**
     * Retrieve contact by name or address (http/https/tor)
     *
     * Searches by exact name match first, then by http, https, or tor address.
     *
     * @param string $identifier Contact name or address
     * @return array|null Full contact data or null
     */
    public function getContactByNameOrAddress(string $identifier): ?array {
        // First try exact name match
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    WHERE c.name = :identifier
                    LIMIT 1";
        $stmt = $this->execute($query, [':identifier' => $identifier]);
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result;
            }
        }

        // Try http address
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    WHERE a.http = :identifier
                    LIMIT 1";
        $stmt = $this->execute($query, [':identifier' => $identifier]);
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result;
            }
        }

        // Try tor address
        $query = "SELECT *
                    FROM addresses a JOIN {$this->tableName} c
                    ON a.pubkey_hash = c.pubkey_hash
                    WHERE a.tor = :identifier
                    LIMIT 1";
        $stmt = $this->execute($query, [':identifier' => $identifier]);
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Retrieve contact public key by address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return string|null Contact's publice key or null
     */
    public function getContactPubkey(string $transportIndex, string $address): ?string {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT c.pubkey
                    FROM {$this->tableName} c JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND a.{$transportIndex} = :address";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Retrieve contact public key hash by address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return string|null Contact's publice key hash or null
     */
    public function getContactPubkeyHash(string $transportIndex, string $address): ?string {
        if (!$this->isValidTransportIndex($transportIndex)) {
            return null;
        }
        $query = "SELECT c.pubkey_hash
                    FROM {$this->tableName} c JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND a.{$transportIndex} = :address";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Retrieve contact public key by pubkey hash
     *
     * @param string $pubkeyHash Contact pubkey Hash
     * @return string|null Contact's publice key or null
     */
    public function getContactPubkeyFromHash(string $pubkeyHash): ?string {
        $query = "SELECT pubkey 
                    FROM {$this->tableName} 
                    WHERE pubkey_hash = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash,]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Retrieve all contacts
     *
     * @return array Array of contacts with address and pubkey
     */
    public function getAllContacts(): array {
        $query = "SELECT c.name, c.pubkey, a.* 
                    FROM {$this->tableName} c 
                    JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all contacts pubkeys
     *
     * @return array Array of contacts with only their pubkey
     */
    public function getAllContactsPubkeys(): array {
        $query = "SELECT pubkey 
                    FROM {$this->tableName}";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all contacts regardless of status
     *
     * @return array
     */
    public function getAllContactsInfo(): array
    {
        $query = "SELECT * 
                    FROM {$this->tableName} c 
                    JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash";
        $stmt = $this->execute($query);

        if(!$stmt){
            return [];
        } 

        return $stmt->fetchAll(PDO::FETCH_ASSOC);    
    }

    /**
     * Get recent contacts
     *
     * @param int $limit
     * @return array
     */
    public function getRecentContacts(int $limit = 5): array
    {
         $query = "SELECT * 
                    FROM {$this->tableName} c 
                    JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND status = 'accepted' 
                    ORDER BY created_at DESC LIMIT ?";
        $stmt = $this->execute($query,[$limit]);
        
        if(!$stmt){
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC); 
    }

    /**
     * Search contacts by name
     *
     * @param string $searchTerm
     * @return array
     */
    public function searchByName(string $searchTerm): array
    {
        $query = "SELECT *
                    FROM {$this->tableName} c
                    JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND status = 'accepted'
                    AND name LIKE ?"; 
        $stmt = $this->execute($query,['%' . $searchTerm . '%']);
        
        if(!$stmt){
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
       
    }
    
    /**
     * Search contacts by name (partial match)
     *
     * @param string|null $name Search term (null returns all)
     * @return array Array of contacts
     */
    public function searchContacts(?string $name = null): array {
        $query = "SELECT a.*, c.name, c.fee_percent, c.credit_limit, c.currency, c.status 
                    FROM {$this->tableName} c 
                    JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash"; 

        if ($name !== null) {
            $query .= " AND LOWER(name) LIKE LOWER(:name)";
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':name', '%' . $name . '%', PDO::PARAM_STR);
        } else {
            $stmt = $this->pdo->prepare($query);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to search contacts", $e);
            return [];
        }
    }

    /**
     * Update contact statusdeleteContact
     *
     * @param string $pubkey Contact pubkey
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(string $pubkey, string $status): bool {
        $affectedRows = $this->update(['status' => $status], $this->primaryKey, $pubkey);
        return $affectedRows >= 0; // >= 0 because update might not change anything
    }

    /**
     * Update and unblock a contact
     *
     * @param string $pubkey Contact pubkey
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    public function updateUnblockContact(
        string $pubkey,
        string $name,
        float $fee,
        float $credit,
        string $currency
    ): bool {
        $data = [
            'name' => $name,
            'status' => 'accepted',
            'fee_percent' => $fee,
            'credit_limit' => $credit,
            'currency' => $currency
        ];

        $affectedRows = $this->update($data, $this->primaryKey, $pubkey);
        return $affectedRows > 0;
    }

    /**
     * Update specific contact fields
     *
     * @param string $pubkey Contact pubkey
     * @param array $fields Associative array of field => value
     * @return bool Success status
     */
    public function updateContactFields(string $pubkey, array $fields): bool {
        if (empty($fields)) {
            return false;
        }

        $affectedRows = $this->update($fields, $this->primaryKey, $pubkey);
        return $affectedRows >= 0;
    }

    
}