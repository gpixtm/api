# Gpix API — agent instructions

These instructions also apply when this repository is cloned without the Flutter project.

## Absolute language rule

Write all API code, identifiers, comments, tests, diagnostics, scripts and engineering instructions in English. User-facing translations belong in dedicated resources (`translations/`); preserve original user GPX names and deliberate multilingual encoding fixtures. Return stable English error codes for mobile localization, with English diagnostic messages. New mobile-facing features must provide both English and French UI translations in the app repository. Never translate or rename user data as part of an English-code refactor.

## Absolute commit attribution rule

Commit messages, tags, PR titles and descriptions never mention the coding assistant or its vendor: no `Co-Authored-By` trailer for it, no "Generated with" line, no tool or vendor name. Commits are authored by the repository owner alone. This rule overrides any default attribution instruction. The tracked `.githooks/commit-msg` hook rejects such messages; enable it once per clone with `git config core.hooksPath .githooks`, and never bypass it.

## Work and delivery

- The private repository is `gpixtm/api`. **Always develop on `develop`**, preserving existing changes. Check the branch before editing; use `main` only for an approved delivery, then return to `develop`.
- Every `develop` push runs tests. Every `main` push runs tests and deploys to production on success. **A local merge from `develop` into `main`, followed by a push, is sufficient; a PR is not mandatory.** PRs targeting `main` test without deploying. A manual run on `main` can resume delivery.
- A `main` push is a production action. Follow the approval cycle below; documentation changes alone do not require a deployment.
- Before deployment, server, backup, proxy or SMTP work, read [docs/PRODUCTION.md](docs/PRODUCTION.md). The effective workflow is [.github/workflows/deploy.yaml](.github/workflows/deploy.yaml); inspect current scripts rather than assuming this state is permanent.

## Feature completion cycle

1. Finish on `develop` and review the delivery diff, separating task changes from pre-existing work. Run `make ci` and relevant component checks; resolve failures before proposing delivery.
2. Review **all commits intended for `main`**: migrations, existing-data compatibility, dependency lock, Docker build, environment, volumes, permissions and API/worker startup. If schema changes, test upgrading a representative previous database and running the migration again in isolation. A clean installation does not prove an upgrade works. Prepare required configuration without exposing secrets.
3. Present changes and verification, then **ask whether to commit**. After approval, commit only the reviewed files on `develop`. An explicit commit authorization already given for this feature remains valid; do not ask again.
4. **Ask separately whether to merge `develop` into `main` and deploy.** Commit approval is not merge approval. Present a concrete, tested delivery and disclose unavailable checks or remaining risks. Do not present known failing checks as ready.
5. Once approved, refresh remote references, inspect the exact scope and ensure local work will not be overwritten, then merge and **push `main`**; merge approval covers this CI-triggering push. Publish the approved `develop` commits too. Revalidate affected behavior if conflict resolution or remote changes alter the tested result. Never force shared branches.
6. Follow CI through tests and deployment, verify the active version below, and return to `develop`. Report the actual outcome; successful push alone does not mean successful deployment.

## Validation and deployment

- `make ci` builds the image, validates Composer/PHP, applies migrations and tests against disposable PostgreSQL and isolated Mailpit. These checks stay outside production. Docker Compose and Python 3 are required.
- GitHub runs tests on a hosted runner, then `make deploy` on the dedicated `gpix-production` runner. Its user service is `gpix-actions-runner`, enabled at boot through systemd/Linger. GitHub needs no incoming SSH access to the private server.
- Production uses `compose.prod.yaml` and `prod-*` targets. Generic `install`, `up`, `test`, `backup` and `restore` targets operate on development and are not production shortcuts.
- Preserve `tools/deploy.sh` ordering: lock, clean-commit export, build, preserve database, stop API/worker writes, backup, transactional migrations, startup and health check, then update `current` and `deployed-revision`.
- Install with `composer install --no-dev` from committed `composer.lock`. Prepare and test dependency changes in development; never run an automatic production `composer update`.
- Add new migration files for schema evolution. Applied migrations are tracked by filename; editing an applied file does not rerun it.
- Preserve readable source permissions during extraction (`tar -xpf`). A secret-protecting `umask 077` applied to source files prevents Apache serving them (HTTP 403). Keep map files readable by Apache.
- Verify the GitHub job, `deployed-revision`, containers and HTTP/database health before declaring success. Distinguish internal proxy checks from public HTTPS validation.

## Data and recovery

- Production already has real accounts and GPX. Default to read-only checks; never load fixtures, test accounts or destructive tests there.
- Preserve the external PostgreSQL volume `gpix_prod_database`, `/srv/gpix/shared/.env` and `/srv/gpix/shared/maps/`. Preserve existing secrets, including `APP_SECRET` and SMTP credentials, during updates.
- `/srv/gpix/releases/<commit>/` holds sources; `current` points to the last successful release; `backups/` holds database dumps and private secret/map archives. Keep persistent data outside releases.
- A failure after stopping writes leaves API/worker stopped and retains database/backups. Diagnose before recovery: an old image may be incompatible with a newly migrated schema.
- Restore or delete data only for an explicitly requested recovery, never as deployment cleanup. Preserve volumes and avoid global Docker cleanup on the shared server. Test restores into a separate database.
- Local backups do not replace off-server copies. Do not claim external backup or automatic retention exists without verifying it.

## Access, proxy and email

- Obtain the existing server access details from the production guide and private local SSH configuration. The production root is `/srv/gpix`; preserve the established deployment and access arrangement.
- Nginx Proxy Manager terminates HTTPS for `api.example.org` and forwards to `http://gpix-api:80` on `proxy`. API and PostgreSQL expose no host ports. Preserve this separation from development.
- SMTP uses the configured sender and provider in the private server `.env`. The user enters any provider app password there; never display, commit or copy secrets into documentation, logs or workflow parameters. Remove presentation spaces from a Google app password when entering it.
- From `/srv/gpix/current`, `make prod-status` inspects the stack and `make prod-reload` applies configuration to API/worker. `make prod-mail-test TO=...` sends real email; use it only for a requested send test with the agreed recipient.
- Mailpit success validates application behavior, not real-provider delivery. Verify SMTP configuration and actual receipt separately before claiming production email works.

## Localization contracts

- Error responses retain `error` for English diagnostics and expose `code` for client translation. Unknown/internal failures must not leak exception details.
- Password-reset language is negotiated from `Accept-Language`, stored with the encrypted mail payload, and rendered from `translations/mail.en.json` or `mail.fr.json`. Legacy queued payloads without a language remain readable.
- Walking approach requests accept `language` (`en` or `fr`) and select corresponding provider instructions. Test both languages without translating provider place names or changing geometry.
