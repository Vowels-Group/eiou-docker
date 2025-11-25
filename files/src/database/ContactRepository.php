<?php
# Copyright 2025

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
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
    */
    public function getPublicKeyFromAddress(string $transportIndex, string $address){
        $query = "SELECT pubkey FROM contacts c JOIN addresses a 
                  ON c.pubkey_hash = a.pubkey_hash
                  AND a.$transportIndex = $address";
        
        $stmt = $this->pdo->prepare($query); 
        $stmt->execute();
        
        if(!$stmt){
            return null;
        }

        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Block a contact
     *
     * @param string $transportIndex Address type, i.e. http, tor
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
     * @param string $transportIndex Address type, i.e. http, tor
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
        $query ="UPDATE contacts SET status = ? WHERE $this->primaryKey = ?";
        $stmt = $this->execute($query,[$status, $pubkey]);
        if(!$stmt){
            return false;
        }
        return true;
    }

    /**
     * Check if contact is accepted
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if accepted
     */
    public function isAcceptedContact(string $pubkey): bool {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey AND status = 'accepted'";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetchColumn();
        return (int) $result ?? 0;
    }


    /**
     * Check how many contacts are accepted
     *
     * @return int amount accepted contacts
     */
    public function countAcceptedContacts(): int {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE status = 'accepted'";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetchColumn();
        return (int) $result ?? 0;
    }

    /**
     * Check if contact exists
     *
     * @param string $transportIndex Address type, i.e. http, tor
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
        $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey AND status = 'blocked'";
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
        $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey AND status != 'accepted'";
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
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE {$this->primaryKey} = :pubkey AND name IS NULL AND status = 'pending'";
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
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE {$this->primaryKey} = :pubkey AND name IS NOT NULL AND status = 'pending'";
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
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @return string status of contact
     */
    public function getContactStatus(string $transportIndex, string $address): string{
        $query = "SELECT c.status FROM addresses a JOIN {$this->tableName} c
                  ON a.pubkey_hash = c.pubkey_hash
                  AND a.{$transportIndex} = :address";

        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return '';
        }
        
        $result = $stmt->fetchColumn();
        return $result ?? null;
    }

    /**
     * Get all pending contact requests (non-user initiated)
     *
     * @return array Array of pending contacts
     */
    public function getPendingContactRequests(): array {
        $query = "SELECT * FROM {$this->tableName} WHERE name IS NULL AND status = 'pending'";
        $stmt = $this->execute($query);

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
        $query = "SELECT * FROM {$this->tableName} WHERE name IS NOT NULL AND status = 'pending'";
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
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
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
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
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
                echo "Pending contact request from: " . $contact['http'] ?? $contact['tor'] . "\n";
                echo "To accept this contact request, use the command:\n";
                echo "eiou add " . $contact['http'] ?? $contact['tor'] . " [name] [fee percent] [credit] [currency]\n";
                echo "Example: eiou add " . $contact['http'] ?? $contact['tor'] . " Bob 0.1 100 USD\n\n";
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
        $query = "SELECT credit_limit FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash";
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
     * Lookup contact by name
     *
     * @param string $name Contact name (case-insensitive)
     * @return array|null Contact data or null
     */
    public function lookupByName(string $name): ?array {
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
                  ON a.pubkey_hash = c.pubkey_hash
                  AND LOWER(c.name) = LOWER(:name)";

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
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupByAddress(string $address): ?array {
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
                ON a.pubkey_hash = c.pubkey_hash";

        $query .= " AND http = :http OR tor = :tor";
        $stmt = $this->execute($query, [':http' => $address,':tor' => $address]);

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
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
                ON a.pubkey_hash = c.pubkey_hash
                AND pubkey = :pubkey";
            
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

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
     * @return string|null Contact address or null
     */
    public function lookupAddressesByName(string $name): ?string {
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c
                  ON a.pubkey_hash = c.pubkey_hash
                  AND LOWER(c.name) = LOWER(:name)";
        
        $stmt = $this->execute($query, [':name' => $name]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup specific contact address by name
     *
     * @param string $name Contact name (case-insensitive)
     * @return string|null Contact address or null
     */
    public function lookupSpecificAddressByName(string $name, string $transportIndex): ?string {
        $query = "SELECT {$transportIndex} FROM addresses a JOIN {$this->tableName} c
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
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(string $address): ?string {
        $query = "SELECT name FROM {$this->tableName} c JOIN addresses a
                  ON a.pubkey_hash = c.pubkey_hash
                  AND (http = :http OR tor = :tor)";

        $stmt = $this->execute($query, [':http' => $address,':tor' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Retrieve all accepted contact addresses
     *
     * @param string|null $exclude Address to exclude
     * @return array Array of accepted addresses
     */
    public function getAllAcceptedAddresses(?string $exclude = null): array {
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c 
                    ON a.pubkey_hash = c.pubkey_hash
                    AND c.status = 'accepted'";
        if ($exclude) {
            $query .= " AND a.http != :http OR a.tor != :tor";
            $stmt = $this->execute($query, [':http' => $exclude,':tor' => $exclude]);
        } else {
            $stmt = $this->execute($query);
        }

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all accepted contact addresses of singular type
     *
     * @param string $addressType Addresstype (tor/http)
     * @param string|null $exclude Address to exclude
     * @return array Array of accepted addresses
     */
    public function getAllSingleAcceptedAddresses(string $addressType, ?string $exclude = null): array {
        $query = "SELECT {$addressType} FROM addresses a JOIN {$this->tableName} c 
                    ON a.pubkey_hash = c.pubkey_hash
                    AND status = 'accepted'";
        if ($exclude) {
            // Get specific address
            $query .= " AND {$addressType} != :toExclude";
            $stmt = $this->execute($query, [':toExclude' => $exclude]);
        } else{
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
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddressesWithStatus(?string $exclude = null): array {
        $query = "SELECT a.*, c.status FROM addresses a JOIN {$this->tableName} c 
                    ON a.pubkey_hash = c.pubkey_hash";
        if ($exclude) {
            $query .= " AND http != :http OR tor != :tor";
            $stmt = $this->execute($query, [':http' => $exclude,':tor' => $exclude]);
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
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @return array|null Full contact data or null
     */
    public function getContactByAddress(string $transportIndex, string $address): ?array {
        $query = "SELECT * FROM addresses a JOIN {$this->tableName} c  
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
     * Retrieve contact public key by address
     *
     * @param string $address Contact address
     * @return string|null Contact's publice key or null
     */
    public function getContactPubkey(string $address): ?string {

        $query = "SELECT c.pubkey FROM {$this->tableName} c JOIN addresses a
                    ON c.pubkey_hash = a.pubkey_hash
                    AND  a.http = :http OR a.tor = :tor";
        $stmt = $this->execute($query, [':http' => $address,':tor' => $address]);

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

        $query = "SELECT pubkey FROM {$this->tableName} 
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
        $query = "SELECT pubkey FROM {$this->tableName}";
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
                    name LIKE ?"; 
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
        $query = "SELECT a.*, c.name, c.fee_percent, c.credit_limit, c.currency 
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

        // TO DO
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

    /**
     * Update specific contact fields through CLI interaction
     *
     * @param array $argv Command line arguments
     */
    function updateContact($argv): void {
        // Update contact information

    // TO DO
        $address = isset($argv[2]) ? $argv[2] : null;
        $field = isset($argv[3]) ? strtolower($argv[3]) : null;
        $value = isset($argv[4]) ? $argv[4] : null;
        $value2 = isset($argv[5]) ? $argv[5] : null;
        $value3 = isset($argv[6]) ? $argv[6] : null;

        // Check if all fields are valid and contact exists before proceeding
        if(!$address || ($address && !$this->lookupByAddress($address))){
            // If no address supplied or no contact exists with supplied address
            if(!$address){
                output(outputNoSuppliedAddress());
            } else{
                output(outputAdressContactIssue($address));
            }
        } elseif (!in_array($field,['name','fee','credit','all'])){
            // If no proper field update parameter
            output(returnContactUpdateInvalidInput());
        }elseif( !$value || ($field === 'all' && (!$value2 || !$value3)) ){
            // Check if enough parameters are given to update
            output(returnContactUpdateInvalidInputParameters());
        } else{
            $query = "UPDATE contacts SET ";
            $params = []; 
            // Depending on supplied argument update specific (or all) items
            if($field === 'name'){
                $query .= "name = :name";
                $params[':name'] = $value;
            }
            elseif($field === 'fee'){
                $query .= "fee_percent = :fee";
                $params[':fee'] = $value * Constants::FEE_CONVERSION_FACTOR; // Convert percentage
            }
            elseif($field === 'credit'){
                $query .= "credit_limit = :credit, currency = :currency";
                $params[':credit'] = $value * Constants::CREDIT_CONVERSION_FACTOR; // Convert to cents
                $params[':currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY;
            }
            elseif($field === 'all'){
                $query .= "name = :name, fee_percent = :fee, credit_limit = :credit, currency = :currency";
                $params[':name'] = $value;
                $params[':fee'] = $value2 * Constants::FEE_CONVERSION_FACTOR; // Convert percentage
                $params[':credit'] = $value3 * Constants::CREDIT_CONVERSION_FACTOR; // Convert to cents
                $params[':currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY;
            }

            $query .= " WHERE pubkey = :pubkey";
            $params[':pubkey'] = $this->getContactPubkey($address);
            output("paramts:" .print_r($params,true),'SILENT');
           
            if ($this->execute($query,$params)) {
                // If succesful update, respond of success
                output(returnContactUpdate());
            } else{
                // If unsuccesful update with correct parameters, implies not an existing contact, respond of this fact
                output(returnContactNotFound());
            }
        }  
    }
}
