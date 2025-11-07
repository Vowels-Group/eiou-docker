#!/bin/bash
#
# Docker XSS Protection Test
#
# This script tests XSS protection in the Docker environment.
# It verifies that the OutputEncoder class is working correctly.
#

set -e

echo "================================"
echo "XSS Protection Docker Test"
echo "================================"
echo

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if Docker is running
if ! docker ps >/dev/null 2>&1; then
    echo -e "${RED}Error: Docker is not running${NC}"
    exit 1
fi

echo -e "${YELLOW}Step 1: Starting Docker container (single node)${NC}"
cd /home/admin/eiou/ai-dev/github/eiou-docker
docker compose -f docker-compose-single.yml up -d --build

echo
echo -e "${YELLOW}Step 2: Waiting for container to initialize (10 seconds)${NC}"
sleep 10

echo
echo -e "${YELLOW}Step 3: Checking container status${NC}"
if ! docker compose -f docker-compose-single.yml ps | grep -q "Up"; then
    echo -e "${RED}Error: Container is not running${NC}"
    docker compose -f docker-compose-single.yml logs
    exit 1
fi
echo -e "${GREEN}✓ Container is running${NC}"

echo
echo -e "${YELLOW}Step 4: Verifying OutputEncoder class exists${NC}"
if docker compose -f docker-compose-single.yml exec -T alice test -f /etc/eiou/src/security/OutputEncoder.php; then
    echo -e "${GREEN}✓ OutputEncoder.php exists${NC}"
else
    echo -e "${RED}✗ OutputEncoder.php not found${NC}"
    exit 1
fi

echo
echo -e "${YELLOW}Step 5: Running XSS protection tests inside container${NC}"
docker compose -f docker-compose-single.yml exec -T alice php /etc/eiou/tests/security/test-xss-protection.php

TEST_EXIT_CODE=$?

echo
if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}================================${NC}"
    echo -e "${GREEN}✓ All XSS protection tests passed!${NC}"
    echo -e "${GREEN}================================${NC}"
else
    echo -e "${RED}================================${NC}"
    echo -e "${RED}✗ Some tests failed${NC}"
    echo -e "${RED}================================${NC}"
    exit 1
fi

echo
echo -e "${YELLOW}Step 6: Testing OutputEncoder in live environment${NC}"

# Test HTML encoding in actual page render
echo "Testing HTML encoding in wallet interface..."
docker compose -f docker-compose-single.yml exec -T alice php -r "
require_once '/etc/eiou/src/security/OutputEncoder.php';

// Test XSS payload
\$xssPayload = '<script>alert(\"XSS\")</script>';
\$encoded = OutputEncoder::html(\$xssPayload);

if (strpos(\$encoded, '<script') !== false) {
    echo 'FAIL: Script tag not encoded\n';
    exit(1);
}

echo 'PASS: XSS payload properly encoded\n';
echo 'Input: ' . \$xssPayload . '\n';
echo 'Output: ' . \$encoded . '\n';
"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Live encoding test passed${NC}"
else
    echo -e "${RED}✗ Live encoding test failed${NC}"
    exit 1
fi

echo
echo -e "${YELLOW}Step 7: Checking for XSS vulnerabilities in templates${NC}"

# Check for unsafe output patterns in HTML files
UNSAFE_PATTERNS=0

echo "Scanning HTML templates for unsafe patterns..."

# Look for unencoded echo statements (should have htmlspecialchars or OutputEncoder)
if docker compose -f docker-compose-single.yml exec -T alice grep -r "<?php echo \$" /etc/eiou/src/gui/layout/ 2>/dev/null | grep -v "htmlspecialchars" | grep -v "OutputEncoder" | grep -q .; then
    echo -e "${YELLOW}⚠ Warning: Found potentially unsafe echo statements${NC}"
    UNSAFE_PATTERNS=1
fi

if [ $UNSAFE_PATTERNS -eq 0 ]; then
    echo -e "${GREEN}✓ No obvious unsafe patterns found${NC}"
fi

echo
echo -e "${YELLOW}Step 8: Cleanup${NC}"
echo "Stopping Docker containers..."
docker compose -f docker-compose-single.yml down

echo
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}XSS Protection Test Complete!${NC}"
echo -e "${GREEN}================================${NC}"
echo
echo "Summary:"
echo "  - OutputEncoder class: ✓ Installed"
echo "  - Unit tests: ✓ Passed"
echo "  - Live encoding: ✓ Working"
echo "  - Template scan: ✓ Completed"
echo
echo "Next steps:"
echo "  1. Review docs/issue-146/XSS_PROTECTION.md"
echo "  2. Update remaining templates if needed"
echo "  3. Add XSS testing to CI/CD pipeline"

exit 0
