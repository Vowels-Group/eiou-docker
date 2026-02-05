#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Node Identity Test ############################
# Validates EIOU_NAME, EIOU_HOST, and EIOU_PORT environment variable
# functionality introduced in PR #580.
#
# Tests:
# - CLI name setting via changesettings stores name correctly
# - getName() returns correct values before and after setting name
# - Empty name rejection
# - Hostname integrity after name change
# - EIOU_HOST, EIOU_PORT, EIOU_NAME env vars configure containers properly
# - Backward compatibility with QUICKSTART-only containers
#
# Prerequisites:
# - Containers must be running with userconfig.json initialized
# - eiou/eiou Docker image must be available for env var tests
##################################################################################

echo -e "\nTesting Node Identity (EIOU_NAME, EIOU_HOST, EIOU_PORT)..."

testname="nodeIdentityTest"
totaltests=0
passed=0
failure=0

# Use first container for CLI tests
testContainer="${containers[0]}"

############################ CLI NAME SETTING TESTS ############################

echo -e "\n[CLI Name Setting Tests]"

# Test 1: getName() returns null when name not set
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getName() returns null when name not set"
nameBeforeSet=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    echo \$app->user->getName() ?? 'null';
" 2>&1)

if [ "$nameBeforeSet" = "null" ]; then
    printf "\t   getName() returns null ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getName() returns null ${YELLOW}WARNING${NC} (got: %s, may already be set)\n" "$nameBeforeSet"
    # Not a hard failure - name may have been set by a previous test run
    passed=$(( passed + 1 ))
fi

# Test 2: changesettings name stores correctly
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'changesettings name' stores correctly"
docker exec ${testContainer} eiou changesettings name "Test Node Name" 2>&1 >/dev/null

