<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Application Bootstrap File
 *
 * Central autoloader that initializes core EIOU application dependencies.
 * This file should be included at the start of all entry points to ensure
 * proper dependency loading order.
 *
 * Loading Order:
 * 1. Core - Application singleton and base functionality
 * 2. Database - Abstract repository for database operations
 * 3. Contracts - Service interfaces (must be loaded before services)
 * 4. Services - Utility and main service containers
 * 5. Schemas - Output formatting (Echo, Output schemas)
 *
 * @note ServiceWrappers.php contains the output() wrapper function
 *       which is scheduled for removal in future refactoring.
 */

// Require core functionality
require_once 'src/core/Application.php';

// Require database functionality
require_once 'src/database/AbstractRepository.php';

// Require all service interfaces (contracts) - must be loaded before services
// Utility service interfaces
require_once 'src/contracts/TimeUtilityServiceInterface.php';
require_once 'src/contracts/CurrencyUtilityServiceInterface.php';
require_once 'src/contracts/ValidationUtilityServiceInterface.php';
require_once 'src/contracts/GeneralUtilityServiceInterface.php';
require_once 'src/contracts/TransportServiceInterface.php';

// Core service interfaces
require_once 'src/contracts/ApiAuthServiceInterface.php';
require_once 'src/contracts/ApiKeyServiceInterface.php';
require_once 'src/contracts/CleanupServiceInterface.php';
require_once 'src/contracts/CliServiceInterface.php';
require_once 'src/contracts/ContactServiceInterface.php';
require_once 'src/contracts/ContactStatusServiceInterface.php';
require_once 'src/contracts/DebugServiceInterface.php';
require_once 'src/contracts/HeldTransactionServiceInterface.php';
require_once 'src/contracts/MessageDeliveryServiceInterface.php';
require_once 'src/contracts/MessageServiceInterface.php';
require_once 'src/contracts/P2pServiceInterface.php';
require_once 'src/contracts/RateLimiterServiceInterface.php';
require_once 'src/contracts/Rp2pServiceInterface.php';
require_once 'src/contracts/SyncServiceInterface.php';
require_once 'src/contracts/TransactionRecoveryServiceInterface.php';
require_once 'src/contracts/TransactionServiceInterface.php';
require_once 'src/contracts/WalletServiceInterface.php';

// Require all files in the services directory
require_once 'src/services/utilities/UtilityServiceContainer.php';
require_once 'src/services/ServiceContainer.php';

// Still needed for the output() wrapper function (will be removed in separate refactoring)
require_once 'src/services/ServiceWrappers.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/EchoSchema.php';
require_once 'src/schemas/OutputSchema.php';
