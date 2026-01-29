<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\WalletServiceInterface;
use Eiou\Core\UserContext;
use Eiou\Core\ErrorCodes;
use Eiou\Exceptions\FatalServiceException;

/**
 * Wallet Service
 *
 * Handles all business logic for wallet management.
 */
class WalletService implements WalletServiceInterface {
    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Constructor
     *
     * @param UserContext $currentUser Current user data
     */
    public function __construct(UserContext $currentUser) {
        $this->currentUser = $currentUser;
    }

    /**
     * Check if wallet exists
     *
     * @param string $request Request type
     * @return void
     */
    public function checkWalletExists(string $request): void {
        // Check if wallet exists
        if ((null === $this->currentUser->hasKeys()) && $request != 'generate' && $request != 'restore') {
            throw new FatalServiceException(
                "Wallet does not exist. Run 'generate' or 'restore' first.",
                ErrorCodes::WALLET_NOT_FOUND,
                ['requested_action' => $request],
                404
            );
        }
    }
}
