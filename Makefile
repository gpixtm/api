SHELL := /bin/sh
.DEFAULT_GOAL := debug-config
.PHONY: ci deploy
ci:
	sh tools/ci.sh
deploy:
	sh tools/deploy.sh

.PHONY: prod-reload prod-mail-test prod-status prod-catalogue-acquire prod-catalogue-runs prod-catalogue-rollback
prod-catalogue-acquire:
	sh tools/production.sh catalogue acquire "$(COUNTRY)"
prod-catalogue-runs:
	sh tools/production.sh catalogue runs
prod-catalogue-rollback:
	sh tools/production.sh catalogue rollback "$(RUN)"
prod-reload:
	sh tools/production.sh reload
prod-mail-test:
	sh tools/production.sh mail-test "$(TO)"
prod-status:
	sh tools/production.sh status

DC := docker compose
SH := sh
# The mobile workspace's Flutter SDK is the Puro environment `gpix`.
PURO := puro
PURO_ENV := gpix
ifeq ($(OS),Windows_NT)
SH := C:/Program Files/Git/bin/sh.exe
endif
.PHONY: dev dev-off debug-config
debug-config:
	"$(PURO)" -e $(PURO_ENV) dart tools/dev_backend.dart init
dev:
	"$(PURO)" -e $(PURO_ENV) dart tools/dev_backend.dart
dev-off:
	$(DC) -f compose.yaml up -d --wait
.PHONY: install up down logs test migrate backup restore
https:
	$(DC) -f compose.yaml -f compose.https.yaml up -d --wait
install:
	"$(SH)" tools/install.sh
up:
	$(DC) up -d --wait
down:
	$(DC) down
logs:
	$(DC) logs -f --tail=100
migrate:
	$(DC) run --rm api php bin/migrate.php
test:
	$(DC) exec -T api php tests/accounts.php
	$(DC) exec -T api php tests/run.php
	$(DC) exec -T api php tests/statistics_upgrade.php
	$(DC) exec -T api php tests/public_trails.php
	$(DC) exec -T api php tests/public_trails_upgrade.php
	$(DC) exec -T api php tests/catalogue.php
	$(DC) exec -T api php tests/catalogue_upgrade.php
backup:
	"$(SH)" tools/backup.sh
restore:
	"$(SH)" tools/restore.sh "$(FILE)"

.PHONY: catalogue-acquire catalogue-runs
# Development acquisition; FILE=/data/catalogue/<extract>.osm.pbf skips the download.
catalogue-acquire:
	$(DC) exec -T catalogue-worker php bin/catalogue.php acquire "$(COUNTRY)" --now $(if $(FILE),--file="$(FILE)")
catalogue-runs:
	$(DC) exec -T catalogue-worker php bin/catalogue.php runs

.PHONY: fixtures mailpit
fixtures:
	$(DC) exec -T -e GPIX_FIXTURE_PASSWORD api php bin/fixtures.php
mailpit:
	$(DC) -f compose.yaml -f compose.mailpit.yaml up -d --wait
