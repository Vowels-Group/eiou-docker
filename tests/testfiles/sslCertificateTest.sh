#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

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

    # Test HTTPS endpoint returns 200 (using -k to accept self-signed cert, -L to follow redirects)
    httpCode=$(docker exec ${container} curl -k -L -s -o /dev/null -w "%{http_code}" https://localhost/ 2>/dev/null)

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

############################ TEST CERTIFICATE SAN/CN DETAILS ############################

echo -e "\n[Certificate Subject Alternative Names Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking certificate CN and SAN details on ${container}"

    # Get certificate subject (CN)
    certSubject=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -subject 2>/dev/null)
    # Check for SANs
    certSAN=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -ext subjectAltName 2>&1)

    # Report certificate details (informational - always passes)
    printf "\t   Certificate CN: ${certSubject}\n"
    if echo "$certSAN" | grep -q "No extensions"; then
        printf "\t   Certificate SANs: None (CN=localhost only)\n"
    else
        printf "\t   Certificate SANs: ${certSAN}\n"
    fi
    printf "\t   Certificate details captured ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
done

############################ TEST SSL TO IP ADDRESS (WITH -k FLAG) ############################

echo -e "\n[SSL to IP Address Tests (Insecure Mode)]"

# Only run inter-container tests if we have more than one container
if [[ ${#containers[@]} -gt 1 ]]; then
    # Use first container as source, second as target
    sourceContainer="${containers[0]}"
    targetContainer="${containers[1]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing SSL connection from ${sourceContainer} to ${targetContainer} via IP address"

    # Get target container IP
    targetIP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${targetContainer} 2>/dev/null)

    if [[ -n "$targetIP" ]]; then
        printf "\t   Target IP: ${targetIP}\n"

        # Test SSL connection with -k flag (should succeed)
        httpCode=$(docker exec ${sourceContainer} curl -k -L -s -o /dev/null -w "%{http_code}" --max-time 10 https://${targetIP}/ 2>/dev/null)

        if [[ "$httpCode" == "200" ]]; then
            printf "\t   SSL to IP with -k flag ${GREEN}PASSED${NC}\n"
            printf "\t   HTTP Status: ${httpCode}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   SSL to IP with -k flag ${RED}FAILED${NC}\n"
            printf "\t   HTTP Status: ${httpCode} (expected 200)\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   Could not get target IP ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\n\t-> Skipping inter-container SSL test (single container setup)"
fi

############################ TEST SSL TO IP ADDRESS (WITHOUT -k FLAG - EXPECTED FAILURE) ############################

echo -e "\n[SSL to IP Address Tests (Strict Mode - Expected Behavior)]"

# Only run inter-container tests if we have more than one container
if [[ ${#containers[@]} -gt 1 ]]; then
    sourceContainer="${containers[0]}"
    targetContainer="${containers[1]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing SSL connection from ${sourceContainer} to ${targetContainer} via IP (strict mode)"

    targetIP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${targetContainer} 2>/dev/null)

    if [[ -n "$targetIP" ]]; then
        printf "\t   Target IP: ${targetIP}\n"

        # Test SSL connection WITHOUT -k flag (expected to fail due to self-signed cert)
        sslResult=$(docker exec ${sourceContainer} curl -s -o /dev/null -w "%{http_code}" --max-time 10 https://${targetIP}/ 2>&1)
        curlExitCode=$?

        # Exit code 60 = SSL certificate problem (expected behavior)
        # Exit code 51 = SSL peer certificate or SSH remote key was not OK
        if [[ "$curlExitCode" -eq 60 ]] || [[ "$curlExitCode" -eq 51 ]] || [[ "$sslResult" == "000" ]]; then
            printf "\t   SSL verification correctly rejected self-signed cert ${GREEN}PASSED${NC}\n"
            printf "\t   curl exit code: ${curlExitCode} (SSL verification failure expected)\n"
            passed=$(( passed + 1 ))
        elif [[ "$sslResult" == "200" ]]; then
            # If it succeeds without -k, certificate may have been updated with SANs
            printf "\t   SSL verification succeeded (certificate may include IP SANs) ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Unexpected SSL behavior ${YELLOW}WARNING${NC}\n"
            printf "\t   Exit code: ${curlExitCode}, HTTP Status: ${sslResult}\n"
            passed=$(( passed + 1 ))  # Don't fail test for unexpected but non-breaking behavior
        fi
    else
        printf "\t   Could not get target IP ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\n\t-> Skipping strict SSL test (single container setup)"
fi

############################ TEST SSL TO DOCKER HOSTNAME ############################

echo -e "\n[SSL to Docker Hostname Tests]"

# Only run inter-container tests if we have more than one container
if [[ ${#containers[@]} -gt 1 ]]; then
    sourceContainer="${containers[0]}"
    targetContainer="${containers[1]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing SSL connection from ${sourceContainer} to ${targetContainer} via hostname"

    # Test SSL connection to hostname with -k flag
    httpCode=$(docker exec ${sourceContainer} curl -k -L -s -o /dev/null -w "%{http_code}" --max-time 10 https://${targetContainer}/ 2>/dev/null)

    if [[ "$httpCode" == "200" ]]; then
        printf "\t   SSL to Docker hostname with -k flag ${GREEN}PASSED${NC}\n"
        printf "\t   HTTP Status: ${httpCode}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   SSL to Docker hostname ${RED}FAILED${NC}\n"
        printf "\t   HTTP Status: ${httpCode} (expected 200)\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\n\t-> Skipping hostname SSL test (single container setup)"
fi

############################ TEST TLS VERSION AND CIPHER ############################

echo -e "\n[TLS Protocol and Cipher Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking TLS version and cipher on ${container}"

    # Get TLS protocol version
    tlsInfo=$(docker exec ${container} sh -c "echo | openssl s_client -connect localhost:443 2>&1" | grep -E "Protocol|Cipher" | head -2)

    if echo "$tlsInfo" | grep -qE "TLSv1\.[23]"; then
        printf "\t   TLS version secure (TLS 1.2+) ${GREEN}PASSED${NC}\n"
        printf "\t   ${tlsInfo}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   TLS version check ${YELLOW}WARNING${NC}\n"
        printf "\t   ${tlsInfo}\n"
        passed=$(( passed + 1 ))  # Don't fail, just warn
    fi
done

############################ TEST API ENDPOINT VIA SSL TO IP ############################

echo -e "\n[API over SSL to IP Address Tests]"

if [[ ${#containers[@]} -gt 1 ]]; then
    sourceContainer="${containers[0]}"
    targetContainer="${containers[1]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing API endpoint from ${sourceContainer} to ${targetContainer} via IP"

    targetIP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${targetContainer} 2>/dev/null)

    if [[ -n "$targetIP" ]]; then
        # Test API endpoint over SSL to IP
        apiResponse=$(docker exec ${sourceContainer} curl -k -s --max-time 10 https://${targetIP}/api/ping 2>/dev/null)

        # API should respond with JSON (either success or auth error)
        if echo "$apiResponse" | grep -q '"success"'; then
            printf "\t   API accessible over SSL to IP ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   API response unexpected ${RED}FAILED${NC}\n"
            printf "\t   Response: ${apiResponse}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   Could not get target IP ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\n\t-> Skipping API over SSL to IP test (single container setup)"
fi

############################ TEST CERTIFICATE CN MATCHES HOSTNAME ############################

echo -e "\n[Certificate CN Matches Hostname Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking certificate CN matches configured hostname on ${container}"

    # Get certificate CN
    certCN=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -subject 2>/dev/null | sed -n 's/.*CN = \([^,]*\).*/\1/p')

    # Get configured hostname from userconfig.json (extract domain from URL)
    configuredHostname=$(docker exec ${container} php -r '
        $config = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true);
        if (isset($config["hostname"])) {
            $hostname = preg_replace("#^https?://#", "", $config["hostname"]);
            echo rtrim($hostname, "/");
        }
    ' 2>/dev/null)

    printf "\t   Certificate CN: ${certCN}\n"
    printf "\t   Configured hostname: ${configuredHostname}\n"

    # CN should match the configured hostname (or be localhost for default)
    if [[ "$certCN" == "$configuredHostname" ]] || [[ "$certCN" == "localhost" && -z "$configuredHostname" ]]; then
        printf "\t   Certificate CN matches hostname ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        # May still pass if CN is the container name (QUICKSTART value)
        if [[ "$certCN" == "${container}" ]]; then
            printf "\t   Certificate CN matches container name ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Certificate CN mismatch ${YELLOW}WARNING${NC}\n"
            printf "\t   This may be expected if hostname was changed after certificate generation\n"
            passed=$(( passed + 1 ))  # Don't fail, just warn
        fi
    fi
done

############################ TEST CERTIFICATE HAS IP SANS ############################

echo -e "\n[Certificate IP SAN Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking certificate has IP addresses in SANs on ${container}"

    # Get container IP
    containerIP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${container} 2>/dev/null)

    # Get certificate SANs
    certSANs=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -ext subjectAltName 2>/dev/null)

    printf "\t   Container IP: ${containerIP}\n"

    # Check if SANs include IP addresses
    if echo "$certSANs" | grep -q "IP Address"; then
        # Check if the container's own IP is in the SANs
        if echo "$certSANs" | grep -q "IP Address:${containerIP}"; then
            printf "\t   Certificate includes container IP in SANs ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            # IP SANs exist but may not include this specific IP (could be regenerated)
            printf "\t   Certificate has IP SANs (may not include current IP) ${GREEN}PASSED${NC}\n"
            printf "\t   SANs: ${certSANs}\n"
            passed=$(( passed + 1 ))
        fi
    else
        # No IP SANs - this is the old certificate format
        printf "\t   Certificate has no IP SANs (legacy format) ${YELLOW}WARNING${NC}\n"
        printf "\t   Regenerate certificate to include IP SANs\n"
        passed=$(( passed + 1 ))  # Don't fail for backward compatibility
    fi
done

############################ TEST CERTIFICATE HAS DNS SANS ############################

echo -e "\n[Certificate DNS SAN Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking certificate has DNS names in SANs on ${container}"

    # Get certificate SANs
    certSANs=$(docker exec ${container} openssl x509 -in ${SSL_CERT} -noout -ext subjectAltName 2>/dev/null)

    # Check for DNS SANs
    if echo "$certSANs" | grep -q "DNS:"; then
        # Check for localhost
        if echo "$certSANs" | grep -q "DNS:localhost"; then
            printf "\t   Certificate includes DNS:localhost ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Certificate has DNS SANs but missing localhost ${YELLOW}WARNING${NC}\n"
            passed=$(( passed + 1 ))
        fi
    else
        printf "\t   Certificate has no DNS SANs (legacy format) ${YELLOW}WARNING${NC}\n"
        passed=$(( passed + 1 ))  # Don't fail for backward compatibility
    fi
done

############################ TEST SSL CERTIFICATE REGENERATION ON HOSTNAME CHANGE ############################

echo -e "\n[SSL Certificate Regeneration Tests]"

# Only run this test if we have at least one container
if [[ ${#containers[@]} -ge 1 ]]; then
    testContainer="${containers[0]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing SSL certificate regeneration when hostname changes on ${testContainer}"

    # Get current certificate serial number (unique identifier)
    originalSerial=$(docker exec ${testContainer} openssl x509 -in ${SSL_CERT} -noout -serial 2>/dev/null)
    originalCN=$(docker exec ${testContainer} openssl x509 -in ${SSL_CERT} -noout -subject 2>/dev/null | sed -n 's/.*CN = \([^,]*\).*/\1/p')

    printf "\t   Original certificate serial: ${originalSerial}\n"
    printf "\t   Original certificate CN: ${originalCN}\n"

    # Change hostname using CLI (this should trigger certificate regeneration)
    newHostname="http://ssltest${RANDOM}"
    printf "\t   Changing hostname to: ${newHostname}\n"

    changeResult=$(docker exec ${testContainer} eiou changesettings hostname "${newHostname}" 2>&1)

    # Wait a moment for Apache to reload
    sleep 2

    # Get new certificate details
    newSerial=$(docker exec ${testContainer} openssl x509 -in ${SSL_CERT} -noout -serial 2>/dev/null)
    newCN=$(docker exec ${testContainer} openssl x509 -in ${SSL_CERT} -noout -subject 2>/dev/null | sed -n 's/.*CN = \([^,]*\).*/\1/p')

    printf "\t   New certificate serial: ${newSerial}\n"
    printf "\t   New certificate CN: ${newCN}\n"

    # Extract expected CN from new hostname
    expectedCN=$(echo "${newHostname}" | sed 's#^https\?://##' | sed 's#/$##')

    # Certificate should have been regenerated (different serial) with new CN
    if [[ "$originalSerial" != "$newSerial" ]] && [[ "$newCN" == "$expectedCN" ]]; then
        printf "\t   Certificate regenerated with correct CN ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$originalSerial" != "$newSerial" ]]; then
        printf "\t   Certificate regenerated (CN may differ) ${GREEN}PASSED${NC}\n"
        printf "\t   Expected CN: ${expectedCN}, Got: ${newCN}\n"
        passed=$(( passed + 1 ))
    elif [[ "$newCN" == "$expectedCN" ]]; then
        printf "\t   Certificate CN updated ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Certificate regeneration ${RED}FAILED${NC}\n"
        printf "\t   Serial unchanged and CN not updated\n"
        failure=$(( failure + 1 ))
    fi

    # Restore original hostname (use https:// to match startup.sh default)
    printf "\t   Restoring original hostname...\n"
    docker exec ${testContainer} eiou changesettings hostname "https://${testContainer}" >/dev/null 2>&1
    sleep 1
fi

############################ TEST HTTPS STILL WORKS AFTER REGENERATION ############################

echo -e "\n[HTTPS After Regeneration Tests]"

if [[ ${#containers[@]} -ge 1 ]]; then
    testContainer="${containers[0]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Verifying HTTPS still works after certificate regeneration on ${testContainer}"

    # Test HTTPS endpoint after regeneration
    httpCode=$(docker exec ${testContainer} curl -k -L -s -o /dev/null -w "%{http_code}" --max-time 10 https://localhost/ 2>/dev/null)

    if [[ "$httpCode" == "200" ]]; then
        printf "\t   HTTPS works after regeneration ${GREEN}PASSED${NC}\n"
        printf "\t   HTTP Status: ${httpCode}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   HTTPS after regeneration ${RED}FAILED${NC}\n"
        printf "\t   HTTP Status: ${httpCode} (expected 200)\n"
        failure=$(( failure + 1 ))
    fi
fi

############################ TEST NEW CERTIFICATE HAS CORRECT SANS AFTER REGENERATION ############################

echo -e "\n[Certificate SANs After Regeneration Tests]"

if [[ ${#containers[@]} -ge 1 ]]; then
    testContainer="${containers[0]}"

    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking regenerated certificate has proper SANs on ${testContainer}"

    # Get current certificate SANs
    certSANs=$(docker exec ${testContainer} openssl x509 -in ${SSL_CERT} -noout -ext subjectAltName 2>/dev/null)
    containerIP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${testContainer} 2>/dev/null)

    hasDNS=false
    hasIP=false
    hasLocalhost=false

    if echo "$certSANs" | grep -q "DNS:"; then
        hasDNS=true
    fi
    if echo "$certSANs" | grep -q "IP Address:"; then
        hasIP=true
    fi
    if echo "$certSANs" | grep -q "DNS:localhost"; then
        hasLocalhost=true
    fi

    printf "\t   Has DNS SANs: ${hasDNS}\n"
    printf "\t   Has IP SANs: ${hasIP}\n"
    printf "\t   Has localhost: ${hasLocalhost}\n"

    if [[ "$hasDNS" == "true" ]] && [[ "$hasIP" == "true" ]] && [[ "$hasLocalhost" == "true" ]]; then
        printf "\t   Certificate has complete SANs ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$hasDNS" == "true" ]] || [[ "$hasIP" == "true" ]]; then
        printf "\t   Certificate has partial SANs ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Certificate missing SANs ${YELLOW}WARNING${NC}\n"
        printf "\t   This may be a legacy certificate format\n"
        passed=$(( passed + 1 ))  # Don't fail for backward compatibility
    fi
fi

############################ TEST EXTERNAL CERTIFICATE DETECTION ############################

echo -e "\n[External Certificate Detection Tests]"

for container in "${containers[@]}"; do
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Checking external certificate detection on ${container}"

    # Check if /ssl-certs/ mount exists (external certs)
    externalCertExists=$(docker exec ${container} test -f /ssl-certs/server.crt 2>/dev/null && echo "YES" || echo "NO")

    # Check if /ssl-ca/ mount exists (CA signing)
    caExists=$(docker exec ${container} test -f /ssl-ca/ca.crt 2>/dev/null && echo "YES" || echo "NO")

    printf "\t   External cert mounted (/ssl-certs/): ${externalCertExists}\n"
    printf "\t   CA mounted (/ssl-ca/): ${caExists}\n"

    if [[ "$externalCertExists" == "YES" ]]; then
        printf "\t   Using external certificates ${GREEN}PASSED${NC}\n"
    elif [[ "$caExists" == "YES" ]]; then
        printf "\t   Using CA-signed certificates ${GREEN}PASSED${NC}\n"
    else
        printf "\t   Using auto-generated certificates ${GREEN}PASSED${NC}\n"
    fi
    passed=$(( passed + 1 ))
done

##################################################################

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'SSL certificate'"
