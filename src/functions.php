<?php
# Copyright 2025

// Require all files in the functions directory
require_once 'src/functions/pdo.php';
require_once 'src/functions/contacts.php';

require_once 'src/functions/database/databaseContactInteraction.php';
require_once 'src/functions/database/databaseDebugInteraction.php';
require_once 'src/functions/database/databaseP2pInteraction.php';
require_once 'src/functions/database/databaseRp2pInteraction.php';
require_once 'src/functions/database/databaseSchema.php';
require_once 'src/functions/database/databaseSetup.php';
require_once 'src/functions/database/databaseTransactionInteraction.php';


require_once 'src/functions/echoSchema.php';
require_once 'src/functions/message.php';
require_once 'src/functions/outputSchema.php';

require_once 'src/functions/payloads/payloadContactSchema.php';
require_once 'src/functions/payloads/payloadMessageSchema.php';
require_once 'src/functions/payloads/payloadP2pSchema.php';
require_once 'src/functions/payloads/payloadRp2pSchema.php';
require_once 'src/functions/payloads/payloadTransactionSchema.php';
require_once 'src/functions/payloads/payloadUtilSchema.php';


require_once 'src/functions/p2p.php';
require_once 'src/functions/rp2p.php';
require_once 'src/functions/synch.php';
require_once 'src/functions/transactions.php';

require_once 'src/functions/utils/utilDebug.php';
require_once 'src/functions/utils/utilGeneral.php';
require_once 'src/functions/utils/utilTransport.php';
require_once 'src/functions/utils/utilUserInteraction.php';

require_once 'src/functions/validation.php';
require_once 'src/functions/wallet.php';