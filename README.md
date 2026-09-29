# Gpix API

Backend of **Gpix**, an offline-first hiking app for Android: accounts, synchronisation of each walker's private library, and the shared, collaborative trail catalogue. The mobile app lives in [gpixtm/app](https://github.com/gpixtm/app).

The phone works offline and syncs when it can. The API is what makes a library follow its walker to another phone, and what lets every walker benefit from the trails, places and reviews added by others.

## What it does

- **Accounts and sessions**: registration, sign-in by username or email, short-lived access tokens with rotating refresh, password reset by email (English and French), per-IP and per-identifier rate limits.
- **Private library sync**: GPX trails, planned days, completed walks and their health summaries sync through an idempotent outbox protocol with revisions, tombstones and explicit conflicts (`/api/sync`). Walks, speeds, statistics and health data stay private.
- **Collaborative trails**: any trail added by a walker is shared once with everyone, deduplicated by a geometric fingerprint shared byte-for-byte with the app. Walkers who covered at least 90 % of a trail may rate and review it. Named places can be added within 100 m of a trail, and GPX waypoint files can be attached as imported places.
- **Open-data catalogue**: hiking routes from OpenStreetMap are acquired per country (Geofabrik extracts + osmium), grouped (e.g. a GR and its variants), refreshed nightly, and browsed through area and paginated search endpoints, never copied wholesale to the phone.
- **Walking helpers**: running per-route speed statistics for kilometre summaries, missing elevations through an optional Open Topo Data instance, and walking approach routes to the nearest point of a trail through Valhalla.

## Stack

PHP 8.4 with Symfony 7.4 components, PostgreSQL 17, Apache, all in Docker Compose. Background workers send emails and acquire catalogue countries. There is no ORM: PDO, plain SQL migrations and a small Clean Architecture split:

```
src/Domain/          pure rules: accounts, trail fingerprint, walk coverage, catalogue planning
src/Application/     use cases (authentication, library sync, elevations)
src/Infrastructure/  PostgreSQL, SMTP, HTTP providers, OSM processing
src/Presentation/    HTTP controllers, error codes
migrations/          ordered SQL/PHP migrations, tracked by filename
bin/                 console entry points (migrate, workers, catalogue, fixtures)
tests/               PHP and Python suites run against a disposable database
```

## Quick start

Requirements: Docker Engine with Compose v2, GNU Make, a POSIX shell (Git Bash on Windows) and Python 3 for the HTTP acceptance tests.

```sh
git clone https://github.com/gpixtm/api.git
cd api
make install   # generates .env with random secrets, builds, migrates, starts
make test      # runs the test suites against the running stack
```

The API listens on `http://127.0.0.1:8080` (`/health` answers `{"status":"ok"}`). Create an account from the app, or load a development account with `GPIX_FIXTURE_PASSWORD=… make fixtures`. `make mailpit` captures password-reset emails at `http://127.0.0.1:8025`.

To reach this local API from a physical phone on your LAN, see the mobile repository's development guide and `make dev`. [INSTALL.md](INSTALL.md) covers requirements, HTTPS, backups and recovery. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) describes a release-based server deployment behind your own reverse proxy.

## Configuration

`make install` writes `.env` (never commit it). Useful keys, all documented in [.env.example](.env.example):

| Variable | Purpose |
| --- | --- |
| `DB_PASSWORD`, `APP_SECRET` | Generated secrets; `APP_SECRET` encrypts queued emails |
| `HTTP_PORT` | Loopback port, 8080 by default |
| `MAILER_DSN`, `MAIL_FROM` | SMTP for password reset (reset answers 503 without them) |
| `ELEVATION_URL` | Optional Open Topo Data endpoint |
| `ROUTING_URL` | Valhalla endpoint; the default public demo is for low volume only |
| `SERVER_NAME` | Public DNS name for the optional `make https` Caddy gateway |

## Documentation

| Topic | Document |
| --- | --- |
| Authentication, sessions, error codes, reset emails | [docs/AUTH.md](docs/AUTH.md) |
| Shared trails, deduplication, reviews and places | [docs/PUBLIC_TRAILS.md](docs/PUBLIC_TRAILS.md) |
| Open-data catalogue acquisition, groups and search | [docs/CATALOGUE.md](docs/CATALOGUE.md) |
| Per-route statistics | [docs/STATISTICS.md](docs/STATISTICS.md) |
| Legacy offline map packages | [docs/OFFLINE.md](docs/OFFLINE.md) |
| Server deployment and continuous deployment | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, tests and coding rules, and [SECURITY.md](SECURITY.md) to report a vulnerability privately.

## Licence and data

Gpix API is free software under the [GNU Affero General Public License v3.0 or later](LICENSE). If you run a modified version as a network service, you must offer its source code to its users.

Catalogue trails are derived from © OpenStreetMap contributors, available under the [Open Database License](https://www.openstreetmap.org/copyright). Extracts are downloaded from [Geofabrik](https://download.geofabrik.de/). Walking directions are computed by [Valhalla](https://github.com/valhalla/valhalla) on the configured provider.
