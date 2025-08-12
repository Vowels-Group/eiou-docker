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

require_once 'src/functions/payloads/contactPayloadSchema.php';
require_once 'src/functions/payloads/messagePayloadSchema.php';
require_once 'src/functions/payloads/p2pPayloadSchema.php';
require_once 'src/functions/payloads/rp2pPayloadSchema.php';
require_once 'src/functions/payloads/transactionPayloadSchema.php';
require_once 'src/functions/payloads/utilPayloadSchema.php';


require_once 'src/functions/p2p.php';
require_once 'src/functions/rp2p.php';
require_once 'src/functions/synch.php';
require_once 'src/functions/transactions.php';

require_once 'src/functions/utils/debug.php';
require_once 'src/functions/utils/general.php';
require_once 'src/functions/utils/transport.php';
require_once 'src/functions/utils/userInteraction.php';

require_once 'src/functions/validation.php';
require_once 'src/functions/wallet.php';