<?php

require_once __DIR__ . '/BasePayload.php';

/**
 * Contact payload builder
 *
 * Copyright 2025
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
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($data['address']);
        return [
            'type' => 'create',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build a contact creation request payload
     *
     * @param string $address The address of the contact request
     * @return array The contact creation payload
     */
    public function buildCreateRequest($address): array
    {
        return $this->build(['address' => $address]);
    }

    /**
     * Build a contact accepted payload
     *
     * @param string $address The address to send the acceptance to
     * @return array The contact accepted payload
     */
    public function buildAccepted(string $address): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return [
            'status' => 'accepted',
            'message' => $myAddress . ' confirms that we are contacts',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build a contact updated payload
     *
     * @param string $address The address to send the acceptance to
     * @return string JSON-encoded contact updated payload
     */
    public function buildUpdated(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'status' => 'updated',
            'message' => $myAddress . ' confirms that contact address has been updated/added',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a contact already exists warning payload
     *
     * @param string $address The address of the contact request
     * @return string JSON-encoded contact already exists payload
     */
    public function buildAlreadyExists(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'status' => 'warning',
            'message' => 'Contact already exists',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a contact rejection payload
     *
     * @param string $address The address of the contact request
     * @param string $reason The reason for rejection
     * @return string JSON-encoded contact rejection payload
     */
    public function buildRejection(string $address, string $reason = 'Contact request rejected'): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'status' => 'rejected',
            'message' => $reason,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a contact pending payload
     *
     * @param string $address The address that is pending
     * @return array The pending payload
     */
    public function buildPending(string $address): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return [
            'status' => 'pending',
            'message' => "Contact request to {$address} is pending",
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }
}