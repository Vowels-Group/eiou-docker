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
#   show_startup_warnings
#   show_alpha_warning_short
# =============================================================================

# Ensure correct character counting for UTF-8 box-drawing characters.
# Without this, printf/fold/string-length count bytes instead of characters,
# causing misaligned borders for multi-byte chars (║, •) and ANSI color codes.
export LC_ALL=C.UTF-8 2>/dev/null || export LC_ALL=en_US.UTF-8 2>/dev/null || true

# Colors for terminal output.
# Using $'...' so variables contain actual ESC bytes — no echo -e needed.
RED=$'\033[0;31m'
YELLOW=$'\033[1;33m'
NC=$'\033[0m'

# Box width (inner content area between the ║ borders)
BOX_WIDTH=76

# Pre-compute the horizontal border line
_BOX_BORDER=$(printf '═%.0s' $(seq 1 $((BOX_WIDTH + 2))))

# Print the top border of a box
box_top() {
    echo "${RED}╔${_BOX_BORDER}╗${NC}"
}

# Print the bottom border of a box
box_bottom() {
    echo "${RED}╚${_BOX_BORDER}╝${NC}"
}

# Print a content line inside a red box with proper right-edge alignment.
# Strips ANSI color codes before measuring width so colored text aligns correctly.
box_line() {
    local text="$1"
    # Strip ANSI escape sequences to calculate visible character width
    local stripped
    stripped=$(printf '%s' "$text" | sed 's/\x1b\[[0-9;]*m//g')
    local visible_len=${#stripped}
    local pad=$((BOX_WIDTH - visible_len))
    [ "$pad" -lt 0 ] && pad=0
    local spaces=""
    [ "$pad" -gt 0 ] && spaces=$(printf "%${pad}s" "")
    echo "${RED}║${NC} ${text}${spaces} ${RED}║${NC}"
}

# Print an empty line inside the box
box_empty() {
    box_line ""
}

# Print text wrapped to fit inside the box
box_wrap() {
    echo "$1" | fold -s -w "$BOX_WIDTH" | while IFS= read -r line; do
        box_line "$line"
    done
}

# Full alpha/testing warning banner - shown at container start
show_alpha_warning() {
    echo ""
    box_top
    box_empty
    box_line "${YELLOW}WARNING: ALPHA/STAGING VERSION${NC}"
    box_empty
    box_line "* This is an alpha/staging version of eIOU."
    box_line "* Do NOT use this for real financial transactions."
    box_line "* All data may be reset without notice."
    box_line "* For testing purposes only."
    box_empty
    box_bottom
    echo ""
}

# Legal notice banner - loaded from separate file for easy editing
show_legal_notice() {
    local notice_file="/app/scripts/legal-notice.txt"
    if [ ! -f "$notice_file" ]; then
        return 0
    fi

    echo ""
    box_top
    box_empty

    while IFS= read -r line || [ -n "$line" ]; do
        if [ -z "$line" ]; then
            box_empty
        else
            box_wrap "$line"
        fi
    done < "$notice_file"

    box_empty
    box_bottom
    echo ""
}

# Combined: show all warnings, then the acceptance line
show_startup_warnings() {
    show_alpha_warning
    show_legal_notice

    echo ""
    box_top
    box_empty
    box_line "By using this software, you acknowledge that you have read and"
    box_line "agree to the above terms."
    box_empty
    box_bottom
    echo ""
}

# Short reminder banner - shown before watchdog starts
show_alpha_warning_short() {
    echo ""
    echo "${YELLOW}══════════════════════════════════════════════════════════════════════════════${NC}"
    echo "${YELLOW}  REMINDER: This is an ALPHA/STAGING version - FOR TESTING PURPOSES ONLY${NC}"
    echo "${YELLOW}══════════════════════════════════════════════════════════════════════════════${NC}"
    echo ""
}

# Prominent error banner for critical failures (e.g., invalid seedphrase)
show_error_banner() {
    local error_title="$1"
    local error_message="$2"
    echo ""
    box_top
    box_empty
    box_line "${RED}CRITICAL ERROR: ${error_title}${NC}"
    box_empty
    if [ -n "$error_message" ]; then
        box_wrap "$error_message"
    fi
    box_empty
    box_line "The container will now stop. Please check your configuration"
    box_line "and try again with a valid seedphrase."
    box_empty
    box_bottom
    echo ""
}
