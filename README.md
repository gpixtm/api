# Gpix API

Independent Symfony/PostgreSQL backend repository: `gpixtm/api`. Read [AGENTS.md](AGENTS.md) for English-code rules and the develop → commit approval → main merge approval → push/deployment workflow. The [production guide](docs/PRODUCTION.md) describes CI, Nginx Proxy Manager and backups for `https://api.example.org`.

From this directory:

```sh
make install
make up
make test
```

See [INSTALL.md](INSTALL.md) for prerequisites, HTTPS, map packages and recovery. The standalone server needs neither the mobile repository nor Flutter. `python tools/bundle_server.py` produces an explicit-allowlist archive in `dist/`, excluding live `.env`, data and backups.

Development keeps Compose identity `gpix`, volume `gpix_database` and default binding `127.0.0.1:8080`. Do not run competing copies on one host. Optional full-workspace mobile commands `make debug-config` and `make dev` use sibling `../mobile` and the Puro environment `gpix`; `make dev-off` restores loopback.

Sign in with username/email and password. Fixtures are explicit and development-only; `make mailpit` captures development emails locally. Read [authentication](docs/AUTH.md) for stable error codes, sessions and localized reset emails, [trail statistics](docs/STATISTICS.md) for the running per-GPX averages used by kilometre summaries, and [shared trails](docs/PUBLIC_TRAILS.md) for the collaborative catalogue, deduplication and reviews. Private files, maps, backups and generated artifacts stay outside Git and Docker build contexts.
