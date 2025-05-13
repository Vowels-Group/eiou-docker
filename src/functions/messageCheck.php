<?php
// Check if all needed precursors to messages.php are available and working
$passed = false;
try{
    // Checks if config.php exists (needed for messages.php)
    if(!file_exists("/etc/eiou/config.php")){
        return;
    }
    require_once("/etc/eiou/config.php");
    require_once("/etc/eiou/functions/pdo.php");
    // create PDO connection, will return the connection or raise an exception (check if PDO functions)
    $pdo = createPDOConnection();
    $pdo = null; // reset PDO
    $passed = true; // All needed precursors are available and working
    return;
} catch(Exception $e){
    return;
}
