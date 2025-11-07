<?php
# Copyright 2025

/**
 * Wallet Index - MVC Architecture
 *
 * Main entry point for wallet interface using proper MVC pattern
 */

// Check if eIOU is initialized
if (!file_exists("/etc/eiou/userconfig.json")) {
    echo "eIOU has not yet been initiated. Please run from terminal to initialize the system.";
    die;
}

// Bootstrap MVC application
require_once '/etc/eiou/src/gui/Bootstrap.php';

use Eiou\Gui\Bootstrap;

// Get Bootstrap instance (dependency injection container)
$bootstrap = Bootstrap::getInstance();

// Handle logout request
$bootstrap->handleLogout();

// Handle authentication POST request
if ($bootstrap->handleAuthentication()) {
    // Authentication successful, redirect to clean URL
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if user is authenticated
if (!$bootstrap->isAuthenticated()) {
    // Show authentication form
    header('Content-Type: text/html; charset=UTF-8');
    require_once("/etc/eiou/src/gui/layout/authenticationForm.html");
    exit;
}

// User is authenticated, check session timeout
if (!$bootstrap->checkSessionTimeout()) {
    // Session timed out, redirect to login
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// If we get here, authentication is valid and session is active

// Verify CSRF token for POST requests
$bootstrap->verifyCSRFToken();

// Get controllers from Bootstrap (with dependency injection)
$contactController = $bootstrap->getController('ContactController');
$transactionController = $bootstrap->getController('TransactionController');
$walletController = $bootstrap->getController('WalletController');

// Route controller actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactController->routeAction();
    $transactionController->routeAction();
}

// Handle GET requests (e.g., check_updates)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $transactionController->routeAction();
}

// Get view data from WalletController
$walletController->index();

// Note: WalletController->index() handles rendering the view
// It prepares all data and includes the wallet.html template
