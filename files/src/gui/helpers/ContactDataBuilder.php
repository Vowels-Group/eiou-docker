<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Helpers;

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
        }

        // Set primary address, preferring security order: Tor > HTTPS > HTTP
        if (!empty($contact['tor'])) {
            $primaryAddress = $contact['tor'];
        } elseif (!empty($contact['https'])) {
            $primaryAddress = $contact['https'];
        } elseif (!empty($contact['http'])) {
            $primaryAddress = $contact['http'];
        } else {
            // Fall back to first available address type
            foreach ($this->addressTypes as $type) {
                if (!empty($contact[$type])) {
                    $primaryAddress = $contact[$type];
                    break;
                }
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
            'transactions' => $contact['transactions'] ?? [],
            'online_status' => $contact['online_status'] ?? 'unknown',
            'valid_chain' => $contact['valid_chain'] ?? null
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
        // Use JSON_HEX_* flags to encode special characters as Unicode escapes
        // This prevents any issues with HTML/JS parsing when the JSON is embedded
        // in onclick attributes or other JavaScript contexts
        return htmlspecialchars(
            json_encode(
                $this->buildContactData($contact, $status),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
