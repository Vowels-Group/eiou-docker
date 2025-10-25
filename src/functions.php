<?php
# Copyright 2025

// Require core functionality
require_once 'src/core/Application.php';
require_once 'src/core/Constants.php';
require_once 'src/core/ErrorHandler.php';
require_once 'src/core/UserContext.php';

// Require database functionality
require_once 'src/database/databaseSetup.php';
require_once 'src/database/pdo.php';
require_once 'src/database/AbstractRepository.php';

// Require all files in the services directory
require_once 'src/services/utilities/UtilityServiceContainer.php';
require_once 'src/services/ServiceContainer.php';
require_once 'src/services/ServiceWrappers.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/echoSchema.php';
require_once 'src/schemas/outputSchema.php';
require_once 'src/schemas/payloads/BasePayload.php';

// Require util functionality
require_once 'src/utils/AdaptivePoller.php';
require_once 'src/utils/InputValidator.php';
require_once 'src/utils/RateLimiter.php';
require_once 'src/utils/SecureLogger.php';
require_once 'src/utils/Security.php';