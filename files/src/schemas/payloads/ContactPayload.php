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
     * Build a contact request received payload
     *
     * @param string $address The address to send the acceptance to
     * @param array|null $knownAddresses All known addresses for the sender (http, tor, etc.)
     * @return string JSON-encoded contact received payload
     */
    public function buildReceived(string $address, ?array $knownAddresses = null): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        $payload = [
            'status' => 'received',
            'message' => $myAddress . ' confirms that the contact request has been received',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include all known addresses if available
        if ($knownAddresses !== null) {
            $payload['senderAddresses'] = $this->filterAddresses($knownAddresses);
        }

        return json_encode($payload);
    }

    /**
     * Build a contact updated payload
     *
     * @param string $address The address to send the acceptance to
     * @param array|null $knownAddresses All known addresses for the sender (http, tor, etc.)
     * @return string JSON-encoded contact updated payload
     */
    public function buildUpdated(string $address, ?array $knownAddresses = null): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        $payload = [
            'status' => 'updated',
            'message' => $myAddress . ' confirms that contact address has been updated/added',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include all known addresses if available
        if ($knownAddresses !== null) {
            $payload['senderAddresses'] = $this->filterAddresses($knownAddresses);
        }

        return json_encode($payload);
    }

    /**
     * Build a contact already exists warning payload
     *
     * @param string $address The address of the contact request
     * @param array|null $knownAddresses All known addresses for the sender (http, tor, etc.)
     * @return string JSON-encoded contact already exists payload
     */
    public function buildAlreadyExists(string $address, ?array $knownAddresses = null): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        $payload = [
            'status' => 'warning',
            'message' => 'Contact already exists',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include all known addresses if available
        if ($knownAddresses !== null) {
            $payload['senderAddresses'] = $this->filterAddresses($knownAddresses);
        }

        return json_encode($payload);
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

    /**
     * Filter addresses array to return only transport addresses with values
     *
     * Removes pubkey_hash and any empty/null address fields.
     * Returns associative array: http => address, tor => address
     *
     * @param array $addresses Raw addresses array from database
     * @return array Filtered addresses with only non-empty transport addresses
     */
    protected function filterAddresses(array $addresses): array
    {
        $filtered = [];
        $transportKeys = $this->transportUtility->getAllAddressTypes();

        foreach ($transportKeys as $key) {
            if (isset($addresses[$key]) && !empty($addresses[$key])) {
                $filtered[$key] = $addresses[$key];
            }
        }

        return $filtered;
    }
}