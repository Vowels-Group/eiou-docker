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
 * 3. Services - Utility and main service containers
 * 4. Schemas - Output formatting (Echo, Output schemas)
 *
 * @note ServiceWrappers.php contains the output() wrapper function
 *       which is scheduled for removal in future refactoring.
 */

// Require core functionality
require_once 'src/core/Application.php';

// Require database functionality
require_once 'src/database/AbstractRepository.php';

// Require all files in the services directory
require_once 'src/services/utilities/UtilityServiceContainer.php';
require_once 'src/services/ServiceContainer.php';

// Still needed for the output() wrapper function (will be removed in separate refactoring)
require_once 'src/services/ServiceWrappers.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/EchoSchema.php';
require_once 'src/schemas/OutputSchema.php';