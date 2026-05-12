<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Includes;

/**
 * Session key constants
 *
 * Centralizes all $_SESSION key names to prevent typos and enable
 * IDE autocompletion/refactoring.
 */
class SessionKeys
{
    // Authentication
    const AUTHENTICATED = 'authenticated';
    const AUTH_TIME = 'auth_time';
    const LAST_ACTIVITY = 'last_activity';
    const LAST_REGENERATION = 'last_regeneration';
    // True when the current session was authenticated via the alternate
    // auth code rather than the primary BIP39-derived one. Used to block
    // alt-code holders from rotating the alt code itself.
    const AUTH_VIA_ALT = 'auth_via_alt';

    // Sensitive-action re-auth (independent of remember-me)
    const SENSITIVE_ACCESS_UNTIL = 'sensitive_access_until';
    const SENSITIVE_ACCESS_AUTH_TIME = 'sensitive_access_auth_time';

    // CSRF protection
    const CSRF_TOKEN = 'csrf_token';
    const CSRF_TOKEN_TIME = 'csrf_token_time';

    // Flash messages
    const MESSAGE = 'message';
    const MESSAGE_TYPE = 'message_type';

    // Transaction tracking
    const IN_PROGRESS_TXIDS = 'in_progress_txids';
    const KNOWN_TXIDS = 'known_txids';
    const KNOWN_DLQ_IDS = 'known_dlq_ids';
}
