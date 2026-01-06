#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

# Test SSL certificate generation and HTTPS functionality
# Verifies that self-signed SSL certificates are properly generated
# and that HTTPS endpoints are accessible
#
# NOTE: All paths use double slashes (//etc/eiou/) to prevent Git Bash on Windows
# from converting /etc/ to C:/Program Files/Git/etc/. This is safe on Linux too.

echo -e "\nTesting SSL certificate and HTTPS functionality..."

testname="sslCertificateTest"
totaltests=0
passed=0
failure=0

# Define SSL paths with double slashes for Windows Git Bash compatibility
SSL_DIR="//etc//apache2//ssl"
SSL_CERT="${SSL_DIR}//server.crt"
SSL_KEY="${SSL_DIR}//server.key"

############################ TEST SSL CERTIFICATE EXISTS ############################

echo -e "\n[SSL Certificate File Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking SSL certificate files exist on ${container}"

    certExists=$(docker exec ${container} test -f ${SSL_CERT} && echo "EXISTS" || echo "NOT_FOUND")
    keyExists=$(docker exec ${container} test -f ${SSL_KEY} && echo "EXISTS" || echo "NOT_FOUND")

    if [[ "$certExists" == "EXISTS" ]] && [[ "$keyExists" == "EXISTS" ]]; then
        printf "\t   SSL certificate and key files exist ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   SSL certificate files ${RED}FAILED${NC}\n"
        printf "\t   Certificate: ${certExists}, Key: ${keyExists}\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST SSL CERTIFICATE PERMISSIONS ############################

echo -e "\n[SSL File Permission Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking SSL file permissions on ${container}"

    # Check key file permissions (should be 600 - owner read/write only)
    keyPerms=$(docker exec ${container} stat -c '%a' ${SSL_KEY} 2>/dev/null)
    # Check cert file permissions (should be 644 - owner read/write, others read)
    certPerms=$(docker exec ${container} stat -c '%a' ${SSL_CERT} 2>/dev/null)

    if [[ "$keyPerms" == "600" ]] && [[ "$certPerms" == "644" ]]; then
        printf "\t   SSL file permissions correct ${GREEN}PASSED${NC}\n"
        printf "\t   Key: ${keyPerms} (expected 600), Cert: ${certPerms} (expected 644)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   SSL file permissions ${RED}FAILED${NC}\n"
        printf "\t   Key: ${keyPerms} (expected 600), Cert: ${certPerms} (expected 644)\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST SSL CERTIFICATE VALIDITY ############################

echo -e "\n[SSL Certificate Validity Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking SSL certificate validity on ${container}"

    # Use openssl to verify certificate is valid and not expired
    # Redirect stdout to /dev/null to suppress "Certificate will not expire" message
    docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -checkend 0 >/dev/null 2>&1
    certExitCode=$?

    if [[ "$certExitCode" -eq 0 ]]; then
        # Get certificate expiry date
        expiryDate=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -enddate 2>/dev/null | cut -d= -f2)
        printf "\t   SSL certificate is valid ${GREEN}PASSED${NC}\n"
        printf "\t   Expires: ${expiryDate}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   SSL certificate validity ${RED}FAILED${NC}\n"
        printf "\t   Certificate is expired or invalid\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST HTTPS ENDPOINT ############################

echo -e "\n[HTTPS Endpoint Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing HTTPS endpoint on ${container}"

    # Test HTTPS endpoint returns 200 (using -k to accept self-signed cert)
    httpCode=$(docker exec ${container} curl -k -s -o /dev/null -w "%{http_code}" https://localhost/ 2>/dev/null)

    if [[ "$httpCode" == "200" ]]; then
        printf "\t   HTTPS endpoint accessible ${GREEN}PASSED${NC}\n"
        printf "\t   HTTP Status: ${httpCode}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   HTTPS endpoint ${RED}FAILED${NC}\n"
        printf "\t   HTTP Status: ${httpCode} (expected 200)\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST HTTPS API ENDPOINT ############################

echo -e "\n[HTTPS API Endpoint Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing HTTPS API endpoint on ${container}"

    # Test HTTPS API endpoint responds with JSON
    apiResponse=$(docker exec ${container} curl -k -s https://localhost/api/ping 2>/dev/null)

    # Check if response contains JSON structure
    if echo "$apiResponse" | grep -q '"success"'; then
        printf "\t   HTTPS API endpoint responding ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   HTTPS API endpoint ${RED}FAILED${NC}\n"
        printf "\t   Response: ${apiResponse}\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST SSL MODULE ENABLED ############################

echo -e "\n[Apache SSL Module Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking Apache SSL module enabled on ${container}"

    # Check if mod_ssl is loaded
    sslModLoaded=$(docker exec ${container} apache2ctl -M 2>/dev/null | grep -c "ssl_module" || echo "0")

    if [[ "$sslModLoaded" -ge "1" ]]; then
        printf "\t   Apache SSL module enabled ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Apache SSL module ${RED}FAILED${NC}\n"
        printf "\t   mod_ssl not loaded\n"
        failure=$(( failure + 1 ))
    fi
done

############################ TEST PORT 443 LISTENING ############################

echo -e "\n[Port 443 Listening Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking port 443 is listening on ${container}"

    # Check if Apache is listening on port 443
    port443Listening=$(docker exec ${container} ss -tlnp 2>/dev/null | grep -c ":443" || echo "0")

    if [[ "$port443Listening" -ge "1" ]]; then
        printf "\t   Port 443 listening ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Port 443 ${RED}FAILED${NC}\n"
        printf "\t   Port 443 not listening\n"
        failure=$(( failure + 1 ))
    fi
done

##################################################################

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'SSL certificate'"
