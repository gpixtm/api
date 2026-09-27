#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
file=${1:?Usage: make restore FILE=backups/file.dump}
test -r "$file"
# Restore into a NEW empty database only. Never overwrite an existing library.
count=$(docker compose exec -T db psql -U gpix -d gpix -Atc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")
if [ "$count" != 0 ]; then echo 'Restore refused: target database is not empty. Use a separately provisioned recovery stack.' >&2; exit 1; fi
docker compose exec -T db pg_restore -U gpix -d gpix --no-owner --exit-on-error < "$file"
