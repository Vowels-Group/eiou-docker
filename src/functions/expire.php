<?php

function expireMessage($message){
    // Expire the p2p request
    updateP2pRequestStatus($message['hash'], 'expired');
    output(outputP2pExpired($message),'SILENT');

    // Cancel transaction if exists
    if(getTransactionByMemo($message['hash'])){
        updateTransactionStatus($message['hash'], 'cancelled');
        output(outputTransactionExpired($message),'SILENT');
    }
}
