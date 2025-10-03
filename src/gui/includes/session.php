<?php
/**
 * Session Management for eIOU Wallet
 * Handles secure session-based authentication
 * Copyright 2025
 */

// Start session with secure settings
function startSecureSession() {
    // Set session cookie parameters before starting session
    $cookieParams = [
        'lifetime' => 0, // Session cookie (expires when browser closes)
        'path' => '/',
        'domain' => '', // Current domain
        'secure' => isset($_SERVER['HTTPS']), // Only send over HTTPS if available
        'httponly' => true, // Prevent JavaScript access
        'samesite' => 'Strict' // Prevent CSRF attacks
    ];

    session_set_cookie_params($cookieParams);

    // Use a custom session name
    session_name('EIOU_WALLET_SESSION');

    // Start the session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Regenerate session ID periodically for security
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Regenerate every 5 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Check if user is authenticated
function isAuthenticated() {
    startSecureSession();
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Authenticate user with auth code
function authenticate($authCode, $userAuthCode) {
    startSecureSession();

    // Use constant-time comparison to prevent timing attacks
    if (hash_equals($userAuthCode, $authCode)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();

        // Regenerate session ID on successful authentication
        session_regenerate_id(true);

        return true;
    }

    return false;
}

// Logout user
function logout() {
    startSecureSession();

    // Clear session data
    $_SESSION = [];

    // Destroy the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Destroy the session
    session_destroy();
}

// Check for session timeout (30 minutes of inactivity)
function checkSessionTimeout() {
    startSecureSession();

    if (isset($_SESSION['last_activity'])) {
        $inactive = time() - $_SESSION['last_activity'];
        $timeout = 1800; // 30 minutes

        if ($inactive >= $timeout) {
            logout();
            return false;
        }
    }

    $_SESSION['last_activity'] = time();
    return true;
}

// Require authentication for protected pages
function requireAuth() {
    if (!isAuthenticated() || !checkSessionTimeout()) {
        // Redirect to login page
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}