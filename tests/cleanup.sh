#!/bin/bash
# Copyright 2025
# Comprehensive cleanup script for test environment
# Removes test containers, volumes, networks, and temporary files

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Default options
FORCE=false
KEEP_IMAGES=false
VERBOSE=false

# Usage
usage() {
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -f, --force       Skip confirmation prompts"
    echo "  -i, --keep-images Keep Docker images (only remove containers)"
    echo "  -v, --verbose     Verbose output"
    echo "  -h, --help        Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                 # Interactive cleanup"
    echo "  $0 -f              # Force cleanup without prompts"
    echo "  $0 -f -i           # Force cleanup but keep images"
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        -f|--force)
            FORCE=true
            shift
            ;;
        -i|--keep-images)
            KEEP_IMAGES=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

log() {
    echo -e "$1"
}

log_verbose() {
    if [[ "$VERBOSE" == true ]]; then
        echo -e "  $1"
    fi
}

confirm() {
    if [[ "$FORCE" == true ]]; then
        return 0
    fi

    read -p "$1 [y/N] " -n 1 -r
    echo
    [[ $REPLY =~ ^[Yy]$ ]]
}

# Count resources before cleanup
count_resources() {
    CONTAINER_COUNT=$(docker ps -a --filter "name=http" --format "{{.Names}}" 2>/dev/null | wc -l)
    VOLUME_COUNT=$(docker volume ls --filter "name=http" --format "{{.Name}}" 2>/dev/null | wc -l)
    NETWORK_EXISTS=$(docker network ls --filter "name=eioud-network" --format "{{.Name}}" 2>/dev/null | wc -l)
}

# Remove test containers
remove_containers() {
    log "${YELLOW}Removing test containers...${NC}"

    local containers=$(docker ps -a --filter "name=http" --format "{{.Names}}" 2>/dev/null)

    if [[ -z "$containers" ]]; then
        log "  No test containers found"
        return 0
    fi

    for container in $containers; do
        log_verbose "Removing container: $container"
        docker rm -f "$container" 2>/dev/null || true
    done

    log -e "  ${GREEN}Removed $(echo "$containers" | wc -w) container(s)${NC}"
}

# Remove test volumes
remove_volumes() {
    log "${YELLOW}Removing test volumes...${NC}"

    local volumes=$(docker volume ls --filter "name=http" --format "{{.Name}}" 2>/dev/null)

    if [[ -z "$volumes" ]]; then
        log "  No test volumes found"
        return 0
    fi

    for volume in $volumes; do
        log_verbose "Removing volume: $volume"
        docker volume rm "$volume" 2>/dev/null || true
    done

    log -e "  ${GREEN}Removed $(echo "$volumes" | wc -w) volume(s)${NC}"
}

# Remove test network
remove_network() {
    log "${YELLOW}Removing test network...${NC}"

    if docker network ls --filter "name=eioud-network" --format "{{.Name}}" | grep -q "eioud-network"; then
        docker network rm eioud-network 2>/dev/null || true
        log -e "  ${GREEN}Removed 'eioud-network'${NC}"
    else
        log "  Network 'eioud-network' not found"
    fi
}

# Remove test images (optional)
remove_images() {
    if [[ "$KEEP_IMAGES" == true ]]; then
        log "${YELLOW}Keeping test images (--keep-images flag)${NC}"
        return 0
    fi

    log "${YELLOW}Removing test images...${NC}"

    # Remove eioud images (tagged with http prefix)
    local images=$(docker images --filter "reference=eioud*" --format "{{.Repository}}:{{.Tag}}" 2>/dev/null)

    if [[ -z "$images" ]]; then
        log "  No test images found"
        return 0
    fi

    for image in $images; do
        log_verbose "Removing image: $image"
        docker rmi "$image" 2>/dev/null || true
    done

    log -e "  ${GREEN}Removed $(echo "$images" | wc -w) image(s)${NC}"
}

# Clean up temporary files
cleanup_temp_files() {
    log "${YELLOW}Cleaning up temporary files...${NC}"

    # Remove test log directories older than 1 day
    find /tmp -maxdepth 1 -name "eiou-test-logs-*" -type d -mtime +1 -exec rm -rf {} \; 2>/dev/null || true

    # Count remaining log directories
    local log_dirs=$(find /tmp -maxdepth 1 -name "eiou-test-logs-*" -type d 2>/dev/null | wc -l)
    log "  Kept $log_dirs recent log director(y/ies)"
}

# Prune Docker system
prune_docker() {
    log "${YELLOW}Pruning Docker system...${NC}"

    # Prune unused containers
    docker container prune -f 2>/dev/null || true

    # Prune unused volumes
    docker volume prune -f 2>/dev/null || true

    # Prune unused networks
    docker network prune -f 2>/dev/null || true

    log -e "  ${GREEN}Docker system pruned${NC}"
}

# Main cleanup function
main() {
    echo "================================================================"
    echo "     EIOU Docker Test Cleanup"
    echo "================================================================"
    echo ""

    # Count resources
    count_resources

    echo "Resources to clean up:"
    echo "  Containers: $CONTAINER_COUNT"
    echo "  Volumes:    $VOLUME_COUNT"
    echo "  Network:    $([ $NETWORK_EXISTS -gt 0 ] && echo 'Yes' || echo 'No')"
    echo ""

    if [[ $CONTAINER_COUNT -eq 0 ]] && [[ $VOLUME_COUNT -eq 0 ]] && [[ $NETWORK_EXISTS -eq 0 ]]; then
        log -e "${GREEN}Nothing to clean up!${NC}"
        exit 0
    fi

    if ! confirm "Proceed with cleanup?"; then
        log "Cleanup cancelled"
        exit 0
    fi

    echo ""

    # Perform cleanup
    remove_containers
    remove_volumes
    remove_network
    remove_images
    cleanup_temp_files

    if confirm "Also prune unused Docker resources?"; then
        prune_docker
    fi

    echo ""
    echo "================================================================"
    log -e "${GREEN}Cleanup complete!${NC}"
    echo "================================================================"
}

# Run main
main
