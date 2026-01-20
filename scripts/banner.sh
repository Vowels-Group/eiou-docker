#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# banner.sh - EIOU Alpha/Testing Warning Banner
# =============================================================================
# This file contains warning banners displayed during container startup.
# Edit this file to update the warning message for all containers.
#
# Usage: Source this file and call the banner functions
#   source /app/scripts/banner.sh
#   show_alpha_warning
#   show_alpha_warning_short
# =============================================================================

# Colors for terminal output
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Full alpha/testing warning banner - shown at container start
show_alpha_warning() {
    echo ""
    echo -e "${RED}╔══════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}║${NC}  ${YELLOW}⚠️  WARNING: ALPHA/STAGING VERSION${NC}                                          ${RED}║${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}║${NC}  • This is an alpha/staging version of eIOU.                                ${RED}║${NC}"
    echo -e "${RED}║${NC}  • Do NOT use this for real financial transactions.                         ${RED}║${NC}"
    echo -e "${RED}║${NC}  • All data may be reset without notice.                                    ${RED}║${NC}"
    echo -e "${RED}║${NC}  • For testing purposes only.                                               ${RED}║${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}║${NC}  By using this software, you acknowledge that you have read and agree       ${RED}║${NC}"
    echo -e "${RED}║${NC}  to the above terms.                                                        ${RED}║${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}╚══════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Short reminder banner - shown before watchdog starts
show_alpha_warning_short() {
    echo ""
    echo -e "${YELLOW}══════════════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}  REMINDER: This is an ALPHA/STAGING version - FOR TESTING PURPOSES ONLY${NC}"
    echo -e "${YELLOW}══════════════════════════════════════════════════════════════════════════════${NC}"
    echo ""
}

# Prominent error banner for critical failures (e.g., invalid seedphrase)
show_error_banner() {
    local error_title="$1"
    local error_message="$2"
    echo ""
    echo -e "${RED}╔══════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}║${NC}  ${RED}❌ CRITICAL ERROR: ${error_title}${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    if [ -n "$error_message" ]; then
        # Word wrap the error message to fit within the banner
        echo "$error_message" | fold -s -w 72 | while read -r line; do
            printf "${RED}║${NC}  %-74s${RED}║${NC}\n" "$line"
        done
    fi
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}║${NC}  The container will now stop. Please check your configuration and try       ${RED}║${NC}"
    echo -e "${RED}║${NC}  again with a valid seedphrase.                                             ${RED}║${NC}"
    echo -e "${RED}║${NC}                                                                              ${RED}║${NC}"
    echo -e "${RED}╚══════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}
