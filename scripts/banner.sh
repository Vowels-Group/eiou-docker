#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# banner.sh - EIOU Startup Warning Banners
# =============================================================================
# Displays warning banners during container startup.
# Uses ASCII box-drawing characters (+, -, |) for maximum terminal
# compatibility. Unicode box-drawing characters (║, ═) render as
# double-width in many terminals (Windows, WSL2, Docker), breaking alignment.
#
# Box width adapts to the terminal — falls back to 80 columns when
# the terminal size is not available (common inside Docker containers).
#
# Usage: Source this file and call the banner functions
#   source /app/scripts/banner.sh
#   show_startup_warnings
#   show_alpha_warning_short
# =============================================================================

# Detect terminal width, default to 80 if unavailable
_TERM_WIDTH=${COLUMNS:-$(tput cols 2>/dev/null || echo 80)}

# Box content width = terminal width - 4 (2 borders + 2 spaces)
BOX_WIDTH=$((_TERM_WIDTH - 4))
# Cap: readable text, not wider than 76 or narrower than 40
[ "$BOX_WIDTH" -gt 76 ] && BOX_WIDTH=76
[ "$BOX_WIDTH" -lt 40 ] && BOX_WIDTH=40

# Pre-compute the horizontal border (BOX_WIDTH + 2 for the spaces around content)
_BOX_BORDER=$(printf -- '-%.0s' $(seq 1 $((BOX_WIDTH + 2))))

box_top() {
    printf '\033[0;31m+%s+\033[0m\n' "$_BOX_BORDER"
}

box_bottom() {
    printf '\033[0;31m+%s+\033[0m\n' "$_BOX_BORDER"
}

# Print a plain-text line padded to BOX_WIDTH inside red borders.
# Text must be no longer than BOX_WIDTH characters.
box_line() {
    local text="$1"
    local pad=$((BOX_WIDTH - ${#text}))
    [ "$pad" -lt 0 ] && pad=0
    printf '\033[0;31m|\033[0m %s%*s \033[0;31m|\033[0m\n' "$text" "$pad" ""
}

# Print a line with ANSI color codes. Pass the visible (plain) text separately
# so padding is calculated from the actual display width.
box_line_color() {
    local colored_text="$1"
    local visible_text="$2"
    local pad=$((BOX_WIDTH - ${#visible_text}))
    [ "$pad" -lt 0 ] && pad=0
    printf '\033[0;31m|\033[0m %s%*s \033[0;31m|\033[0m\n' "$colored_text" "$pad" ""
}

box_empty() {
    box_line ""
}

# Word-wrap text to BOX_WIDTH and output each line via box_line.
# Short lines pass through unchanged. Long lines split at word boundaries.
box_wrap() {
    local text="$1"
    if [ ${#text} -le $BOX_WIDTH ]; then
        box_line "$text"
        return
    fi
    local current_line=""
    for word in $text; do
        if [ -z "$current_line" ]; then
            current_line="$word"
        elif [ $((${#current_line} + 1 + ${#word})) -le $BOX_WIDTH ]; then
            current_line="$current_line $word"
        else
            box_line "$current_line"
            current_line="$word"
        fi
    done
    [ -n "$current_line" ] && box_line "$current_line"
}

# Full alpha/testing warning banner - shown at container start
show_alpha_warning() {
    echo ""
    box_top
    box_empty
    box_line_color "$(printf '\033[1;33mWARNING: ALPHA/STAGING VERSION\033[0m')" \
                   "WARNING: ALPHA/STAGING VERSION"
    box_empty
    box_line "* This is an alpha/staging version of eIOU."
    box_line "* Do NOT use this for real financial transactions."
    box_line "* All data may be reset without notice."
    box_line "* For testing purposes only."
    box_empty
    box_bottom
    echo ""
}

# Legal notice banner - loaded from separate file for easy editing.
# Lines longer than BOX_WIDTH are word-wrapped automatically.
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
    box_wrap "By using this software, you acknowledge that you have read and agree to the above terms."
    box_empty
    box_bottom
    echo ""
}

# Short reminder banner - shown before watchdog starts
show_alpha_warning_short() {
    local border
    border=$(printf '=%.0s' $(seq 1 $((BOX_WIDTH + 4))))
    echo ""
    printf '\033[1;33m%s\033[0m\n' "$border"
    printf '\033[1;33m  REMINDER: This is an ALPHA/STAGING version - FOR TESTING PURPOSES ONLY\033[0m\n'
    printf '\033[1;33m%s\033[0m\n' "$border"
    echo ""
}

# Prominent error banner for critical failures (e.g., invalid seedphrase)
show_error_banner() {
    local error_title="$1"
    local error_message="$2"
    echo ""
    box_top
    box_empty
    box_line_color "$(printf '\033[0;31mCRITICAL ERROR: %s\033[0m' "$error_title")" \
                   "CRITICAL ERROR: $error_title"
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
