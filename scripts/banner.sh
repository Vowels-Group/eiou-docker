#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# banner.sh - eIOU Startup Warning Banners
# =============================================================================
# Displays warning banners during container startup.
# Uses horizontal rules for visual separation — no side borders, so there
# are no terminal-width alignment issues. Text is indented for readability.
#
# Usage: Source this file and call the banner functions
#   source /app/scripts/banner.sh
#   show_startup_warnings
#   show_alpha_warning_short
# =============================================================================

# Horizontal rule — 60 dashes
_HR="------------------------------------------------------------"

# Print a red horizontal rule
box_rule() {
    printf '\033[0;31m%s\033[0m\n' "$_HR"
}

# Full alpha/testing warning banner - loaded from separate file for easy editing.
show_alpha_warning() {
    local warning_file="/app/scripts/banners/alpha-warning.txt"
    if [ ! -f "$warning_file" ]; then
        return 0
    fi

    local first_line=1
    echo ""
    box_rule
    echo ""

    while IFS= read -r line || [ -n "$line" ]; do
        if [ "$first_line" -eq 1 ]; then
            printf '\033[1;33m  %s\033[0m\n' "$line"
            first_line=0
        elif [ -z "$line" ]; then
            echo ""
        else
            printf '  %s\n' "$line"
        fi
    done < "$warning_file"

    echo ""
    box_rule
    echo ""
}

# Legal notice banner - loaded from separate file for easy editing.
show_legal_notice() {
    local notice_file="/app/scripts/banners/legal-notice.txt"
    if [ ! -f "$notice_file" ]; then
        return 0
    fi

    box_rule
    echo ""

    while IFS= read -r line || [ -n "$line" ]; do
        if [ -z "$line" ]; then
            echo ""
        else
            printf '  %s\n' "$line"
        fi
    done < "$notice_file"

    echo ""
    box_rule
    echo ""
}

# Combined: show warning, then the acceptance line
show_startup_warnings() {
    show_alpha_warning

    box_rule
    echo ""
    printf '  By using this software, you acknowledge that you have\n'
    printf '  read and agree to the above terms.\n'
    echo ""
    box_rule
    echo ""
}

