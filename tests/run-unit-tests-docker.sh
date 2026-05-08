#!/usr/bin/env bash
# Run the PHPUnit unit suite inside the eiou Docker image.
#
# The image carries php-bcmath, /app/eiou, and /etc/eiou/config — none of
# which exist on a plain dev host — so a handful of tests that legitimately
# guard on those paths skip on the host but run here. Tests that assert
# the *first-boot* (no key files, empty config) path also need a fresh
# /etc/eiou/config; this script provides one via tmpfs.
#
# Usage:
#   tests/run-unit-tests-docker.sh           # all three modes (recommended)
#   tests/run-unit-tests-docker.sh node      # against the running eiou-node
#   tests/run-unit-tests-docker.sh fresh     # fresh empty-config container
#   tests/run-unit-tests-docker.sh fresh-key fresh container with a stub
#                                              encrypted-master-key file
# Extra args after the mode are forwarded to phpunit (filters, paths, etc).

set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
mode="${1:-all}"
shift || true

img="eiou-docker-eiou:latest"
if ! docker image inspect "$img" >/dev/null 2>&1; then
  echo "Image $img not found — run 'docker compose build' first." >&2
  exit 1
fi

phpunit_in_container() {
  local volumes_args=("$@")
  docker run --rm \
    -v "$repo_root":/repo -w /repo \
    --entrypoint sh \
    "${volumes_args[@]}" \
    "$img" \
    -c "
      rm -rf /repo/tests/.phpunit.cache
      php /repo/files/vendor/bin/phpunit \
        --configuration /repo/tests/phpunit.xml.dist \
        --no-progress \
        ${EIOU_PHPUNIT_EXTRA_ARGS:-}
    "
}

run_against_running_node() {
  echo "=== mode: node (against running eiou-node) ==="
  if ! docker ps --filter name=^/eiou-node$ --format '{{.Names}}' | grep -q '^eiou-node$'; then
    echo "eiou-node is not running — start it with 'docker compose up -d'." >&2
    return 1
  fi
  # Mirror the host repo into the container so the bootstrap (which
  # resolves files/vendor/autoload.php relative to the project root)
  # finds dev dependencies. Plain `docker compose exec` against the
  # production image would miss phpunit because the image is built
  # with --no-dev.
  docker cp "$repo_root/tests" eiou-node:/repo/tests >/dev/null 2>&1 || true
  docker cp "$repo_root/files" eiou-node:/repo/files >/dev/null 2>&1 || true
  docker exec eiou-node sh -c "
    rm -rf /repo/tests/.phpunit.cache
    php /repo/files/vendor/bin/phpunit \
      --configuration /repo/tests/phpunit.xml.dist \
      --no-progress \
      ${EIOU_PHPUNIT_EXTRA_ARGS:-} $*
  "
}

run_fresh() {
  echo "=== mode: fresh (empty /etc/eiou/config + /dev/shm) ==="
  EIOU_PHPUNIT_EXTRA_ARGS="$*" phpunit_in_container \
    --tmpfs /etc/eiou/config:rw,size=10M \
    --tmpfs /dev/shm:rw,size=10M
}

run_fresh_with_encrypted_key() {
  echo "=== mode: fresh-key (stub .master.key.enc present) ==="
  docker run --rm \
    -v "$repo_root":/repo -w /repo \
    --entrypoint sh \
    --tmpfs /etc/eiou/config:rw,size=10M \
    --tmpfs /dev/shm:rw,size=10M \
    "$img" \
    -c "
      printf 'fake-encrypted-data' > /etc/eiou/config/.master.key.enc
      chmod 600 /etc/eiou/config/.master.key.enc
      rm -rf /repo/tests/.phpunit.cache
      php /repo/files/vendor/bin/phpunit \
        --configuration /repo/tests/phpunit.xml.dist \
        --no-progress \
        ${EIOU_PHPUNIT_EXTRA_ARGS:-} $* tests/Unit/Security/
    "
}

case "$mode" in
  node)       run_against_running_node "$@" ;;
  fresh)      run_fresh "$@" ;;
  fresh-key)  run_fresh_with_encrypted_key "$@" ;;
  all)
    run_against_running_node "$@"
    echo
    run_fresh "$@"
    echo
    run_fresh_with_encrypted_key "$@"
    ;;
  *)
    echo "Unknown mode: $mode (expected one of: node, fresh, fresh-key, all)" >&2
    exit 2
    ;;
esac
