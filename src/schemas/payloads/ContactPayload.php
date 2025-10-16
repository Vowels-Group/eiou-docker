<?php

declare(strict_types=1);

namespace EIOU\Schemas\Payloads;

/**
 * Contact payload builder
 *
 * Handles building payloads for contact-related operations including
 * contact creation, acceptance, and status messages.
 */
class ContactPayload extends BasePayload
{
    /**
     * Build a contact creation payload
     *
     * @param array $data Input data (optional, can be empty for contact creation)
     * @return array The contact creation payload
     */
    public function build(array $data = []): array
    {
        $user = $this->userContext->getUser();

        return [
            'type' => 'create',
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
        ];
    }

    /**
     * Build a contact creation request payload
     *
     * @return array The contact creation payload
     */
    public function buildCreateRequest(): array
    {
        return $this->build([]);
    }

    /**
     * Build a contact accepted payload
     *
     * @param string $address The address to send the acceptance to
     * @return array The contact accepted payload
     */
    public function buildAccepted(string $address): array
    {
        $user = $this->userContext->getUser();
        $myAddress = $this->resolveUserAddressForTransport($address);

        return [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => 'accepted',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'message' => $myAddress . ' confirms that we are contacts',
        ];
    }

    /**
     * Build a contact already exists warning payload
     *
     * @return array The warning payload
     */
    public function buildAlreadyExists(): array
    {
        $user = $this->userContext->getUser();

        return [
            'status' => 'warning',
            'message' => 'Contact already exists',
            'myPublicKey' => $user ? $user->getPublicKey() : null,
        ];
    }

    /**
     * Build a contact rejection payload
     *
     * @param string $reason The reason for rejection
     * @return array The rejection payload
     */
    public function buildRejection(string $reason = 'Contact request rejected'): array
    {
        $user = $this->userContext->getUser();

        return [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => 'rejected',
            'senderAddress' => $this->getUserAddress(),
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'message' => $reason,
        ];
    }

    /**
     * Build a contact pending payload
     *
     * @param string $address The address that is pending
     * @return array The pending payload
     */
    public function buildPending(string $address): array
    {
        return [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => 'pending',
            'senderAddress' => $this->getUserAddress(),
            'message' => "Contact request to {$address} is pending",
        ];
    }

    /**
     * Resolve user address for transport
     *
     * This method replicates the functionality of the global resolveUserAddressForTransport function
     * TODO: This should be moved to a service or utility class
     *
     * @param string $address The address to resolve
     * @return string The resolved address
     */
    private function resolveUserAddressForTransport(string $address): string
    {
        // For now, we'll return the user's address if available, otherwise the input address
        // The actual implementation would need to be moved from the global function
        $userAddress = $this->getUserAddress();
        return $userAddress ?? $address;
    }
}