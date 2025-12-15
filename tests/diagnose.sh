#!/bin/bash
# Copyright 2025
# Diagnostic helper for debugging test failures
# Usage: ./diagnose.sh [container_name]

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

container="${1:-}"

print_section() {
    echo ""
    echo -e "${BLUE}=== $1 ===${NC}"
}

# If no container specified, show all test containers
if [[ -z "$container" ]]; then
    print_section "Available Test Containers"
    docker ps -a --filter "name=http" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

    echo ""
    echo "Usage: $0 <container_name>"
    echo "Example: $0 httpA"
    exit 0
fi

# Check if container exists
if ! docker ps -a --format '{{.Names}}' | grep -q "^${container}$"; then
    echo -e "${RED}Container '$container' not found${NC}"
    exit 1
fi

print_section "Container State: $container"
docker inspect "$container" --format='
  ID:         {{.Id}}
  Name:       {{.Name}}
  Status:     {{.State.Status}}
  Running:    {{.State.Running}}
  ExitCode:   {{.State.ExitCode}}
  StartedAt:  {{.State.StartedAt}}
  Health:     {{if .State.Health}}{{.State.Health.Status}}{{else}}N/A{{end}}
'

print_section "Network Configuration"
docker inspect "$container" --format='
  Network Mode: {{.HostConfig.NetworkMode}}
  IP Address:   {{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}
  Ports:        {{json .NetworkSettings.Ports}}
'

print_section "Environment Variables (EIOU related)"
docker exec "$container" env 2>/dev/null | grep -E "^(QUICKSTART|RESTORE|EIOU|TOR)" || echo "  No EIOU environment variables found"

print_section "File System Check"
echo "  /etc/eiou/userconfig.json: $(docker exec "$container" test -f /etc/eiou/userconfig.json 2>/dev/null && echo 'EXISTS' || echo 'NOT FOUND')"
echo "  /etc/eiou/dbconfig.json:   $(docker exec "$container" test -f /etc/eiou/dbconfig.json 2>/dev/null && echo 'EXISTS' || echo 'NOT FOUND')"
echo "  /etc/eiou/.master.key:     $(docker exec "$container" test -f /etc/eiou/.master.key 2>/dev/null && echo 'EXISTS' || echo 'NOT FOUND')"

print_section "Service Status"
echo "  Apache: $(docker exec "$container" service apache2 status 2>/dev/null | head -1 || echo 'Unable to check')"
echo "  MariaDB: $(docker exec "$container" service mariadb status 2>/dev/null | head -1 || echo 'Unable to check')"
echo "  Tor: $(docker exec "$container" service tor status 2>/dev/null | head -1 || echo 'Unable to check')"

print_section "Database Connectivity"
docker exec "$container" bash -c 'mysqladmin ping -h localhost --silent 2>/dev/null && echo "  MySQL: Connected" || echo "  MySQL: NOT responding"'

print_section "PHP Configuration"
docker exec "$container" php -v 2>/dev/null | head -1 || echo "  PHP not available"

print_section "Container Logs (last 30 lines)"
docker logs "$container" 2>&1 | tail -30

print_section "PHP Error Log (last 20 lines)"
docker exec "$container" tail -20 /var/log/php_errors.log 2>/dev/null || echo "  No PHP errors or log not accessible"

print_section "Apache Error Log (last 20 lines)"
docker exec "$container" tail -20 /var/log/apache2/error.log 2>/dev/null || echo "  No Apache errors or log not accessible"

print_section "Network Connectivity Tests"
echo "  DNS Resolution: $(docker exec "$container" nslookup google.com 2>/dev/null | grep -q 'Address:' && echo 'OK' || echo 'FAILED')"
echo "  External HTTP:  $(docker exec "$container" curl -s --connect-timeout 5 -o /dev/null -w '%{http_code}' http://httpbin.org/get 2>/dev/null || echo 'FAILED')"

print_section "Process List"
docker exec "$container" ps aux 2>/dev/null | head -15 || echo "  Unable to list processes"

print_section "Disk Usage"
docker exec "$container" df -h / 2>/dev/null | head -3 || echo "  Unable to check disk"

echo ""
echo -e "${GREEN}Diagnosis complete for container: $container${NC}"
echo ""
echo "For more detailed logs, run:"
echo "  docker logs $container"
echo "  docker exec -it $container bash"
