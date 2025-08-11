<?php

# Copyright 2025

function sych($argv){
    if(isset($argv[2])){
        $argument = strtolower($argv[2]);
        if($argument === 'contacts'){
            synchAllContacts();
        } elseif($argument === 'transactions'){
            synchAllTransactions();
        }
    } else{
        synchAll();
    }

}

function synchAll(){
    synchAllContacts();
    synchAllTransactions();
}

function synchAllContacts(){
    //output synching all contacts
    $contacts = retrieveContactAddresses();
    foreach ($contacts as $contact) {
        synchContact($contact);
    }
}

function synchContact($contactAddress){
    // Synch contact
    $contact = retrieveContactQuery($contactAddress); // Get contact from database
    if($contact['status'] === 'pending'){
        output(outputSynchContactDueToPendingStatus($contactAddress),'SILENT');
        // If the contact is still pending then inquire with contact
        $messagePayload = buildMessageContactIsAcceptedInquiryPayload($contactAddress);
        $synchResponse = json_decode(send($contactAddress, $messagePayload),true);
        $status = $synchResponse['status'];
        $reason = $synchResponse['reason'];
        if($status === 'accepted'){
            // If you are accepted as a contact by the contact in question then update accordingly 
            updateContactStatus($contactAddress, $status);
            output(outputContactSuccesfullySynched($contactAddress),'SILENT');
        } elseif($status === 'rejected' && $reason === 'unknown'){
            // If no database existence of contact request on their end, resend contact request
            $contactPayload = createContactPayload();
            $responseData = json_decode(send($contactAddress, $contactPayload), true);
            if(isset($responseData['status']) && ($responseData['status'] === 'accepted')){
                // Contact received our contact request, needs to be accepted by other user first
                //   If acceptance is automatic then able to check through following inquiry
                //   Otherwise would need to inquire again down the line (through synch or otherwise)
                $messagePayload = buildMessageContactIsAcceptedInquiryPayload($contactAddress);
                $synchResponse = send($contactAddress, $messagePayload);
                if($status === 'accepted'){
                    updateContactStatus($contactAddress, $status);
                    output(outputContactSuccesfullySynched($contactAddress),'SILENT');
                }
            }
        }
    }
}


function synchAllTransactions(){
    // To Do: synch transactions
}

function synchTransaction(){
    // To Do: synch single transaction
}


