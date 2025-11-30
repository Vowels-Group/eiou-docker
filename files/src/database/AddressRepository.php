<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Address Repository
 *
 * Manages all database interactions for the contacts table.
 *
 * @package Database\Repository
 */
class AddressRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'addresses';
        $this->primaryKey = 'pubkey_hash';
    }

    /**
     * Get all possible address types from table
     *
     * @return array|null Address Types
     */
    public function getAllAddressTypes(){
        $columns = $this->getColumnNames();
        if($columns){
            // Remove unneeded column names
            $columns = array_values(array_diff($columns, ['id','pubkey_hash']));
        }
        return $columns;
    }

    /**
     * Insert a new address
     *
     * @param string $contactPublicKey Contact's public key
     * @param array $addresses Associative array of Contact address(es) ['address_type' => address]
     * @return bool Success status
     */
    public function insertAddress(string $contactPublicKey, array $addresses): bool {

        $data = array_merge($addresses,[
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPublicKey)
        ]);

        $result = $this->insert($data);
        return $result !== false;
    }

    /**
     * Update specific contact adress fields
     *
     * @param string $pubkeyHash Contact pubkey hash
     * @param array $fields Associative array of field => value
     * @return bool Success status
     */
    public function updateContactFields(string $pubkeyHash, array $fields): bool {
        if (empty($fields)) {
            return false;
        }

        $affectedRows = $this->update($fields, $this->primaryKey, $pubkeyHash);
        return $affectedRows >= 0;
    }

    /**
     * Lookup contact addresses by pubkey hash
     *
     * @param string $pubkeyHash Contact pubkey
     * @return array Contact data (including pubkey_hash) or null
     */
    public function lookupByPubkeyHash(string $pubkeyHash): array {
        $query = "SELECT * FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: [];
    }

    /**
     * Delete contact addresses by pubkey 
     *
     * @param string $pubkeyHash Contact pubkey
     * @return bool True or false
     */
    public function deleteByPubkey(string $pubkey): bool {
        $deletedRows = $this->delete($this->primaryKey, hash(Constants::HASH_ALGORITHM, $pubkey));
        return $deletedRows > 0;
    }

    /**
     * Delete contact addresses by pubkey hash
     *
     * @param string $pubkeyHash Contact pubkey hash
     * @return bool True or false
     */
    public function deleteByPubkeyHash(string $pubkeyHash): bool {
        $deletedRows = $this->delete($this->primaryKey, $pubkeyHash);
        return $deletedRows > 0;
    }

    /**
     * Retrieve all contact addresses
     *
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddresses(?string $transportIndex = null, ?string $exclude = null): array {
        $query = "SELECT * FROM {$this->tableName}";
        if ($transportIndex && $exclude) { 
            $query .= "  WHERE {$transportIndex} = :toExclude";
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
     * Retrieve contact public key hash by address
     *
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @return string|null Contact's publice key hash or null
     */
    public function getContactPubkeyHash(string $transportIndex, string $address): ?string {
        $query = "SELECT {$this->primaryKey} FROM {$this->tableName}
                    WHERE {$transportIndex} = :address";

        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Get all address type column names from the addresses table
     * Excludes 'id' and 'pubkey_hash' columns
     *
     * @return array Array of address type names (e.g., ['http', 'tor'])
     */
    public function getAllAddressTypes(): array {
        $query = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME NOT IN ('id', 'pubkey_hash')
                  ORDER BY ORDINAL_POSITION";

        $stmt = $this->execute($query, [':table_name' => $this->tableName]);

        if (!$stmt) {
            // Fallback to known address types if query fails
            return ['http', 'tor'];
        }

        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $result ?: ['http', 'tor'];
    }
}