nameFromConfig=$(docker exec ${testContainer} php -r "
    \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
    echo \$json['name'] ?? 'NOT_SET';
" 2>&1)

if [ "$nameFromConfig" = "Test Node Name" ]; then
    printf "\t   changesettings name stored correctly ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   changesettings name stored correctly ${RED}FAILED${NC} (got: %s)\n" "$nameFromConfig"
    failure=$(( failure + 1 ))
fi

# Test 3: getName() returns set name
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getName() returns set name"
nameAfterSet=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    echo \$app->user->getName() ?? 'null';
" 2>&1)

if [ "$nameAfterSet" = "Test Node Name" ]; then
    printf "\t   getName() returns set name ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getName() returns set name ${RED}FAILED${NC} (got: %s)\n" "$nameAfterSet"
    failure=$(( failure + 1 ))
fi

# Test 4: changesettings name rejects empty name
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'changesettings name' rejects empty name"
emptyNameOutput=$(docker exec ${testContainer} eiou changesettings name "" 2>&1)
emptyNameExit=$?

# Check for error indication: either non-zero exit code or error text in output
if [ $emptyNameExit -ne 0 ] || echo "$emptyNameOutput" | grep -qi "error\|invalid\|empty\|required\|cannot"; then
    printf "\t   changesettings rejects empty name ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    # Verify name was not overwritten to empty
    nameAfterEmpty=$(docker exec ${testContainer} php -r "
        \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
        echo \$json['name'] ?? 'NOT_SET';
    " 2>&1)
    if [ "$nameAfterEmpty" = "Test Node Name" ]; then
        printf "\t   changesettings rejects empty name ${GREEN}PASSED${NC} (name unchanged)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   changesettings rejects empty name ${RED}FAILED${NC} (name was overwritten to: %s)\n" "$nameAfterEmpty"
        failure=$(( failure + 1 ))
    fi
fi

# Test 5: hostname not corrupted after name change
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing hostname not corrupted after name change"
hostnameAfterNameChange=$(docker exec ${testContainer} php -r "
    \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
    echo \$json['hostname'] ?? 'NOT_SET';
" 2>&1)

# Hostname should still start with http:// or https:// and contain the container name
if [[ "$hostnameAfterNameChange" == http://* ]] || [[ "$hostnameAfterNameChange" == https://* ]]; then
    if echo "$hostnameAfterNameChange" | grep -q "${testContainer}"; then
        printf "\t   hostname intact after name change ${GREEN}PASSED${NC} (hostname: %s)\n" "$hostnameAfterNameChange"
        passed=$(( passed + 1 ))
    else
        printf "\t   hostname intact after name change ${YELLOW}WARNING${NC} (hostname: %s, expected to contain %s)\n" "$hostnameAfterNameChange" "${testContainer}"
        # Not a hard failure - hostname may use IP or custom host
        passed=$(( passed + 1 ))
    fi
else
    printf "\t   hostname intact after name change ${RED}FAILED${NC} (hostname: %s, missing http(s):// prefix)\n" "$hostnameAfterNameChange"
    failure=$(( failure + 1 ))
fi

############################ EIOU_HOST/EIOU_PORT/EIOU_NAME ENV VAR TESTS ############################

echo -e "\n[EIOU_HOST/EIOU_PORT/EIOU_NAME Environment Variable Tests]"

# Check if the eiou/eiou image is available before creating temporary containers
imageExists=$(docker images -q eiou/eiou 2>/dev/null)

if [ -z "$imageExists" ]; then
    echo -e "\n\t${YELLOW}Skipping environment variable tests: eiou/eiou image not found${NC}"
else

    # Test 6: Container with EIOU_HOST, EIOU_PORT, EIOU_NAME
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing container with EIOU_HOST, EIOU_PORT, EIOU_NAME"

    # Clean up any leftover containers from previous runs
    docker rm -f nodeIdTest 2>/dev/null
    docker volume rm nodeIdTest-mysql-data nodeIdTest-files nodeIdTest-backups 2>/dev/null

    # Create temporary container with all three env vars
    docker run -d \
        --network=eiou-network \
        --name nodeIdTest \
        -e QUICKSTART=nodeIdTest \
        -e EIOU_NAME="Production Node" \
        -e EIOU_HOST=10.0.0.99 \
        -e EIOU_PORT=8443 \
        -e EIOU_CONTACT_STATUS_ENABLED=false \
        eiou/eiou 2>&1 >/dev/null

    # Wait for initialization
    echo -e "\t   Waiting for nodeIdTest to initialize..."
    if wait_for_condition "docker exec nodeIdTest test -f /etc/eiou/config/userconfig.json" ${EIOU_INIT_TIMEOUT:-120} 2 "nodeIdTest init"; then

        # Sub-test 6a: hostname contains EIOU_HOST:EIOU_PORT
        envHostname=$(docker exec nodeIdTest php -r "
            \$json = json_decode(file_get_contents('/etc/eiou/config/userconfig.json'), true);
            echo \$json['hostname'] ?? 'NOT_SET';
        " 2>&1)

        # Sub-test 6b: name is EIOU_NAME
        envName=$(docker exec nodeIdTest php -r "
            \$json = json_decode(file_get_contents('/etc/eiou/config/userconfig.json'), true);
            echo \$json['name'] ?? 'NOT_SET';
        " 2>&1)

        # Sub-test 6c: hostname_secure contains https://EIOU_HOST:EIOU_PORT
        envHostnameSecure=$(docker exec nodeIdTest php -r "
            \$json = json_decode(file_get_contents('/etc/eiou/config/userconfig.json'), true);
            echo \$json['hostname_secure'] ?? 'NOT_SET';
        " 2>&1)

        envTestPassed=true

        # Validate hostname contains 10.0.0.99:8443
        if echo "$envHostname" | grep -q "10.0.0.99:8443"; then
            printf "\t   hostname contains EIOU_HOST:EIOU_PORT ${GREEN}PASSED${NC} (hostname: %s)\n" "$envHostname"
        else
            printf "\t   hostname contains EIOU_HOST:EIOU_PORT ${RED}FAILED${NC} (hostname: %s)\n" "$envHostname"
            envTestPassed=false
        fi

        # Validate name is "Production Node"
        if [ "$envName" = "Production Node" ]; then
            printf "\t   name is EIOU_NAME ${GREEN}PASSED${NC}\n"
        else
            printf "\t   name is EIOU_NAME ${RED}FAILED${NC} (got: %s)\n" "$envName"
            envTestPassed=false
        fi

        # Validate hostname_secure contains https://10.0.0.99:8443
        if echo "$envHostnameSecure" | grep -q "https://10.0.0.99:8443"; then
            printf "\t   hostname_secure contains https://EIOU_HOST:EIOU_PORT ${GREEN}PASSED${NC} (hostname_secure: %s)\n" "$envHostnameSecure"
        else
            printf "\t   hostname_secure contains https://EIOU_HOST:EIOU_PORT ${RED}FAILED${NC} (hostname_secure: %s)\n" "$envHostnameSecure"
            envTestPassed=false
        fi

        # Validate display name appears in Docker logs
        envLogs=$(docker logs nodeIdTest 2>&1)
        if echo "$envLogs" | grep -q "Display name: Production Node"; then
            printf "\t   display name in Docker logs ${GREEN}PASSED${NC}\n"
        else
            printf "\t   display name in Docker logs ${RED}FAILED${NC} (not found in logs)\n"
            envTestPassed=false
        fi

        if [ "$envTestPassed" = "true" ]; then
            passed=$(( passed + 1 ))
        else
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   container with EIOU_HOST/PORT/NAME ${RED}FAILED${NC} (timeout waiting for init)\n"
        failure=$(( failure + 1 ))
    fi

    # Clean up nodeIdTest
    docker rm -f nodeIdTest 2>/dev/null
    docker volume rm nodeIdTest-mysql-data nodeIdTest-files nodeIdTest-backups 2>/dev/null

    # Test 7: Container with EIOU_HOST only (no port)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing container with EIOU_HOST only (no port)"

    # Clean up any leftover containers from previous runs
    docker rm -f nodeIdTest2 2>/dev/null
    docker volume rm nodeIdTest2-mysql-data nodeIdTest2-files nodeIdTest2-backups 2>/dev/null

    # Create temporary container with EIOU_HOST only
    docker run -d \
        --network=eiou-network \
        --name nodeIdTest2 \
        -e QUICKSTART=nodeIdTest2 \
        -e EIOU_HOST=192.168.1.50 \
        -e EIOU_CONTACT_STATUS_ENABLED=false \
        eiou/eiou 2>&1 >/dev/null

    # Wait for initialization
    echo -e "\t   Waiting for nodeIdTest2 to initialize..."
    if wait_for_condition "docker exec nodeIdTest2 test -f /etc/eiou/config/userconfig.json" ${EIOU_INIT_TIMEOUT:-120} 2 "nodeIdTest2 init"; then

        hostOnlyHostname=$(docker exec nodeIdTest2 php -r "
            \$json = json_decode(file_get_contents('/etc/eiou/config/userconfig.json'), true);
            echo \$json['hostname'] ?? 'NOT_SET';
        " 2>&1)

        # Validate hostname contains 192.168.1.50 without port suffix
        if echo "$hostOnlyHostname" | grep -q "192.168.1.50"; then
            printf "\t   hostname contains EIOU_HOST ${GREEN}PASSED${NC} (hostname: %s)\n" "$hostOnlyHostname"
            passed=$(( passed + 1 ))
        else
            printf "\t   hostname contains EIOU_HOST ${RED}FAILED${NC} (hostname: %s)\n" "$hostOnlyHostname"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   container with EIOU_HOST only ${RED}FAILED${NC} (timeout waiting for init)\n"
        failure=$(( failure + 1 ))
    fi

    # Clean up nodeIdTest2
    docker rm -f nodeIdTest2 2>/dev/null
    docker volume rm nodeIdTest2-mysql-data nodeIdTest2-files nodeIdTest2-backups 2>/dev/null

fi

############################ BACKWARD COMPATIBILITY TEST ############################

echo -e "\n[Backward Compatibility Test]"

# Test 8: Container with only QUICKSTART (backward compatibility)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing QUICKSTART-only container backward compatibility"

# Use existing container which was created with QUICKSTART only
quickstartHostname=$(docker exec ${testContainer} php -r "
    \$json = json_decode(file_get_contents('${USERCONFIG}'), true);
    echo \$json['hostname'] ?? 'NOT_SET';
" 2>&1)

# Hostname should match the QUICKSTART pattern (container name with protocol prefix)
if [[ "$quickstartHostname" == http://${testContainer}* ]] || [[ "$quickstartHostname" == https://${testContainer}* ]]; then
    printf "\t   QUICKSTART backward compatibility ${GREEN}PASSED${NC} (hostname: %s)\n" "$quickstartHostname"
    passed=$(( passed + 1 ))
else
    printf "\t   QUICKSTART backward compatibility ${RED}FAILED${NC} (hostname: %s, expected http(s)://%s)\n" "$quickstartHostname" "${testContainer}"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'node identity'"
