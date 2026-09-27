#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
mkdir -p backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
docker compose exec -T db pg_dump -U gpix -d gpix -Fc > "backups/$stamp.dump.part"
mv "backups/$stamp.dump.part" "backups/$stamp.dump"
tar -czf "backups/$stamp-config-maps.tar.gz" .env data/maps
echo "Saved backups/$stamp.dump and configuration/maps archive. Keep both private and off this server."
