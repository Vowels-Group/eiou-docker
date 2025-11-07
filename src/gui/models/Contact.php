<?php
/**
 * Contact Model
 *
 * Represents contact data and provides contact-related operations
 *
 * Copyright 2025
 */

namespace Eiou\Gui\Models;

require_once __DIR__ . '/../../services/ServiceContainer.php';

use ServiceContainer;

/**
 * Contact Model
 *
 * Handles contact operations and data management
 */
class Contact
{
    /**
     * @var ServiceContainer Service container for accessing services
     */
    private ServiceContainer $serviceContainer;

    /**
     * @var array Contact data cache
     */
    private array $contacts = [];

    /**
     * Constructor
     *
     * @param ServiceContainer $serviceContainer Service container
     */
    public function __construct(ServiceContainer $serviceContainer)
    {
        $this->serviceContainer = $serviceContainer;
    }

    /**
     * Get all contacts
     *
     * @param bool $includeBlocked Include blocked contacts
     * @return array List of contacts
     */
    public function getAllContacts(bool $includeBlocked = false): array
    {
        $cacheKey = $includeBlocked ? 'all_with_blocked' : 'all_active';

        if (isset($this->contacts[$cacheKey])) {
            return $this->contacts[$cacheKey];
        }

        $contactService = $this->serviceContainer->getContactService();
        $contacts = $contactService->getAllContacts();

        if (!$includeBlocked) {
            $contacts = array_filter($contacts, function ($contact) {
                return !($contact['blocked'] ?? false);
            });
        }

        $this->contacts[$cacheKey] = $contacts;
        return $contacts;
    }

    /**
     * Get contact by address
     *
     * @param string $address Contact address
     * @return array|null Contact data or null if not found
     */
    public function getContact(string $address): ?array
    {
        $contactService = $this->serviceContainer->getContactService();
        return $contactService->getContact($address);
    }

