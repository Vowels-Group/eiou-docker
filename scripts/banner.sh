#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# banner.sh - EIOU Startup Warning Banners
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
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Box width (inner content area, excluding the ║ borders)
BOX_WIDTH=76

# Print a line inside a red box with proper right-edge alignment
# Usage: box_line "text"
box_line() {
    printf "${RED}║${NC} %-${BOX_WIDTH}s ${RED}║${NC}\n" "$1"
}

# Print an empty line inside the box
box_empty() {
    box_line ""
}

# Print text wrapped to fit inside the box
# Usage: box_wrap "long text here"
box_wrap() {
    echo "$1" | fold -s -w $BOX_WIDTH | while IFS= read -r line; do
        box_line "$line"
    done
}

# Full alpha/testing warning banner - shown at container start
show_alpha_warning() {
    echo ""
    printf "${RED}╔"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╗${NC}\n"
    box_empty
    box_line "$(echo -e "${YELLOW}WARNING: ALPHA/STAGING VERSION${NC}")"
    box_empty
    box_line "• This is an alpha/staging version of eIOU."
    box_line "• Do NOT use this for real financial transactions."
    box_line "• All data may be reset without notice."
    box_line "• For testing purposes only."
    box_empty
    printf "${RED}╚"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╝${NC}\n"
    echo ""
}

# Legal notice banner - loaded from separate file for easy editing
# Usage: show_legal_notice
show_legal_notice() {
    local notice_file="/app/scripts/legal-notice.txt"
    if [ ! -f "$notice_file" ]; then
        return 0
    fi

    echo ""
    printf "${RED}╔"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╗${NC}\n"
    box_empty

    while IFS= read -r line || [ -n "$line" ]; do
        if [ -z "$line" ]; then
            box_empty
        else
            box_wrap "$line"
        fi
    done < "$notice_file"

    box_empty
    printf "${RED}╚"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╝${NC}\n"
    echo ""
}

# Combined: show all warnings, then the acceptance line
show_startup_warnings() {
    show_alpha_warning
    show_legal_notice

    echo ""
    printf "${RED}╔"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╗${NC}\n"
    box_empty
    box_line "By using this software, you acknowledge that you have read and"
    box_line "agree to the above terms."
    box_empty
    printf "${RED}╚"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╝${NC}\n"
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
    printf "${RED}╔"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╗${NC}\n"
    box_empty
    box_line "$(echo -e "${RED}CRITICAL ERROR: ${error_title}${NC}")"
    box_empty
    if [ -n "$error_message" ]; then
        box_wrap "$error_message"
    fi
    box_empty
    box_line "The container will now stop. Please check your configuration"
    box_line "and try again with a valid seedphrase."
    box_empty
    printf "${RED}╚"; printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))); printf "╝${NC}\n"
    echo ""
}
