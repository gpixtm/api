# Deploying the API on a server

This guide describes the release-based, single-host deployment used by `make deploy`: Docker Compose (`compose.prod.yaml`) behind an HTTPS reverse proxy you already run (Nginx Proxy Manager, Traefik, Caddy, plain Nginx…). For a quick standalone installation, including an optional bundled Caddy gateway, see [INSTALL.md](../INSTALL.md) instead.

## Layout

Choose an absolute deployment directory and export it as `DEPLOY_ROOT` for `make deploy`. Operations commands infer it when run from `$DEPLOY_ROOT/current`.

| Path | Content |
| --- | --- |
| `releases/<commit>/` | Exact sources of each deployed commit (`git archive`) |
| `current` | Symlink to the last successfully deployed release |
| `shared/.env` | Private configuration and secrets, mode 600, generated once |
| `shared/maps/` | Optional legacy map packages, mounted read-only |
| `backups/` | PostgreSQL dump and `shared/` archive taken before each migration |
| `deployed-revision` | Active commit; `deploy.lock` prevents concurrent operations |

PostgreSQL uses the external volume `gpix_prod_database`, which deployments and `docker compose down` preserve.

## Configuration

The first `make deploy` generates `shared/.env` with random `DB_PASSWORD` and `APP_SECRET`, copying `PROXY_NETWORK` and `CATALOGUE_STORAGE` from the environment when set. Deployment refuses to start while a required value is missing.

| Variable | Required | Meaning |
| --- | --- | --- |
| `DB_PASSWORD`, `APP_SECRET` | yes | 64 hex characters each. `APP_SECRET` encrypts queued emails: never change it on a running server. |
| `PROXY_NETWORK` | yes | Existing Docker network shared with your reverse proxy |
| `CATALOGUE_STORAGE` | yes | Host directory for open-data extracts and work files (several GB per country) |
| `MAILER_DSN`, `MAIL_FROM` | for password reset | Symfony Mailer DSN and sender, e.g. `smtps://user%40example.org:APP_PASSWORD@smtp.example.org:465` |
| `ELEVATION_URL` | no | Your Open Topo Data endpoint for missing elevations |
| `ROUTING_URL` | no | Valhalla endpoint for walking approach routes (defaults to a low-volume public demo) |

Keep `shared/.env` off Git, CI variables and logs. Without SMTP, registration and sign-in work but password reset answers 503.

## Deploying

```sh
git clone https://github.com/gpixtm/api.git && cd api
DEPLOY_ROOT=/srv/gpix PROXY_NETWORK=proxy CATALOGUE_STORAGE=/data/gpix-catalogue make deploy
```

Only committed code is deployed. The script takes a lock, exports the commit, builds the image before interrupting service, starts or keeps the database, stops API and workers, backs up the database and `shared/`, runs transactional migrations, restarts, checks health, then switches `current`. A build failure leaves the previous version running. A failure after writes stop leaves API and workers stopped with data and backups intact: diagnose before restarting, because an older image may not match a newer schema.

Dependencies come from the committed `composer.lock` (`composer install --no-dev`); never resolve dependencies on the server. Source permissions are preserved on extraction (`tar -xpf`) so Apache can read them.

## Reverse proxy

Only the API joins `PROXY_NETWORK`, under the alias `gpix-api`; PostgreSQL stays on the private stack network and no host port is published. Point your public DNS name at the proxy and forward HTTPS to `http://gpix-api:80`, with no caching and no websocket. Allow large GPX uploads and slow map requests, for example with Nginx:

```nginx
client_max_body_size 50m;
proxy_read_timeout 120s;
proxy_send_timeout 120s;
```

Then check that `https://<your-host>/health` returns `{"status":"ok"}` and that an unauthenticated `/api/trails` returns 401. Build the mobile app's Prod flavor with that URL (see the mobile repository's README).

## Operations

From `$DEPLOY_ROOT/current`:

| Command | Purpose |
| --- | --- |
| `make prod-status` | Container status |
| `make prod-reload` | Apply `shared/.env` changes to API and workers |
| `make prod-mail-test TO=you@example.org` | Send one real test email |
| `make prod-catalogue-acquire COUNTRY=FR` | Queue an open-data country acquisition (see [CATALOGUE.md](CATALOGUE.md)) |
| `make prod-catalogue-runs` | Acquisition progress and statistics |
| `make prod-catalogue-rollback RUN=<id>` | Hide what one acquisition run created |

Acquired countries are refreshed nightly; a deployment stops the worker and an interrupted run is retried the next night.

## Backups and recovery

Local backups do not survive a disk loss: copy dumps and `shared/` archives to external private storage and test restores in a separate database. Retention is not automatic. Never restore an old dump automatically, since it discards later writes. PostgreSQL major upgrades need their own backup and migration procedure. Avoid global `docker system prune --volumes` on a shared host.

## Continuous deployment

The public repository's [CI workflow](../.github/workflows/ci.yaml) runs `make ci` on GitHub-hosted runners for every push and pull request. It never runs on a self-hosted machine: a public repository's pull requests could otherwise execute code on your server, and its logs are public.

To deploy automatically, keep a **private** repository whose workflow runs on a self-hosted runner on the server (`RUNNER_REPOSITORY_URL=https://github.com/<owner>/<private-repo> sh tools/setup-runner.sh`, token on stdin), checks out the requested commit of this repository and runs `make deploy`. After a tested push to `main`, CI sends it a `repository_dispatch` event of type `deploy` with `client_payload.sha` when these repository secrets exist:

| Secret | Value |
| --- | --- |
| `DEPLOY_REPOSITORY` | `<owner>/<private-repo>` |
| `DEPLOY_DISPATCH_TOKEN` | Fine-grained token limited to that private repository, with *Contents: read and write* |

Forks have no such secrets and skip the step.