    /**
     * Get contact by name
     *
     * @param string $name Contact name
     * @return array|null Contact data or null if not found
     */
    public function getContactByName(string $name): ?array
    {
        $contactService = $this->serviceContainer->getContactService();
        $contacts = $this->getAllContacts();

        foreach ($contacts as $contact) {
            if (strcasecmp($contact['name'] ?? '', $name) === 0) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Check if contact exists
     *
     * @param string $address Contact address
     * @return bool True if contact exists
     */
    public function exists(string $address): bool
    {
        return $this->getContact($address) !== null;
    }

    /**
     * Add new contact
     *
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool True on success
     */
    public function add(
        string $address,
        string $name,
        float $fee,
        float $credit,
        string $currency
    ): bool {
        $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];
        $contactService = $this->serviceContainer->getContactService();

        ob_start();
        try {
            $contactService->addContact($argv);
            ob_end_clean();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            ob_end_clean();
            return false;
        }
    }

    /**
     * Update contact
     *
     * @param string $address Contact address
     * @param string $name New name
     * @param float $fee New fee
     * @param float $credit New credit limit
     * @return bool True on success
     */
    public function update(
        string $address,
        string $name,
        float $fee,
        float $credit
    ): bool {
        $argv = ['eiou', 'update', $address, 'all', $name, $fee, $credit];
        $contactService = $this->serviceContainer->getContactService();

        ob_start();
        try {
            $contactService->updateContact($argv);
            ob_end_clean();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            ob_end_clean();
            return false;
        }
    }

    /**
     * Delete contact
     *
     * @param string $address Contact address
     * @return bool True on success
     */
    public function delete(string $address): bool
    {
        $contactService = $this->serviceContainer->getContactService();

        ob_start();
        try {
            $contactService->deleteContact($address);
            ob_end_clean();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            ob_end_clean();
            return false;
        }
    }

    /**
     * Block contact
     *
     * @param string $address Contact address
     * @return bool True on success
     */
    public function block(string $address): bool
    {
        $contactService = $this->serviceContainer->getContactService();

        ob_start();
        try {
            $contactService->blockContact($address);
            ob_end_clean();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            ob_end_clean();
            return false;
        }
    }

    /**
     * Unblock contact
     *
     * @param string $address Contact address
     * @return bool True on success
     */
    public function unblock(string $address): bool
    {
        $contactService = $this->serviceContainer->getContactService();

        ob_start();
        try {
            $contactService->unblockContact($address);
            ob_end_clean();
            $this->clearCache();
            return true;
        } catch (\Exception $e) {
            ob_end_clean();
            return false;
        }
    }

    /**
     * Get pending contact requests
     *
     * @return array List of pending contact requests
     */
    public function getPendingRequests(): array
    {
        $contactService = $this->serviceContainer->getContactService();
        $allContacts = $this->getAllContacts(true);

        return array_filter($allContacts, function ($contact) {
            return ($contact['status'] ?? '') === 'pending';
        });
    }

    /**
     * Get blocked contacts
     *
     * @return array List of blocked contacts
     */
    public function getBlockedContacts(): array
    {
        $contactService = $this->serviceContainer->getContactService();
        $allContacts = $this->getAllContacts(true);

        return array_filter($allContacts, function ($contact) {
            return ($contact['blocked'] ?? false) === true;
        });
    }

    /**
     * Get active contacts (not blocked, confirmed)
     *
     * @return array List of active contacts
     */
    public function getActiveContacts(): array
    {
        return $this->getAllContacts(false);
    }

    /**
     * Get contacts sorted by name
     *
     * @param bool $ascending Sort ascending (default) or descending
     * @return array Sorted contacts
     */
    public function getContactsSortedByName(bool $ascending = true): array
    {
        $contacts = $this->getAllContacts();

        usort($contacts, function ($a, $b) use ($ascending) {
            $nameA = $a['name'] ?? '';
            $nameB = $b['name'] ?? '';
            $result = strcasecmp($nameA, $nameB);
            return $ascending ? $result : -$result;
        });

        return $contacts;
    }

    /**
     * Search contacts by name or address
     *
     * @param string $query Search query
     * @return array Matching contacts
     */
    public function search(string $query): array
    {
        $contacts = $this->getAllContacts();
        $query = strtolower($query);

        return array_filter($contacts, function ($contact) use ($query) {
            $name = strtolower($contact['name'] ?? '');
            $address = strtolower($contact['address'] ?? '');

            return str_contains($name, $query) || str_contains($address, $query);
        });
    }

    /**
     * Clear contact cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->contacts = [];
    }

    /**
     * Get contact count
     *
     * @param bool $includeBlocked Include blocked contacts in count
     * @return int Number of contacts
     */
    public function getCount(bool $includeBlocked = false): int
    {
        return count($this->getAllContacts($includeBlocked));
    }

    /**
     * Validate contact data
     *
     * @param array $data Contact data to validate
     * @return array Validation result with 'valid' and 'errors' keys
     */
    public function validate(array $data): array
    {
        require_once __DIR__ . '/../../utils/InputValidator.php';

        $errors = [];

        // Validate address
        if (isset($data['address'])) {
            $result = \InputValidator::validateAddress($data['address']);
            if (!$result['valid']) {
                $errors['address'] = $result['error'];
            }
        } else {
            $errors['address'] = 'Address is required';
        }

        // Validate name
        if (isset($data['name'])) {
            $result = \InputValidator::validateContactName($data['name']);
            if (!$result['valid']) {
                $errors['name'] = $result['error'];
            }
        } else {
            $errors['name'] = 'Name is required';
        }

        // Validate fee
        if (isset($data['fee'])) {
            $result = \InputValidator::validateFeePercent($data['fee']);
            if (!$result['valid']) {
                $errors['fee'] = $result['error'];
            }
        } else {
            $errors['fee'] = 'Fee is required';
        }

        // Validate credit
        if (isset($data['credit'])) {
            $result = \InputValidator::validateCreditLimit($data['credit']);
            if (!$result['valid']) {
                $errors['credit'] = $result['error'];
            }
        } else {
            $errors['credit'] = 'Credit is required';
        }

        // Validate currency
        if (isset($data['currency'])) {
            $result = \InputValidator::validateCurrency($data['currency']);
            if (!$result['valid']) {
                $errors['currency'] = $result['error'];
            }
        } else {
            $errors['currency'] = 'Currency is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
