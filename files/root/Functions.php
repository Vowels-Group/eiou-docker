<?php
# Copyright 2025 The Vowels Company

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