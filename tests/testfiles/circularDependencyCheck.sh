#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

############################ Circular Dependency Check ############################
# Static analysis tool to detect circular dependencies in PHP service files
#
# This script parses PHP service files for:
# - Constructor parameters (direct dependencies)
# - Setter injection methods (set*Service patterns for circular deps)
#
# It builds a dependency graph and uses DFS to detect cycles.
#
# Usage:
#   ./circularDependencyCheck.sh           # Normal output
#   ./circularDependencyCheck.sh --verbose # Detailed output with dependency graph
#
# Exit codes:
#   0 - No circular dependencies found
#   1 - Circular dependencies detected
##################################################################################

set -e

# Parse arguments
VERBOSE=false
SERVICES_DIR=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        --services-dir)
            SERVICES_DIR="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

# Determine script location and services directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -z "$SERVICES_DIR" ]; then
    # Default: look for services directory relative to eiou-docker root
    REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
    SERVICES_DIR="$REPO_ROOT/files/src/services"
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "Circular Dependency Check"
echo "========================="
echo ""

# Validate services directory exists
if [ ! -d "$SERVICES_DIR" ]; then
    echo -e "${RED}Error: Services directory not found: $SERVICES_DIR${NC}"
    exit 1
fi

echo "Analyzing files in $SERVICES_DIR..."
echo ""

# Temporary files for storing parsed data
DEPS_FILE=$(mktemp)
SETTER_DEPS_FILE=$(mktemp)
ALL_SERVICES_FILE=$(mktemp)
CYCLES_FILE=$(mktemp)

# Cleanup temp files on exit
cleanup() {
    rm -f "$DEPS_FILE" "$SETTER_DEPS_FILE" "$ALL_SERVICES_FILE" "$CYCLES_FILE"
}
trap cleanup EXIT

# Parse all PHP service files
parse_service_files() {
    local file
    for file in "$SERVICES_DIR"/*.php; do
        [ -f "$file" ] || continue

        local filename=$(basename "$file")
        local service_name="${filename%.php}"

        # Skip non-service files
        case "$service_name" in
            ServiceContainer|ServiceWrappers|*Interface|*Trait)
                continue
                ;;
        esac

        echo "$service_name" >> "$ALL_SERVICES_FILE"

        # Extract constructor dependencies (type-hinted parameters that are services)
        # Handle multi-line constructors by extracting content between __construct( and first {
        # Look for patterns like: ServiceName $serviceName or ?ServiceName $serviceName
        sed -n '/public function __construct/,/{/p' "$file" 2>/dev/null | \
            tr '\n' ' ' | \
            grep -oP '\??([\w]+Service)\s+\$\w+' 2>/dev/null | \
            sed 's/[?$].*//g; s/\s*$//' | \
            while read -r dep; do
                # Normalize interface names to service names
                dep="${dep%Interface}"
                if [ -n "$dep" ] && [ "$dep" != "$service_name" ]; then
                    echo "$service_name -> $dep (constructor)" >> "$DEPS_FILE"
                fi
            done

        # Extract setter injection dependencies (set*Service methods)
        # These indicate potential circular dependencies
        grep -oP 'public function set\w+Service\([^)]*\)' "$file" 2>/dev/null | \
            grep -oP '\(\s*\??([\w]+Service(?:Interface)?)\s+\$' 2>/dev/null | \
            sed 's/[()?$]//g; s/\s*$//' | \
            while read -r dep; do
                # Normalize interface names to service names
                dep="${dep%Interface}"
                if [ -n "$dep" ] && [ "$dep" != "$service_name" ]; then
                    echo "$service_name -> $dep (setter)" >> "$SETTER_DEPS_FILE"
                fi
            done
    done
}

# Build adjacency list from dependencies
declare -A ADJACENCY_LIST
declare -A REVERSE_ADJACENCY_LIST

build_graph() {
    local line from to type

    # Combine constructor and setter dependencies for cycle detection
    # Setter dependencies are the ones that indicate circular patterns
    cat "$DEPS_FILE" "$SETTER_DEPS_FILE" 2>/dev/null | while read -r line; do
        from=$(echo "$line" | awk '{print $1}')
        to=$(echo "$line" | awk '{print $3}')
        if [ -n "$from" ] && [ -n "$to" ]; then
            echo "$from:$to"
        fi
    done | sort -u
}

