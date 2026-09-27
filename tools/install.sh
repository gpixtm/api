#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
docker compose version >/dev/null
docker info >/dev/null
umask 077
if [ ! -f .env ]; then
  if docker volume inspect gpix_database >/dev/null 2>&1; then
    echo 'Existing database volume but .env is missing. Restore its original secrets; nothing was changed.' >&2
    exit 1
  fi
  secret() { od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }
  { printf 'DB_PASSWORD=%s\n' "$(secret)"; printf 'APP_SECRET=%s\n' "$(secret)"; printf 'HTTP_PORT=8080\nELEVATION_URL=\n'; } > .env
fi
for key in DB_PASSWORD APP_SECRET; do
  if ! grep -Eq "^${key}=.{32,}$" .env; then echo "Missing $key in .env. Restore/configure it; existing secrets are never replaced." >&2; exit 1; fi
done
mkdir -p data/maps backups
docker compose build
docker compose up -d --wait db
docker compose run --rm api php bin/migrate.php
docker compose up -d --wait
echo 'Gpix is ready on loopback port 8080 (or HTTP_PORT). Configure HTTPS and SMTP, then register an account on the phone. Fixtures are never loaded by installation.'
