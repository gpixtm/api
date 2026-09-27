#!/bin/sh
set -eu
root=${DEPLOY_ROOT:-/srv/gpix}
test -f "$root/deployed-revision"
export GPIX_RELEASE=$(cat "$root/deployed-revision")
export GPIX_SHARED_DIR="$root/shared"
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'A production operation is already running.' >&2; exit 1; }
cd "$root/current"
prod() { docker compose --env-file "$root/shared/.env" -f compose.prod.yaml "$@"; }
case "${1:-status}" in
  reload)
    prod config --quiet
    prod up -d --wait --wait-timeout 180 --no-build --no-deps --force-recreate api mail-worker
    ;;
  mail-test)
    test -n "${2:-}" || { echo 'Usage: make prod-mail-test TO=your@email' >&2; exit 1; }
    prod exec -T api php bin/mail-test.php "$2"
    ;;
  status) prod ps ;;
  *) echo 'Unknown production operation.' >&2; exit 1 ;;
esac
