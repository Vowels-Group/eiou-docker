<?php
# Copyright 2025

// Require core functionality
require_once 'src/core/Application.php';

// Require database functionality
require_once 'src/database/AbstractRepository.php';

// Require all files in the services directory
require_once 'src/services/utilities/UtilityServiceContainer.php';
require_once 'src/services/ServiceContainer.php';
// Needed for temporary bridge of service functions between services
require_once 'src/services/ServiceWrappers.php';

// Require schema (echo/output/payload) functionality
require_once 'src/schemas/echoSchema.php';
require_once 'src/schemas/outputSchema.php';