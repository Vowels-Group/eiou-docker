<?php
//processing messages like (r)p2p
require_once("/var/www/html/eiou/config.php");
require_once("/var/www/html/eiou/functions.php");
$lockfile = '/tmp/messages_lock.pid';

// Ensure only one instance runs
checkSingleInstance($lockfile);

// Create PDO connection
$pdo = createPDOConnection();

while (TRUE) {
    processQueuedP2pMessages();
    processQueuedRP2pMessages();
    // Sleep to prevent tight looping
    usleep(500000); // Sleep for 500ms (0.5 seconds)
}