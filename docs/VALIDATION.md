# Server validation — 27 September 2026

These are dated observations, not immutable environment settings. Current production procedures and status are described in [PRODUCTION.md](PRODUCTION.md); the early loopback-only and unconfigured-production observations below were superseded later that day.

## Original account migration validation

The standalone stack used Compose identity `gpix` and PostgreSQL volume `gpix_database`, preserving the existing database container. API and Mailpit were initially bound to loopback ports 8080/8025. That validation did not send external SMTP email or deploy an external server.

Private backups were created before migration: `backups/20260927T084937Z.dump` and `backups/20260927T084937Z-config-maps.tar.gz`. They contain private data/configuration and are excluded from delivery. No secret was intentionally printed or rotated.

Additive migration `002_accounts.sql` ran after user confirmation. Legacy fields compared before/after migration and fixture were unchanged:

| Legacy data | Count | Ordered-row MD5 |
| --- | ---: | --- |
| trails: id,payload,revision,deleted,mutation | 6 | `05b56952817f6876676163775fb0d752` |
| receipts: operation_id,digest,response,created_at | 15 | `152cc65c9c634ee8b4b9cd9d784dc90b` |

One historical-owner fixture existed, with no unowned legacy trail/receipt. Running fixtures twice preserved the password hash and library. Installation never runs fixtures automatically.

The original checks passed: API/worker build; 18 account and 9 persistence/API checks; eight real HTTP acceptance groups with isolated temporary users and Mailpit; one real Flutter SQLite/HTTP integration; three mocked-Docker installer tests. They covered authentication, refresh retry after lost response, owner isolation, reset expiry/single use, revocation, persistent throttling, conflicts, idempotency and coordinate validation. Test users were cleaned up. Mailpit success did not establish real SMTP delivery.

## LAN correction

The reported connection refusal came from a Dev LAN URL with Docker published only on loopback. Publication was corrected to the explicit private Wi-Fi interface. The Pixel received HTTP 200 for `/health` and login over the LAN; the verification session was closed. No ADB tunnel, personal-device installation or firewall change was involved. The four mobile environment checks passed.

`make dev` prepares the LAN binding and local Mailpit. The host's check of its own LAN IP remains advisory after Docker health succeeds, because the phone can reach it even when that host check fails. The mobile launch task can prepare a sibling backend automatically; a standalone clone uses its separately configured API.

## English code and localization changes

The isolated `tools/ci.sh` suite passed after adding stable error codes, language-aware walking requests and English/French reset email resources. It validated the image, Composer/PHP, repeated migrations, account/persistence tests, French provider fixture and real HTTP/Mailpit behavior. It did not deploy these changes to production or send external email.

The server bundle allowlist now includes translation resources. Secrets, live data, backups, vendor and SDK files remain excluded. Regenerate an archive when delivering changed sources and verify its manifest/checksum. The API remains independently deployable without the mobile repository.
