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
     * Insert a new address
     *
     * @param string $contactPublicKey Contact's public key
     * @param array $addresses Associative array of Contact address(es) ['address_type' => address]
     * @return bool Success status
     */
    public function insertAddress(string $contactPublicKey, array $addresses): bool {

        $data = array_merge($addresses,[
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPublicKey),
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

        $affectedRows = $this->update($fields, 'pubkey_hash', $pubkeyHash);
        return $affectedRows >= 0;
    }

    /**
     * Lookup contact addresses by pubkey hash
     *
     * @param string $pubkeyHash Contact pubkey
     * @return array|null Contact data or null
     */
    public function lookupByPubkey(string $pubkeyHash): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE pubkey = :pubkey";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }


    /**
     * Retrieve contact public key hash by address
     *
     * @param string $address Contact address
     * @return string|null Contact's publice key hash or null
     */
    public function getContactPubkeyHash(string $address): ?string {
        $query = "SELECT pubkey_hash FROM {$this->tableName}";
        $query .= " WHERE http = :http OR tor = :tor";
        $stmt = $this->execute($query, [':http' => $address,':tor' => $address]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }


}