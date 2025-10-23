<?php
# Copyright 2025

// Require core functionality
require_once 'src/core/Application.php';
require_once 'src/core/Constants.php';
require_once 'src/core/ErrorHandler.php';
require_once 'src/core/UserContext.php';

// Require database functionality
require_once 'src/database/pdo.php';
require_once 'src/database/AbstractRepository.php';
require_once 'src/database/DebugRepository.php';
require_once 'src/database/ContactRepository.php';
require_once 'src/database/databaseSchema.php';
require_once 'src/database/databaseSetup.php';
require_once 'src/database/P2pRepository.php';
require_once 'src/database/Rp2pRepository.php';
require_once 'src/database/TransactionRepository.php';

// Require all files in the services directory
require_once 'src/services/utilities/UtilityServiceContainer.php';
require_once 'src/services/utilities/CurrencyUtilityService.php';
require_once 'src/services/utilities/TimeUtilityService.php';
require_once 'src/services/utilities/TransportUtilityService.php';
require_once 'src/services/utilities/ValidationUtilityService.php';
require_once 'src/services/ServiceContainer.php';
require_once 'src/services/CleanupService.php';
require_once 'src/services/ContactService.php';
require_once 'src/services/MessageService.php';
require_once 'src/services/P2pService.php';
require_once 'src/services/Rp2pService.php';
require_once 'src/services/ServiceWrappers.php';
require_once 'src/services/SynchService.php';
require_once 'src/services/TransactionService.php';
require_once 'src/services/WalletService.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/echoSchema.php';
require_once 'src/schemas/outputSchema.php';
require_once 'src/schemas/payloads/BasePayload.php';
require_once 'src/schemas/payloads/ContactPayload.php';
require_once 'src/schemas/payloads/MessagePayload.php';
require_once 'src/schemas/payloads/P2pPayload.php';
require_once 'src/schemas/payloads/Rp2pPayload.php';
require_once 'src/schemas/payloads/TransactionPayload.php';
require_once 'src/schemas/payloads/UtilPayload.php';

// Require util functionality
require_once 'src/utils/AdaptivePoller.php';
require_once 'src/utils/InputValidator.php';
require_once 'src/utils/RateLimiter.php';
require_once 'src/utils/SecureLogger.php';
require_once 'src/utils/Security.php';