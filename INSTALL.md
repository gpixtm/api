# Installing the Gpix server

This guide covers the standalone/development Compose stack. For the existing production service, use [production operations](docs/PRODUCTION.md) and [agent instructions](AGENTS.md), including approval and data-preservation rules.

## Requirements and installation

Linux, Docker Engine, recent Docker Compose v2 with `--wait`, GNU Make, a POSIX shell, `od`, `tr`, `grep` and `tar`. Build access to Docker/Composer registries is required. Linux containers on x86_64 Docker Desktop have been tested; arm64 images are available but unverified. Allow at least 2 GB RAM (4 GB recommended for builds), plus map/backup storage. Large map/DEM preparation needs separate sizing.

```sh
cd gpix-server # or backend/ in the full workspace
make install
make test
```

Run server commands from this directory. Private configuration is `.env`, maps are `data/maps/`, backups are `backups/`. The archive is autonomous and does not need Flutter. Scripts run with `sh` and use LF line endings. On Windows, Make and Docker must be on PATH; the Makefile uses Git Bash at `C:/Program Files/Git/bin/sh.exe`. Override `SH` when installed elsewhere.

`make install` generates two random internal secrets only when `.env` does not exist, builds, waits for PostgreSQL TCP, migrates, then waits for the API. Compose identity is `gpix`, database volume `gpix_database`. Re-running preserves data and secrets. An existing volume without `.env` stops installation: restore the original configuration. Do not run competing copies under the same identity on one host.

Default binding is `127.0.0.1:8080`. Use email/username and password, not a personal API key. Keep `DB_PASSWORD` and `APP_SECRET` at least 32 characters and private. Mobile sessions use Android secure storage; libraries are account-private.

## HTTPS and LAN

For the optional standalone Caddy gateway, point a DNS name to the host, provide ports 80/443, set `SERVER_NAME=hiking.example.org`, then run `make https`. Caddy stores certificates persistently. This alternative has not been field-deployed; an existing HTTPS proxy forwarding to loopback also works. Mobile Prod requires HTTPS; Dev permits HTTP only on private IPv4.

Full-workspace commands `make debug-config` / `make dev` require sibling `../mobile` and the [Puro](https://puro.dev) environment `gpix` on `PATH` (setup in the mobile repository's `docs/DEBUG-VSCODE.md`); they run `puro -e gpix dart`. Override `PURO` or `PURO_ENV` if needed. `make dev` reads `../mobile/config/dev.local.json`, publishes on that explicit LAN interface and prepares Mailpit; `make dev-off` restores loopback. No tunnel is required. Verify `/health` from the phone, not solely the host's own LAN address.

## Commands and recovery

| Command | Purpose |
| --- | --- |
| `make up` | Start API, database and worker using `.env` |
| `make fixtures` | Explicit development fixture, no purge; never production |
| `make mailpit` | Local SMTP capture and loopback Mailpit interface |
| `make down` | Stop without deleting volumes |
| `make logs` | Follow logs |
| `make migrate` | Apply missing migrations |
| `make test` | Database, idempotency, conflict, validation and auth checks |
| `make ci` | Full isolated build, database and HTTP/Mailpit suite |
| `make backup` | PostgreSQL dump and private configuration/map archive |
| `make restore FILE=backups/date.dump` | Restore into an empty database only |

With Caddy, stop the gateway using `docker compose -f compose.yaml -f compose.https.yaml down`. Preserve volumes; do not add `-v`.

For disaster recovery, prepare a separate host, restore private configuration and maps, start only `docker compose up -d --wait db`, restore before migrations, then run `make install`. Restore refuses a nonempty database. Preserve/reissue certificates as appropriate and keep dumps/config archives off-server. A successful fresh installation does not prove recovery; test restoration separately.

For updates to this standalone stack, back up, replace code/lockfiles while preserving `.env` and `data`, then run `make install`. Changing `DB_PASSWORD` does not change the existing database's internal password. Use the production deployment procedure for the existing production stack.

## Maps, elevations and routing

The mobile app prepares OpenFreeMap areas automatically. The optional legacy package catalog is built once under `data/maps/`; see [map packages](docs/OFFLINE.md). Installation does not download the world or claim a production region is ready. Shared regions are reused on the phone.

Missing elevations can use your Open Topo Data instance configured through `ELEVATION_URL`, for example `http://elevation:5000/v1/srtm`. No public DEM provider is implicitly selected. Requests contain at most 100 coordinates; missing values stay null and failure does not block maps/GPX. DEM software/data and real operation are not bundled or field-validated.

Authenticated `POST /api/approach` joins the nearest GPX point through Valhalla. Configure `ROUTING_URL` (default `https://valhalla1.openstreetmap.de/route`, low-volume testing). Use a dedicated provider for scale. Origin/destination coordinates go to the provider; requests are limited to about one per second per API instance. `language` selects English or French instructions. Recalculation is explicit. Restart using the environment-appropriate command; `make dev` preserves LAN publication.

## Fixtures and email

`make fixtures` runs inside the existing API container without host PHP/Composer. It is an explicit development-only historical-owner fixture, not part of `make install`. It preserves changed passwords, performs no purge and refuses an unrelated public account with the same identity. Never publish its local login values in mobile launch files; keep optional prefill in ignored Dev configuration.

`make mailpit` captures emails at `http://127.0.0.1:8025`, using internal `mailpit:1025` and a dummy sender for API/worker. It forwards nothing externally. `make up` or `make dev-off` returns to `.env` SMTP; run `make mailpit` again to restore capture. The worker sends queued reset emails; copy the code into Forgot password → I have a reset code. Codes expire after 30 minutes and work once; manual entry does not rely on deep-link handling.

For a separately installed live server, configure real `MAILER_DSN` and `MAIL_FROM`, then recreate API/worker. For existing production use `make prod-reload`. Without configuration, known/unknown reset requests both return 503. Otherwise the neutral reply means queued; the worker retries every 30 seconds until expiry. Inspect worker logs and attempt counts without printing codes. Preserve `APP_SECRET`, which encrypts queued payloads. See [authentication](docs/AUTH.md).

Mobile Prod does not prefill credentials. First login requires a connection; a recognized session then opens its private library offline despite access-token expiry. Explicit sign-out closes local access immediately; remote revocation is discovered on reconnect.
