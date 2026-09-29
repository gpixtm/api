#!/bin/sh
set -eu
# Run from <root>/current: the deployment root is inferred from the active release.
root=${DEPLOY_ROOT:-$(dirname "$(dirname "$(CDPATH= cd -- "$(dirname "$0")/.." && pwd -P)")")}
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
    prod up -d --wait --wait-timeout 180 --no-build --no-deps --force-recreate api mail-worker catalogue-worker
    ;;
  mail-test)
    test -n "${2:-}" || { echo 'Usage: make prod-mail-test TO=your@email' >&2; exit 1; }
    prod exec -T api php bin/mail-test.php "$2"
    ;;
  catalogue)
    # Queue a country (`acquire FR`), list runs (`runs`) or hide what a run created (`rollback ID`).
    shift
    test -n "${1:-}" || { echo 'Usage: make prod-catalogue-acquire COUNTRY=FR | make prod-catalogue-runs' >&2; exit 1; }
    prod exec -T catalogue-worker php bin/catalogue.php "$@"
    ;;
  status) prod ps ;;
  *) echo 'Unknown production operation.' >&2; exit 1 ;;
esac
