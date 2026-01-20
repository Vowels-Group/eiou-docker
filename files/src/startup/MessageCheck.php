<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';

// Check if all needed precursors for core functionality are available and working
$passed = false;
try{
    // Checks if dbconfig.json exists (needed for core functionality)
    if(!file_exists('/etc/eiou/dbconfig.json')){
        SecureLogger::error("messageCheck: dbconfig.json not found at /etc/eiou/dbconfig.json");
        return;
    }
    require_once '/etc/eiou/src/database/Pdo.php';
    // create PDO connection, will return the connection or raise an exception (check if PDO functions)
    $pdo = createPDOConnection();
    $pdo = null; // reset PDO
    $passed = true; // All needed precursors are available and working
    return;
} catch(Exception $e){
    SecureLogger::logException($e, 'ERROR');
    return;
}
