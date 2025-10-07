<?php
# Copyright 2025

// Require database functionality
require_once 'src/database/pdo.php';
require_once 'src/database/databaseContactInteraction.php';
require_once 'src/database/databaseDebugInteraction.php';
require_once 'src/database/databaseP2pInteraction.php';
require_once 'src/database/databaseRp2pInteraction.php';
require_once 'src/database/databaseSchema.php';
require_once 'src/database/databaseSetup.php';
require_once 'src/database/databaseTransactionInteraction.php';

// Require all files in the functions directory
require_once 'src/functions/cleanup.php';
require_once 'src/functions/contacts.php';
require_once 'src/functions/message.php';
require_once 'src/functions/p2p.php';
require_once 'src/functions/rp2p.php';
require_once 'src/functions/synch.php';
require_once 'src/functions/transactions.php';
require_once 'src/functions/wallet.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/echoSchema.php';
require_once 'src/schemas/outputSchema.php';
require_once 'src/schemas/payloads/payloadContactSchema.php';
require_once 'src/schemas/payloads/payloadMessageSchema.php';
require_once 'src/schemas/payloads/payloadP2pSchema.php';
require_once 'src/schemas/payloads/payloadRp2pSchema.php';
require_once 'src/schemas/payloads/payloadTransactionSchema.php';
require_once 'src/schemas/payloads/payloadUtilSchema.php';

// Require util functionality
require_once 'src/utils/AdaptivePoller.php';
require_once 'src/utils/InputValidator.php';
require_once 'src/utils/RateLimiter.php';
require_once 'src/utils/SecureLogger.php';
require_once 'src/utils/Security.php';
require_once 'src/utils/utilCleanup.php';
require_once 'src/utils/utilDebug.php';
require_once 'src/utils/utilGeneral.php';
require_once 'src/utils/utilTransport.php';
require_once 'src/utils/utilUserInteraction.php';
require_once 'src/utils/utilValidation.php';