# Detect cycles using DFS
detect_cycles() {
    local edges_input="$1"

    # Build adjacency list in awk for cycle detection
    echo "$edges_input" | awk '
    BEGIN {
        # Initialize
        cycle_count = 0
    }

    {
        # Parse edge format "from:to"
        split($0, parts, ":")
        from = parts[1]
        to = parts[2]

        # Build adjacency list
        if (!(from in adj_count)) {
            adj_count[from] = 0
            nodes[from] = 1
        }
        if (!(to in nodes)) {
            nodes[to] = 1
            adj_count[to] = 0
        }

        adj[from, adj_count[from]] = to
        adj_count[from]++
    }

    END {
        # DFS to find cycles
        for (node in nodes) {
            # Reset visited state for each starting node
            delete visited
            delete rec_stack
            delete path
            path_len = 0

            # Run DFS from this node
            result = dfs(node, visited, rec_stack, path, path_len, adj, adj_count, cycles, cycle_count)
            if (result != "") {
                # Found a cycle starting from this node
            }
        }

        # Print unique cycles
        for (c in cycles) {
            print cycles[c]
        }
    }

    function dfs(node, visited, rec_stack, path, path_len, adj, adj_count, cycles, cycle_count,    i, neighbor, cycle_str, j, cycle_start, normalized) {
        visited[node] = 1
        rec_stack[node] = 1
        path[path_len] = node
        path_len++

        for (i = 0; i < adj_count[node]; i++) {
            neighbor = adj[node, i]

            if (!(neighbor in visited)) {
                dfs(neighbor, visited, rec_stack, path, path_len, adj, adj_count, cycles, cycle_count)
            } else if (neighbor in rec_stack) {
                # Found cycle - extract it
                cycle_start = -1
                for (j = 0; j < path_len; j++) {
                    if (path[j] == neighbor) {
                        cycle_start = j
                        break
                    }
                }

                if (cycle_start >= 0) {
                    # Build cycle string
                    cycle_str = ""
                    for (j = cycle_start; j < path_len; j++) {
                        if (cycle_str != "") cycle_str = cycle_str " -> "
                        cycle_str = cycle_str path[j]
                    }
                    cycle_str = cycle_str " -> " neighbor

                    # Normalize cycle to start with smallest node name
                    normalized = normalize_cycle(cycle_str)
                    if (!(normalized in seen_cycles)) {
                        cycle_count++
                        cycles[cycle_count] = cycle_str
                        seen_cycles[normalized] = 1
                    }
                }
            }
        }

        delete rec_stack[node]
        path_len--
        return ""
    }

    function normalize_cycle(cycle,    parts, n, min_idx, i, result, j) {
        # Split cycle into nodes and find smallest to use as start
        gsub(/ -> /, "\n", cycle)
        n = split(cycle, parts, "\n")

        # Remove last element (duplicate of first in cycle)
        n = n - 1

        min_idx = 1
        for (i = 2; i <= n; i++) {
            if (parts[i] < parts[min_idx]) {
                min_idx = i
            }
        }

        # Rebuild starting from min
        result = ""
        for (i = 0; i < n; i++) {
            j = ((min_idx - 1 + i) % n) + 1
            if (result != "") result = result ":"
            result = result parts[j]
        }
        return result
    }
    '
}

# Main execution
echo "Parsing service files..."
parse_service_files

service_count=$(wc -l < "$ALL_SERVICES_FILE" 2>/dev/null || echo 0)
echo "Found $service_count service files"
echo ""

if [ "$VERBOSE" = true ]; then
    echo -e "${BLUE}Constructor Dependencies:${NC}"
    if [ -s "$DEPS_FILE" ]; then
        cat "$DEPS_FILE" | sed 's/^/  /'
    else
        echo "  (none found)"
    fi
    echo ""

    echo -e "${YELLOW}Setter Injection Dependencies (potential circular):${NC}"
    if [ -s "$SETTER_DEPS_FILE" ]; then
        cat "$SETTER_DEPS_FILE" | sed 's/^/  /'
    else
        echo "  (none found)"
    fi
    echo ""
fi

# Build and analyze dependency graph
echo "Building dependency graph..."
edges=$(build_graph)

if [ "$VERBOSE" = true ]; then
    echo -e "${BLUE}Dependency Graph Edges:${NC}"
    echo "$edges" | sed 's/:/ -> /g; s/^/  /'
    echo ""
fi

echo "Detecting cycles..."
echo ""

# Detect cycles
cycles=$(detect_cycles "$edges")

if [ -z "$cycles" ]; then
    echo -e "${GREEN}No circular dependencies detected.${NC}"
    echo -e "${GREEN}PASS${NC}"
    exit 0
else
    # Count and display cycles
    cycle_count=$(echo "$cycles" | grep -c '^' || echo 0)

    echo -e "${RED}Found $cycle_count circular dependency chain(s):${NC}"
    echo ""

    i=1
    echo "$cycles" | while read -r cycle; do
        if [ -n "$cycle" ]; then
            echo "  $i. $cycle"
            i=$((i + 1))
        fi
    done

    echo ""
    echo -e "${YELLOW}Note: These are managed via setter injection to break the cycle.${NC}"
    echo -e "${YELLOW}      See ServiceContainer::wireCircularDependencies() for the wiring logic.${NC}"
    echo ""
    echo -e "${RED}FAIL: Circular dependencies detected${NC}"
    exit 1
fi
