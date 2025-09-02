<?php
# Copyright 2025

//processing transation messages
require_once("/etc/eiou/config.php");
require_once("/etc/eiou/functions.php");
$lockfile = '/tmp/messages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Create PDO connection
$pdo = createPDOConnection();

while (TRUE) {
    processPendingTransactions();
    // Sleep to prevent tight looping
    usleep(500000); // Sleep for 500ms (0.5 seconds)
}