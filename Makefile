SHELL := /bin/sh
.DEFAULT_GOAL := debug-config
.PHONY: ci deploy
ci:
	sh tools/ci.sh
deploy:
	sh tools/deploy.sh

.PHONY: prod-reload prod-mail-test prod-status
prod-reload:
	sh tools/production.sh reload
prod-mail-test:
	sh tools/production.sh mail-test "$(TO)"
prod-status:
	sh tools/production.sh status

DC := docker compose
SH := sh
DART := ../.tooling/flutter/bin/dart
ifeq ($(OS),Windows_NT)
SH := C:/Program Files/Git/bin/sh.exe
DART := ..\.tooling\flutter\bin\dart.bat
endif
.PHONY: dev dev-off debug-config
debug-config:
	"$(DART)" tools/dev_backend.dart init
dev:
	"$(DART)" tools/dev_backend.dart
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
backup:
	"$(SH)" tools/backup.sh
restore:
	"$(SH)" tools/restore.sh "$(FILE)"

.PHONY: fixtures mailpit
fixtures:
	$(DC) exec -T api php bin/fixtures.php
mailpit:
	$(DC) -f compose.yaml -f compose.mailpit.yaml up -d --wait
