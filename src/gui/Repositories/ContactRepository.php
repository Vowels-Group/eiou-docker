<?php
/**
 * Contact Repository
 *
 * Handles data access for contacts. Provides database abstraction for all contact-related queries.
 * Extracted from functions.php for clean separation of concerns.
 *
 * @package eIOUGUI\Repositories
 * @author Hive Mind Collective
 * @copyright 2025
 */

namespace eIOUGUI\Repositories;

use PDO;
use Exception;

class ContactRepository
{
    /**
     * @var PDO|null Database connection
     */
    private ?PDO $pdo = null;

    /**
     * Get PDO connection (lazy initialization)
     *
     * @return PDO|null
     */
    private function getPDOConnection(): ?PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = $this->createPDOConnection();
            } catch (Exception $e) {
                error_log("Database connection failed: " . $e->getMessage());
                return null;
            }
        }
        return $this->pdo;
    }

    /**
     * Create PDO connection
     *
     * @return PDO
     * @throws Exception
     */
    private function createPDOConnection(): PDO
    {
        // This should be implemented based on your database configuration
        // For now, we'll include the global getPDOConnection function
        global $pdo;
        if ($pdo !== null) {
            return $pdo;
        }

        // If no global connection exists, call the global function
        if (function_exists('getPDOConnection')) {
            $connection = getPDOConnection();
            if ($connection !== null) {
                return $connection;
            }
        }

        throw new Exception("Database connection not available");
    }

    /**
     * Get all accepted contacts
     *
     * @return array
     */
    public function getAcceptedContacts(): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'accepted'");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting accepted contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all pending contacts (requests received)
     *
     * @return array
     */
    public function getPendingContacts(): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            // Get all pending contact requests (where name IS NULL and status = 'pending')
            $stmt = $pdo->prepare("SELECT address, pubkey, status FROM contacts WHERE name IS NULL AND status = 'pending'");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting pending contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user pending contacts (requests sent by user)
     *
     * @return array
     */
    public function getUserPendingContacts(): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            // Get all pending contact requests (where name IS NOT NULL and status = 'pending')
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE name IS NOT NULL AND status = 'pending'");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting user pending contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all blocked contacts
     *
     * @return array
     */
    public function getBlockedContacts(): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'blocked'");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting blocked contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all contacts regardless of status
     *
     * @return array
     */
    public function getAllContacts(): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting all contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get contact name by address
     *
     * @param string $address
     * @return string|null
     */
    public function getContactNameByAddress(string $address): ?string
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT name FROM contacts WHERE address = ?");
            $stmt->execute([$address]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['name'] : null;
        } catch (Exception $e) {
            error_log("Error getting contact name: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find contact by address
     *
     * @param string $address
     * @return array|null
     */
    public function findByAddress(string $address): ?array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE address = ?");
            $stmt->execute([$address]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (Exception $e) {
            error_log("Error finding contact by address: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if contact exists by address
     *
     * @param string $address
     * @return bool
     */
    public function contactExists(string $address): bool
    {
        return $this->findByAddress($address) !== null;
    }

    /**
     * Check for new contact requests since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewContactRequests(int $lastCheckTime): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            $query = "SELECT COUNT(*) as count FROM contacts
                      WHERE name IS NULL AND status = 'pending'
                      AND created_at > ?";

            $stmt = $pdo->prepare($query);
            $stmt->execute([date('Y-m-d H:i:s', $lastCheckTime)]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Error checking for new contact requests: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Count total contacts
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->getAllContacts());
    }

    /**
     * Save a new contact
     *
     * @param array $contact_data
     * @return bool
     */
    public function saveContact(array $contact_data): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            // This is a placeholder - actual implementation depends on your database schema
            // You may need to adjust the fields based on your contacts table structure
            $stmt = $pdo->prepare("INSERT INTO contacts (address, name, pubkey, status, created_at) VALUES (?, ?, ?, ?, ?)");
            return $stmt->execute([
                $contact_data['address'] ?? '',
                $contact_data['name'] ?? '',
                $contact_data['pubkey'] ?? '',
                $contact_data['status'] ?? 'pending',
                $contact_data['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Error saving contact: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a contact
     *
     * @param string $address
     * @return bool
     */
    public function deleteContact(string $address): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM contacts WHERE address = ?");
            return $stmt->execute([$address]);
        } catch (Exception $e) {
            error_log("Error deleting contact: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update contact status
     *
     * @param string $address
     * @param string $status
     * @return bool
     */
    public function updateContactStatus(string $address, string $status): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("UPDATE contacts SET status = ? WHERE address = ?");
            return $stmt->execute([$status, $address]);
        } catch (Exception $e) {
            error_log("Error updating contact status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Search contacts by name
     *
     * @param string $searchTerm
     * @return array
     */
    public function searchByName(string $searchTerm): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE name LIKE ? AND status = 'accepted'");
            $stmt->execute(['%' . $searchTerm . '%']);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error searching contacts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent contacts
     *
     * @param int $limit
     * @return array
     */
    public function getRecentContacts(int $limit = 5): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'accepted' ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting recent contacts: " . $e->getMessage());
            return [];
        }
    }
}
