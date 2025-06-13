<?php
# Copyright 2025

//processing messages like (r)p2p
require_once("/etc/eiou/config.php");
require_once("/etc/eiou/functions.php");
$lockfile = '/tmp/messages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Create PDO connection
$pdo = createPDOConnection();

while (TRUE) {
    processQueuedP2pMessages();
    processQueuedRp2pMessages();
    // Sleep to prevent tight looping
    usleep(500000); // Sleep for 500ms (0.5 seconds)
}