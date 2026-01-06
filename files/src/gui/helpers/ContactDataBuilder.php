<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * ContactDataBuilder - Builds standardized contact data arrays for the GUI
 *
 * This helper class dynamically builds contact data structures that include
 * all address types configured in the switch, ensuring the GUI adapts to
 * whatever address types are available (http, tor, i2p, etc.)
 */
class ContactDataBuilder
{
    /** @var array */
    private $addressTypes;

    /**
     * Constructor
     *
     * @param array $addressTypes Array of address type names from the switch config
     */
    public function __construct(array $addressTypes)
    {
        $this->addressTypes = $addressTypes;
    }

    /**
     * Build a standardized contact data array
     *
     * @param array $contact Raw contact data from the database
     * @param string $status Contact status ('accepted', 'pending', 'blocked')
     * @return array Standardized contact data with all address types
     */
    public function buildContactData(array $contact, string $status): array
    {
        $addresses = [];
        $primaryAddress = null;

        // Dynamically build addresses array based on configured types
        foreach ($this->addressTypes as $type) {
            $addresses[$type] = $contact[$type] ?? '';
            if ($primaryAddress === null && !empty($contact[$type])) {
                $primaryAddress = $contact[$type];
            }
        }

        return array_merge([
            'address' => $primaryAddress ?? '',
            'name' => $contact['name'] ?? ($status === 'blocked' ? '-' : ''),
            'fee' => $contact['fee'] ?? 0,
            'credit_limit' => $contact['credit_limit'] ?? 0,
            'currency' => $contact['currency'] ?? 'USD',
            'status' => $status,
            'pubkey' => $contact['pubkey'] ?? '',
            'balance' => $contact['balance'] ?? 0,
            'contact_id' => $contact['contact_id'] ?? '',
            'transactions' => $contact['transactions'] ?? []
        ], $addresses);
    }

    /**
     * Build a JSON-encoded, HTML-safe contact data string for use in onclick attributes
     *
     * @param array $contact Raw contact data from the database
     * @param string $status Contact status ('accepted', 'pending', 'blocked')
     * @return string HTML-safe JSON-encoded contact data
     */
    public function buildEncodedContactData(array $contact, string $status): string
    {
        return htmlspecialchars(json_encode($this->buildContactData($contact, $status)), ENT_QUOTES, 'UTF-8');
    }
}
