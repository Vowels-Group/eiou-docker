<?php
/**
 * Contact Service
 *
 * Provides a wrapper around the existing ServiceContainer contact service.
 * This allows for easy integration with the new architecture while maintaining
 * compatibility with the existing /etc/eiou/src/services/ServiceContainer.php
 *
 * @package eIOUGUI\Services
 * @author Hive Mind Collective
 * @copyright 2025
 */

namespace eIOUGUI\Services;

use eIOUGUI\Core\Session;
use eIOUGUI\Repositories\ContactRepository;

class ContactService
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var ContactRepository Contact repository
     */
    private ContactRepository $repository;

    /**
     * @var object Original contact service from ServiceContainer
     */
    private $originalService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param ContactRepository $repository
     */
    public function __construct(Session $session, ContactRepository $repository)
    {
        $this->session = $session;
        $this->repository = $repository;

        // Get the original service from ServiceContainer if available
        if (class_exists('\ServiceContainer')) {
            $this->originalService = \ServiceContainer::getInstance()->getContactService();
        }
    }

    /**
     * Get all accepted contacts with formatted data
     *
     * @return array
     */
    public function getAcceptedContacts(): array
    {
        return $this->repository->getAcceptedContacts();
    }

    /**
     * Get all pending contacts (requests received)
     *
     * @return array
     */
    public function getPendingContacts(): array
    {
        return $this->repository->getPendingContacts();
    }

    /**
     * Get user pending contacts (requests sent by user)
     *
     * @return array
     */
    public function getUserPendingContacts(): array
    {
        return $this->repository->getUserPendingContacts();
    }

    /**
     * Get all blocked contacts
     *
     * @return array
     */
    public function getBlockedContacts(): array
    {
        return $this->repository->getBlockedContacts();
    }

    /**
     * Get all contacts regardless of status
     *
     * @return array
     */
    public function getAllContacts(): array
    {
        return $this->repository->getAllContacts();
    }

    /**
     * Add a new contact (delegates to original service)
     *
     * @param array $argv Command line arguments for contact service
     * @return bool
     */
    public function addContact(array $argv): bool
    {
        if ($this->originalService && method_exists($this->originalService, 'addContact')) {
            $this->originalService->addContact($argv);
            return true;
        }
        return false;
    }

    /**
     * Delete a contact (delegates to original service)
     *
     * @param string $address
     * @return bool
     */
    public function deleteContact(string $address): bool
    {
        if ($this->originalService && method_exists($this->originalService, 'deleteContact')) {
            $this->originalService->deleteContact($address);
            return true;
        }
        return false;
    }

    /**
     * Block a contact (delegates to original service)
     *
     * @param string $address
     * @return bool
     */
    public function blockContact(string $address): bool
    {
        if ($this->originalService && method_exists($this->originalService, 'blockContact')) {
            $this->originalService->blockContact($address);
            return true;
        }
        return false;
    }

    /**
     * Unblock a contact (delegates to original service)
     *
     * @param string $address
     * @return bool
     */
    public function unblockContact(string $address): bool
    {
        if ($this->originalService && method_exists($this->originalService, 'unblockContact')) {
            $this->originalService->unblockContact($address);
            return true;
        }
        return false;
    }

    /**
     * Update a contact (delegates to original service)
     *
     * @param array $argv Command line arguments for contact service
     * @return bool
     */
    public function updateContact(array $argv): bool
    {
        if ($this->originalService && method_exists($this->originalService, 'updateContact')) {
            $this->originalService->updateContact($argv);
            return true;
        }
        return false;
    }

    /**
     * Get contact by address
     *
     * @param string $address
     * @return array|null
     */
    public function getContactByAddress(string $address): ?array
    {
        return $this->repository->findByAddress($address);
    }

    /**
     * Search contacts by name
     *
     * @param string $searchTerm
     * @return array
     */
    public function searchContacts(string $searchTerm): array
    {
        return $this->repository->searchByName($searchTerm);
    }

    /**
     * Get contact statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total_contacts' => $this->repository->count(),
            'accepted_contacts' => count($this->getAcceptedContacts()),
            'pending_contacts' => count($this->getPendingContacts()),
            'blocked_contacts' => count($this->getBlockedContacts())
        ];
    }
}
