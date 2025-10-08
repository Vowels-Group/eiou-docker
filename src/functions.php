<?php
# Copyright 2025

// Require database functionality
require_once 'src/database/pdo.php';
require_once 'src/database/AbstractRepository.php';
require_once 'src/database/ContactRepository.php';
require_once 'src/database/DebugRepository.php';
require_once 'src/database/databaseSchema.php';
require_once 'src/database/databaseSetup.php';
require_once 'src/database/P2pRepository.php';
require_once 'src/database/Rp2pRepository.php';
require_once 'src/database/TransactionRepository.php';

// Require all files in the services directory
require_once 'src/services/CleanupService.php';
require_once 'src/services/ContactService.php';
require_once 'src/services/MessageService.php';
require_once 'src/services/P2pService.php';
require_once 'src/services/Rp2pService.php';
require_once 'src/services/ServiceContainer.php';
require_once 'src/services/ServiceWrappers.php';
require_once 'src/services/SynchService.php';
require_once 'src/services/TransactionService.php';
require_once 'src/services/WalletService.php';

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
require_once 'src/utils/utilGeneral.php';
require_once 'src/utils/utilTransport.php';
require_once 'src/utils/utilUserInteraction.php';
require_once 'src/utils/utilValidation.php';