#!/bin/sh
# Linux production deployment. All persistent state lives outside releases.
set -eu
umask 077
source_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
# DEPLOY_ROOT is the absolute directory holding releases, shared state and backups.
root=${DEPLOY_ROOT:?Set DEPLOY_ROOT to the absolute deployment directory}
case "$root" in /*) ;; *) echo 'DEPLOY_ROOT must be absolute.' >&2; exit 1;; esac
mkdir -p "$root/releases" "$root/shared/maps" "$root/backups"
# Apache must be able to traverse the read-only maps mount; secrets stay private.
chmod 755 "$root/shared/maps"
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
revision=$(git -C "$source_dir" rev-parse HEAD)
if [ -n "$(git -C "$source_dir" status --porcelain --untracked-files=normal)" ]; then
  echo 'Commit changes before deployment; only committed code is deployed.' >&2
  exit 1
fi
export GPIX_RELEASE=$revision GPIX_SHARED_DIR="$root/shared"
release="$root/releases/$revision"
env_file="$root/shared/.env"
if [ ! -f "$env_file" ]; then
  if docker volume inspect gpix_prod_database >/dev/null 2>&1; then
    echo 'Database exists but secrets are missing. Restore shared/.env.' >&2; exit 1
  fi
  secret() { od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }
  {
    printf 'DB_PASSWORD=%s\nAPP_SECRET=%s\n' "$(secret)" "$(secret)"
    printf 'PROXY_NETWORK=%s\nCATALOGUE_STORAGE=%s\n' "${PROXY_NETWORK:-}" "${CATALOGUE_STORAGE:-}"
    printf 'MAILER_DSN=\nMAIL_FROM=\nELEVATION_URL=\n'
  } > "$env_file"
fi
chmod 600 "$env_file"
for key in DB_PASSWORD APP_SECRET; do
  grep -Eq "^${key}=[a-f0-9]{64}$" "$env_file" || { echo "Missing/invalid $key in shared/.env" >&2; exit 1; }
done
if [ ! -d "$release" ]; then
  staging=$(mktemp -d "$root/releases/.staging-XXXXXXXX")
  git -C "$source_dir" archive --format=tar --output="$root/release.tar.part" "$revision"
  # Preserve Git's public source permissions despite the private-state umask.
  tar -xpf "$root/release.tar.part" -C "$staging"
  rm "$root/release.tar.part"
  mv "$staging" "$release"
fi
cd "$release"
prod() { docker compose --env-file "$env_file" -f compose.prod.yaml "$@"; }
prod config --quiet
# Build before interrupting the running application. Dependencies come from composer.lock.
prod build --pull api
docker volume inspect gpix_prod_database >/dev/null 2>&1 || docker volume create gpix_prod_database >/dev/null
# Do not recreate/upgrade a running database as a side effect of an app deployment.
prod up -d --wait --no-recreate db
maintenance=0
on_exit() {
  status=$?
  if [ "$status" -ne 0 ] && [ "$maintenance" = 1 ]; then
    prod stop api mail-worker catalogue-worker || true
    echo "Deployment failed. API/workers remain stopped; database and backups preserved in $root. See docs/DEPLOYMENT.md." >&2
  fi
}
trap on_exit EXIT
maintenance=1
prod stop api mail-worker catalogue-worker
stamp=$(date -u +%Y%m%dT%H%M%SZ)-$revision
prod exec -T db pg_dump -U gpix -d gpix -Fc > "$root/backups/$stamp.dump.part"
mv "$root/backups/$stamp.dump.part" "$root/backups/$stamp.dump"
tar -czf "$root/backups/$stamp-shared.tar.gz.part" -C "$root" shared
mv "$root/backups/$stamp-shared.tar.gz.part" "$root/backups/$stamp-shared.tar.gz"
# Migrations are transactional and take a PostgreSQL advisory lock.
prod run --rm --no-deps api php bin/migrate.php
prod up -d --wait --wait-timeout 180 --no-build --no-deps api mail-worker catalogue-worker
prod exec -T api php bin/health.php
ln -sfn "$release" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"
maintenance=0
printf '%s\n' "$revision" > "$root/deployed-revision"
echo "Deployed $revision. Reverse proxy upstream: http://gpix-api:80"
