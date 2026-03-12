#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC
#
# credentials.example.sh - Template for test environment credentials
#
# SETUP INSTRUCTIONS:
# 1. Copy this file to credentials.sh:
#    cp credentials.example.sh credentials.sh
#
# 2. credentials.sh is gitignored and will NOT be committed
#
# 3. Update values below with your test-specific credentials (if any)
#
# IMPORTANT: Most eIOU credentials are auto-generated and do not require
# manual configuration. This file is for edge cases only.
#
# Current auto-generated credentials (NO ACTION REQUIRED):
# - Database credentials: Generated in DatabaseSetup.php using random_bytes()
# - API keys: Generated during test execution
# - Wallet seedphrases: Generated at container startup
# - SSL certificates: Auto-generated in startup.sh
#
###############################################################################

# =============================================================================
# EXTERNAL SERVICE CREDENTIALS (if testing with external services)
# =============================================================================
# Uncomment and set if your tests integrate with external services

# Example: External API for integration testing
# export EXTERNAL_API_KEY=""
# export EXTERNAL_API_SECRET=""

# Example: CI/CD webhook for notifications
# export CI_WEBHOOK_URL=""
# export CI_WEBHOOK_TOKEN=""

# =============================================================================
# TEST OVERRIDE CREDENTIALS (advanced usage only)
# =============================================================================
# These allow overriding auto-generated values for specific test scenarios
# Generally NOT recommended - use auto-generated values

# Override database host (default: localhost)
# export EIOU_TEST_DB_HOST=""

# Override test container timeout (default: 120s)
# export EIOU_INIT_TIMEOUT=""

# Override Tor timeout (default: 120s)
# export EIOU_TOR_TIMEOUT=""

# =============================================================================
# USAGE
# =============================================================================
# Source this file in your test scripts:
#   if [ -f "./baseconfig/credentials.sh" ]; then
#       . "./baseconfig/credentials.sh"
#   fi
#
# Or source from config.sh for automatic loading
