<?php
# Copyright 2025-2026 Vowels Group, LLC

// Include the Composer autoloader
require_once __DIR__ . '/../bootstrap.php';

use Eiou\Utils\Logger;
use function Eiou\Database\createPDOConnection;

// Check if all needed precursors for core functionality are available and working
$passed = false;
try{
    // Checks if dbconfig.json exists (needed for core functionality)
    if(!file_exists('/etc/eiou/config/dbconfig.json')){
        Logger::getInstance()->error("messageCheck: dbconfig.json not found at /etc/eiou/config/dbconfig.json");
        return;
    }
    // create PDO connection, will return the connection or raise an exception (check if PDO functions)
    $pdo = createPDOConnection();
    $pdo = null; // reset PDO
    $passed = true; // All needed precursors are available and working
    return;
} catch(\Exception $e){
    Logger::getInstance()->logException($e, [], 'ERROR');
    return;
}
