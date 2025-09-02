<?php
# Copyright 2025

# Check if there are any messages that have expired
function processCleanupMessages(){
     $expiringMessages = retrieveExpiringP2pMessages();

    // Process each not completed message
    foreach ($expiringMessages as $message) {     
         // If no response after set amount of time, expire the p2p (and potential transaction)
        if(returnMicroTime() > $message['expiration']){
            
            // TO DO: check for potential synch case of transaction before expiring

            expireMessage($message);
        }
    }
}