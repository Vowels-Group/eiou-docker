<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Wallet Service
 *
 * Handles all business logic for wallet management.
 *
 * @package Services
 */
class WalletService {
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
            echo returnNoWalletExists();
            exit();
        }
    }
}
