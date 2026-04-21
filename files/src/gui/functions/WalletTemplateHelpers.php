<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Post-authentication template helpers — for wallet.html and its sub-
 * templates only.
 *
 * This file is the post-auth counterpart to TemplateHelpers.php. It's
 * loaded by Functions.php (which only runs on authenticated requests),
 * so helpers here are free to depend on `Application::getInstance()` /
 * the service container / repository factory — things that aren't
 * guaranteed to be initialised on the unauthenticated login page.
 *
 * The split is deliberate: pure Constants wrappers and date-format
 * helpers stay in TemplateHelpers.php so they're callable from the
 * login page (authenticationForm.html) and other pre-auth sub-
 * templates; anything that reaches into service/DB state lives here.
 *
 * Depends on: the Composer autoloader (loaded via /app/eiou/Functions.php)
 * and a post-auth `Application::getInstance()` context.
 */

/**
 * Get the address-schema types discovered from INFORMATION_SCHEMA, ordered
 * by the canonical security priority (`Constants::VALID_TRANSPORT_INDICES`
 * — Tor > HTTPS > HTTP). Types present in the schema but absent from the
 * priority list (i.e. future transports) are appended in schema-column
 * order so they still get rendered, just after the known ones.
 *
 * Request-scoped function-static cache — `getAllAddressTypes()` hits
 * INFORMATION_SCHEMA each call, and templates iterate this list once per
 * contact row + once per pending-contact modal, so memoizing avoids an
 * O(N) query pattern on larger wallets.
 *
 * @return string[] Address-type column names in display-priority order
 */
function getOrderedAddressSchemaTypes(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $schemaTypes = \Eiou\Core\Application::getInstance()->services
        ->getRepositoryFactory()
        ->get(\Eiou\Database\AddressRepository::class)
        ->getAllAddressTypes();
    $priority = \Eiou\Core\Constants::VALID_TRANSPORT_INDICES;
    $known = array_values(array_intersect($priority, $schemaTypes));
    $unknown = array_values(array_diff($schemaTypes, $priority));
    $cached = array_merge($known, $unknown);
    return $cached;
}

/**
 * Build the flattened, lowercased, space-joined search string for a
 * contact row's `data-contact-address` attribute. Iterates whatever
 * transport columns the `addresses` schema currently exposes so adding
 * a new transport doesn't silently leave it out of the search index.
 * Ordering doesn't matter for substring search, but reusing
 * `getOrderedAddressSchemaTypes` keeps us on one cached list.
 *
 * @param array $contact Row merged with address columns (as emitted by
 *                       getPendingContactRequests / ContactDataBuilder)
 * @return string Space-joined lowercased address values, htmlspecialchars-safe
 */
function contactAddressSearchAttr(array $contact): string {
    $values = [];
    foreach (getOrderedAddressSchemaTypes() as $type) {
        if (!empty($contact[$type])) {
            $values[] = (string) $contact[$type];
        }
    }
    return strtolower(implode(' ', $values));
}
