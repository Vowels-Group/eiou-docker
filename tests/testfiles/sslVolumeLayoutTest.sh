#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# Test the unified ssl-cert volume layout.
#
# Verifies that mounting one ssl-cert volume at /var/lib/eiou/ssl plus the
# startup.sh symlinks (/etc/letsencrypt -> ssl/letsencrypt, /etc/nginx/ssl ->
# ssl/nginx) gives certbot and nginx the canonical paths they expect, with
# all cert state on one logical volume on disk.
#
# Also verifies the cert nginx serves on :443 persists across `docker rm` +
# `docker run` (the operator-driven image-update path), since that's the
# whole reason the nginx subdirectory is on a volume in the first place.
#
# This test manages its own container lifecycle — it does not reuse the
# topology spun up by tests/buildfiles/*.sh. Sources baseconfig/config.sh so
# it works whether invoked directly or via run-all-tests.sh.

# Source baseconfig if helpers (succesrate, colors, network) aren't in scope.
# Idempotent: when sourced by run-all-tests.sh the helpers are already loaded.
if ! command -v succesrate >/dev/null 2>&1; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    . "${SCRIPT_DIR}/baseconfig/config.sh"
fi

echo -e "\nTesting unified ssl-cert volume layout..."

testname="sslVolumeLayoutTest"
totaltests=0
passed=0
failure=0

testContainer="eiou-ssl-vol-test"
testVolume="${testContainer}-ssl-cert"
image="eiou/eiou"

# Sidecar image used to inspect the ssl-cert volume independently of the
# container under test. We need an *external* container that mounts the named
# volume read-only so we can confirm files actually live on the volume rather
# than in the eiou container's writable layer — `docker exec` into the eiou
# container can't make that distinction. Any minimal image with sh/ls/test
# would do; alpine:3 is the conventional ~5 MB choice for throwaway sidecars.
sidecarImage="alpine:3"

# Record whether sidecarImage was already present so cleanup only removes it
# if this test pulled it. Avoids deleting an image the operator had for other
# reasons.
if docker image inspect "${sidecarImage}" >/dev/null 2>&1; then
    sidecarImagePreexisted=yes
else
    sidecarImagePreexisted=no
fi

cleanup() {
    docker rm -f "${testContainer}" >/dev/null 2>&1
    docker volume rm "${testContainer}-mysql-data" "${testContainer}-config" \
        "${testContainer}-plugins" "${testContainer}-backups" "${testVolume}" \
        >/dev/null 2>&1
    if [[ "${sidecarImagePreexisted}" == "no" ]]; then
        docker rmi "${sidecarImage}" >/dev/null 2>&1
    fi
}
trap cleanup EXIT

# Pre-clean in case a previous run left state behind
cleanup

############################ CREATE CONTAINER WITH UNIFIED VOLUME ############################

echo -e "\n[Container Boot With Unified ssl-cert Volume]"
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Starting ${testContainer} with ssl-cert volume mount"

if docker run -d --network="${network}" --name "${testContainer}" \
    -v "${testContainer}-mysql-data:/var/lib/mysql" \
    -v "${testContainer}-config:/etc/eiou/config" \
    -v "${testContainer}-plugins:/etc/eiou/plugins" \
    -v "${testContainer}-backups:/var/lib/eiou/backups" \
    -v "${testVolume}:/var/lib/eiou/ssl" \
    -e EIOU_HOST="${testContainer}" \
    -e EIOU_TOR_FORCE_FAST=true \
    "${image}" >/dev/null 2>&1; then
    printf "\t   Container started ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Container failed to start ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
    echo ""
    succesrate "${totaltests}" "${passed}" "${failure}" "'ssl volume layout'"
    exit 1
fi

# Wait for startup.sh to reach the SSL section and write a self-signed cert.
sleep 30

############################ VOLUME EXISTS ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking ssl-cert volume was created"

if docker volume inspect "${testVolume}" >/dev/null 2>&1; then
    printf "\t   Volume ${testVolume} exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Volume ${testVolume} missing ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SUBDIRECTORIES CREATED ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking ssl/letsencrypt/ and ssl/nginx/ exist on the volume"

leSubdir=$(docker exec "${testContainer}" test -d /var/lib/eiou/ssl/letsencrypt && echo "EXISTS" || echo "NOT_FOUND")
nginxSubdir=$(docker exec "${testContainer}" test -d /var/lib/eiou/ssl/nginx && echo "EXISTS" || echo "NOT_FOUND")

if [[ "${leSubdir}" == "EXISTS" ]] && [[ "${nginxSubdir}" == "EXISTS" ]]; then
    printf "\t   Both subdirectories present ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ssl/letsencrypt/: ${leSubdir}, ssl/nginx/: ${nginxSubdir} ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SYMLINKS RESOLVE CORRECTLY ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking /etc/letsencrypt and /etc/nginx/ssl resolve into the volume"

