<?php
# Copyright 2025

// Check if all needed precursors for core functionality are available and working
$passed = false;
try{
    // Checks if dbuserconfig.php exists (needed for core functionality)
    if(!file_exists('/etc/eiou/dbuserconfig.php')){
        return;
    }
    require_once '/etc/eiou/src/database/pdo.php';
    // create PDO connection, will return the connection or raise an exception (check if PDO functions)
    $pdo = createPDOConnection();
    $pdo = null; // reset PDO
    $passed = true; // All needed precursors are available and working
    return;
} catch(Exception $e){
    return;
}
