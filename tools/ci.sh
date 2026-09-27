#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
ci() { docker compose -f compose.ci.yaml "$@"; }
cleanup() {
  status=$?
  trap - EXIT
  if [ "$status" -ne 0 ]; then ci logs --tail=80; fi
  ci down --volumes --remove-orphans
  exit "$status"
}
trap cleanup EXIT
ci build --pull
ci run --rm --no-deps api composer validate --strict --no-check-publish
ci run --rm --no-deps api sh -c 'find src bin public tests -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
ci up -d --wait db
ci run --rm api php bin/migrate.php
ci run --rm api php bin/migrate.php
ci up -d --wait api
ci exec -T api php tests/accounts.php
ci exec -T api php tests/run.php
ci exec -T api php tests/approach.php
# Recreate the API, keeping PostgreSQL alive, and verify readiness again.
ci up -d --wait --force-recreate api
ci exec -T api php bin/health.php