leTarget=$(docker exec "${testContainer}" readlink -f /etc/letsencrypt 2>/dev/null)
nginxTarget=$(docker exec "${testContainer}" readlink -f /etc/nginx/ssl 2>/dev/null)

if [[ "${leTarget}" == "/var/lib/eiou/ssl/letsencrypt" ]] && \
   [[ "${nginxTarget}" == "/var/lib/eiou/ssl/nginx" ]]; then
    printf "\t   Both canonical paths symlink into the volume ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Symlink resolution wrong ${RED}FAILED${NC}\n"
    printf "\t   /etc/letsencrypt -> ${leTarget} (expected /var/lib/eiou/ssl/letsencrypt)\n"
    printf "\t   /etc/nginx/ssl   -> ${nginxTarget} (expected /var/lib/eiou/ssl/nginx)\n"
    failure=$(( failure + 1 ))
fi

############################ SUBDIRS LIVE ON ONE VOLUME ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking both subdirs live on ${testVolume}"

# A canary file written via /etc/letsencrypt must surface under the same volume's
# letsencrypt/ subdir; same for nginx. Confirms the symlinks resolve into the
# same backing volume rather than separate stores.
docker exec "${testContainer}" sh -c 'echo le-canary > /etc/letsencrypt/.canary.le && echo nginx-canary > /etc/nginx/ssl/.canary.nginx' >/dev/null 2>&1

volumeListing=$(docker run --rm -v "${testVolume}:/vol:ro" "${sidecarImage}" sh -c 'ls /vol/letsencrypt/.canary.le /vol/nginx/.canary.nginx 2>&1' 2>&1)

if echo "${volumeListing}" | grep -q "/vol/letsencrypt/.canary.le" && \
   echo "${volumeListing}" | grep -q "/vol/nginx/.canary.nginx"; then
    printf "\t   Canaries appear in ${testVolume}/letsencrypt/ and ${testVolume}/nginx/ ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Volume layout incorrect ${RED}FAILED${NC}\n"
    printf "\t   ls output: ${volumeListing}\n"
    failure=$(( failure + 1 ))
fi

# Clean up canaries so they don't pollute the persistence check below
docker exec "${testContainer}" rm -f /etc/letsencrypt/.canary.le /etc/nginx/ssl/.canary.nginx >/dev/null 2>&1

############################ NGINX CERT LANDS ON VOLUME ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking self-signed cert was written to ssl/nginx/"

certOnVolume=$(docker run --rm -v "${testVolume}:/vol:ro" "${sidecarImage}" sh -c 'test -f /vol/nginx/server.crt && test -f /vol/nginx/server.key && echo OK || echo MISSING' 2>&1)

if [[ "${certOnVolume}" == "OK" ]]; then
    printf "\t   server.crt + server.key in ssl/nginx/ ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Cert files missing on volume: ${certOnVolume} ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ CERT FINGERPRINT STABLE ACROSS docker rm ############################

totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Checking cert fingerprint persists across container recreation"

fingerprintBefore=$(docker exec "${testContainer}" openssl x509 -in /etc/nginx/ssl/server.crt -noout -fingerprint -sha256 2>/dev/null | awk -F= '{print $2}')

if [[ -z "${fingerprintBefore}" ]]; then
    printf "\t   Could not read cert fingerprint ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
else
    docker rm -f "${testContainer}" >/dev/null 2>&1

    if docker run -d --network="${network}" --name "${testContainer}" \
        -v "${testContainer}-mysql-data:/var/lib/mysql" \
        -v "${testContainer}-config:/etc/eiou/config" \
        -v "${testContainer}-plugins:/etc/eiou/plugins" \
        -v "${testContainer}-backups:/var/lib/eiou/backups" \
        -v "${testVolume}:/var/lib/eiou/ssl" \
        -e EIOU_HOST="${testContainer}" \
        -e EIOU_TOR_FORCE_FAST=true \
        "${image}" >/dev/null 2>&1; then

        sleep 20

        fingerprintAfter=$(docker exec "${testContainer}" openssl x509 -in /etc/nginx/ssl/server.crt -noout -fingerprint -sha256 2>/dev/null | awk -F= '{print $2}')

        if [[ "${fingerprintBefore}" == "${fingerprintAfter}" ]] && [[ -n "${fingerprintAfter}" ]]; then
            printf "\t   Fingerprint stable across recreation ${GREEN}PASSED${NC}\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Fingerprint changed ${RED}FAILED${NC}\n"
            printf "\t   Before: ${fingerprintBefore}\n"
            printf "\t   After:  ${fingerprintAfter}\n"
            failure=$(( failure + 1 ))
        fi
    else
        printf "\t   Recreated container failed to start ${RED}FAILED${NC}\n"
        failure=$(( failure + 1 ))
    fi
fi

##################################################################

echo ""
succesrate "${totaltests}" "${passed}" "${failure}" "'ssl volume layout'"
