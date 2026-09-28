# Production — api.example.org

The private `gpixtm/api` repository contains only the backend. Pushes to `develop` and PRs targeting `main` run tests on a disposable GitHub runner. Each `main` push tests and then deploys the exact commit on the dedicated `gpix-production` runner. Read [AGENTS.md](../AGENTS.md) and obtain the separate merge/deployment approval before this action. A local merge and push is sufficient; PRs are optional. Manual dispatch from `main` can resume a deployment. No inbound SSH from GitHub is needed.

After approval and validation:

```sh
git switch main
git pull --ff-only origin main
git merge develop
git push origin main
```

## Deployment and persistent data

The workflow runs `make ci`, then `make deploy`. Restrict `main` pushes to authorized maintainers: the deployment runner has Docker access on production. PRs never run the deployment job. CI uses disposable PostgreSQL and Mailpit, including HTTP reset requests, SMTP worker delivery, code receipt, expiry and single use; no CI email leaves that Docker network. Python 3 is required.

The established SSH account is `walker` on the private host `192.0.2.10`; use the existing private local SSH configuration/key. The production directory is `/srv/gpix/`:

- `releases/<commit>/`: exact release sources.
- `current`: last successfully deployed release.
- `shared/.env`: secrets generated once, mode 600, never sent to GitHub.
- `shared/maps/`: persistent map catalog/packages.
- `backups/`: PostgreSQL dump and private secret/map archive before migration.
- `deployed-revision`: active commit; `deploy.lock` prevents concurrent deployments.

PostgreSQL uses external volume `gpix_prod_database`, which deployment and `compose down` preserve. Replacing application containers must not lose accounts, sessions or trails. Symfony caches belong to each new container.

Build dependencies with `composer install --no-dev` from `composer.lock`. Dependency updates are prepared, tested and committed in development, never resolved directly on production. Preserve readable extraction permissions (`tar -xpf`); applying a restrictive secret umask to code can cause Apache HTTP 403.

Build before interrupting service. Then preserve/start the existing database, stop API/worker writes, back up, run transactional migrations under lock, recreate API/worker and verify HTTP/database health. A short interruption is expected. Never load fixtures or test users on production. An already running database is not recreated for every delivery. PostgreSQL version upgrades, especially major versions, require a dedicated backup/migration procedure.

## Nginx Proxy Manager

NPM uses Docker network `proxy`. Only the API joins it under alias `gpix-api`; PostgreSQL stays on the private stack network. No API/database host ports are published.

1. Point DNS `api.example.org` to the public server/router address. Public TCP 80/443 must reach NPM on the configured private host.
2. Open the existing NPM administration interface on port 81.
3. Under Hosts → Proxy Hosts, configure domain `api.example.org`, scheme `http`, upstream `gpix-api`, port `80`, publicly accessible (the API authenticates requests), asset caching disabled and no websocket requirement.
4. Add the following advanced settings:

```nginx
client_max_body_size 50m;
proxy_read_timeout 120s;
proxy_send_timeout 120s;
```

5. Request the configured Let's Encrypt certificate and enable Force SSL and HTTP/2.
6. Verify public HTTPS `/health` returns `{"status":"ok"}` and unauthenticated `/api/trails` returns 401.

If the NPM network changes, update `PROXY_NETWORK` in `shared/.env` and redeploy. NPM `localhost` would refer to its own container, not the API. Distinguish internal proxy checks from public HTTPS checks.

## SMTP and mobile

Configure `MAILER_DSN` and `MAIL_FROM` in `shared/.env`; preserve the existing sender and credentials. Production must not use Mailpit. Without SMTP, registration/login work but password recovery is unavailable. Apply changes to both API and worker using `make prod-reload`.

For Gmail, enable two-step verification where required and create an [app password](https://myaccount.google.com/apppasswords). The normal Google account password is unsuitable. If unavailable, check [Google's account restrictions](https://support.google.com/mail/answer/185833). Enter the 16-character app password without presentation spaces directly into the private server `.env`; never paste real credentials into tracked examples.

```dotenv
MAILER_DSN=smtps://sender%40example.com:APP_PASSWORD@smtp.example.org:465
MAIL_FROM=sender@example.com
```

Use the actual configured account instead of these placeholders. Only when an explicit email test is requested, send to the agreed recipient:

```sh
cd /srv/gpix/current
make prod-reload
make prod-mail-test TO=agreed-recipient@example.com
```

SMTP acceptance is not proof of receipt: check the inbox and spam folder. Updates preserve `.env`. Reference: [Gmail SMTP with SSL on port 465](https://support.google.com/a/answer/176600).

The mobile Prod profile uses `https://api.example.org` and no fixture prefill. Users create their own accounts. DNS and the certificate must work before debugging against this endpoint. Password-reset emails support English/French according to the request language.

## Operations and recovery

```sh
cd /srv/gpix/current
export GPIX_RELEASE=$(cat /srv/gpix/deployed-revision)
export GPIX_SHARED_DIR=/srv/gpix/shared
docker compose --env-file "$GPIX_SHARED_DIR/.env" -f compose.prod.yaml ps
docker compose --env-file "$GPIX_SHARED_DIR/.env" -f compose.prod.yaml logs --tail=100 api mail-worker
systemctl --user status gpix-actions-runner
```

A build failure leaves the previous version running. Failure during migration/startup leaves API/worker stopped and data preserved; CI fails and `current` remains on the last successful release. Diagnose job logs, correct and rerun/push only within the approved delivery. Successful migrations are not replayed; a failing SQL migration rolls back its transaction.

Never automatically restore an old dump: it can discard subsequent writes. After successful migrations, an older image may be schema-incompatible. Verify restoration in a separate recovery database before an explicitly authorized switch.

Local backups do not protect against disk loss. Copy dumps/private archives to external private storage and test restores. Retention is not automatic. Monitor disk space and remove only specifically identified, already archived old releases/backups according to the intended retention policy. Never run global `docker system prune --volumes` on this shared host.

The user systemd service `gpix-actions-runner` uses `Linger=yes` to start without SSH login. For a new installation, `tools/setup-runner.sh` reads a short-lived GitHub registration token from stdin and verifies the official archive SHA-256. Do not keep personal tokens or private SSH keys in the repository.

References: [GitHub runners](https://docs.github.com/en/actions/concepts/runners/self-hosted-runners), [Composer lockfiles](https://getcomposer.org/doc/01-basic-usage.md), [NPM Docker networks](https://nginxproxymanager.com/advanced-config/).