# Short reminder banner - shown before watchdog starts
show_alpha_warning_short() {
    local Y='\033[1;33m'  # bold yellow
    local R='\033[0m'     # reset

    echo ""
    printf "${Y}%s${R}\n" "$_HR"
    printf "${Y}  OPEN ALPHA: Decentralized P2P credit network. Active development.${R}\n"
    printf "${Y}%s${R}\n" "$_HR"

    # User information — uses variables set by startup.sh
    # shellcheck disable=SC2154  # variables set in parent script
    echo ""
    echo "  User Information:"
    if [ -n "${displayname:-}" ]; then
        echo -e "\t Display name: $displayname"
    fi
    if [ -n "${tor:-}" ]; then
        echo -e "\t Tor address: $tor"
    fi
    if [ -n "${http:-}" ]; then
        if [[ ${http} == https://* ]]; then
            local httpAddr="${http/https:\/\//http:\/\/}"
            local httpsAddr="$http"
        elif [[ ${http} == http://* ]]; then
            local httpAddr="$http"
            local httpsAddr="${http/http:\/\//https:\/\/}"
        else
            local httpAddr="http://$http"
            local httpsAddr="https://$http"
        fi
        local ADDR_WARN=""
        if [ "${EIOU_HOST:-false}" = "false" ] && [ "${QUICKSTART:-false}" != "false" ]; then
            ADDR_WARN="\033[33m⚠\033[0m "
        fi
        echo -e "\t HTTPS address: ${ADDR_WARN}$httpsAddr"
        echo -e "\t HTTP address:  ${ADDR_WARN}$httpAddr"
        if [ "${EIOU_HOST:-false}" = "false" ] && [ "${QUICKSTART:-false}" != "false" ]; then
            echo -e "\t \033[33m⚠ These addresses are Docker-internal only (resolved via Docker DNS)."
            echo -e "\t   They are not reachable from outside the Docker network."
            echo -e "\t   For external access, set EIOU_HOST to a real IP or domain and EIOU_PORT to the mapped port."
            if [ "${P2P_SSL_VERIFY:-}" != "false" ] && [ -z "${P2P_CA_CERT:-}" ]; then
                echo -e "\t   HTTPS between nodes will also fail — self-signed certs are rejected by default"
                echo -e "\t   (P2P_SSL_VERIFY=true). Set P2P_SSL_VERIFY=false or use P2P_CA_CERT with a shared CA."
            fi
            echo -e "\033[0m"
        fi
    fi
    if [ -n "${pubkey:-}" ]; then
        local readable="${pubkey//$'\n'/$'\n\t\t'}"
        echo -e "\t Public Key: \n\t\t $readable"
    fi
    if [ "${authcode_file:-}" = "tty" ]; then
        echo -e "\t Authentication Code: (displayed securely via terminal)"
    elif [[ "${authcode_file:-}" == seedfile:* ]]; then
        local seedfile_path="${authcode_file#seedfile:}"
        echo -e "\t Seedphrase & Auth Code: (stored in secure temp file)"
        echo -e "\t   View: docker exec \"$(hostname)\" cat \"$seedfile_path\""
        echo -e "\t   Auto-deletes in 15 minutes"
    elif [ -n "${authcode_file:-}" ]; then
        echo -e "\t Authentication Code: (stored in secure temp file)"
        echo -e "\t   View: docker exec \"$(hostname)\" cat \"$authcode_file\""
        echo -e "\t   Auto-deletes in 15 minutes"
    else
        echo -e "\t Authentication Code: (unavailable - see 'eiou info --show-auth')"
    fi

    # One-time analytics opt-in notice (shown until user makes a choice)
    local consent_asked
    consent_asked=$(php -r '$c = json_decode(@file_get_contents("/etc/eiou/config/defaultconfig.json"), true); echo ($c["analyticsConsentAsked"] ?? false) ? "true" : "false";' 2>/dev/null)
    if [ "$consent_asked" != "true" ]; then
        printf "\n${Y}%s${R}\n" "$_HR"
        printf "${Y}  Anonymous analytics available — help improve eIOU by${R}\n"
        printf "${Y}  sharing fully anonymous, non-sensitive usage statistics.${R}\n"
        printf "${Y}  Sent once per week through Tor. Your identity and${R}\n"
        printf "${Y}  transactions remain completely private.${R}\n"
        printf "${Y}${R}\n"
        printf "${Y}  To enable (to disable replace true with false):${R}\n"
        printf "${Y}  CLI: eiou changesettings analyticsEnabled true${R}\n"
        printf "${Y}  API: PUT /api/v1/system/settings${R}\n"
        printf "${Y}       {\"analytics_enabled\": true}${R}\n"
        printf "${Y}${R}\n"
        printf "${Y}  No action required — nothing is sent unless you opt in.${R}\n"
        printf "${Y}%s${R}\n" "$_HR"
    fi

    echo ""
}

# Prominent error banner for critical failures (e.g., invalid seedphrase)
show_error_banner() {
    local error_title="$1"
    local error_message="$2"
    echo ""
    box_rule
    echo ""
    printf '\033[0;31m  CRITICAL ERROR: %s\033[0m\n' "$error_title"
    echo ""
    if [ -n "$error_message" ]; then
        # Word wrap long error messages at 56 chars
        local current_line=""
        for word in $error_message; do
            if [ -z "$current_line" ]; then
                current_line="$word"
            elif [ $((${#current_line} + 1 + ${#word})) -le 56 ]; then
                current_line="$current_line $word"
            else
                printf '  %s\n' "$current_line"
                current_line="$word"
            fi
        done
        [ -n "$current_line" ] && printf '  %s\n' "$current_line"
        echo ""
    fi
    printf '  The container will now stop. Please check your\n'
    printf '  configuration and try again with a valid seedphrase.\n'
    echo ""
    box_rule
    echo ""
}
