<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Includes;

/**
 * Session key constants
 *
 * Centralizes all $_SESSION key names to prevent typos and enable
 * IDE autocompletion/refactoring.
 *
 * @see https://github.com/eiou-org/eiou-docker/issues/699
 */
class SessionKeys
{
    // Authentication
    const AUTHENTICATED = 'authenticated';
    const AUTH_TIME = 'auth_time';
    const LAST_ACTIVITY = 'last_activity';
    const LAST_REGENERATION = 'last_regeneration';

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
