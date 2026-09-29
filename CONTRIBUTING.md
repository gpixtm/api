# Contributing to Gpix API

Thanks for your interest! Bug reports, fixes, documentation and features are welcome. For a large change, open an issue first so we can agree on the approach.

## Workflow

1. Fork the repository and branch from `develop` (not `main`, which is what gets deployed).
2. Make focused commits with [Conventional Commits](https://www.conventionalcommits.org/) messages: `feat: …`, `fix: …`, `docs: …`, `refactor: …`, `test: …`.
3. Run `make ci` locally. It builds the image, validates Composer and PHP syntax, runs the migrations twice and runs every suite against a disposable PostgreSQL and Mailpit.
4. Open a pull request against `develop`, describing the behaviour change and how you tested it. CI runs the same `make ci`.

Enable the repository's commit hooks once per clone:

```sh
git config core.hooksPath .githooks
```

## Rules

- **English everywhere in code**: identifiers, comments, tests, diagnostics, scripts and docs. User-facing text belongs in `translations/` (English and French are required). API errors return a stable English `code` that the app translates.
- **Layers**: pure rules in `src/Domain`, use cases in `src/Application`, PostgreSQL/HTTP/SMTP adapters in `src/Infrastructure`, HTTP in `src/Presentation`.
- **Schema changes** go in a new migration file; never edit one that has been released. Test upgrading a database populated with the previous schema, not just a fresh install, and keep old payloads readable.
- **Shared trail fingerprint**: `src/Domain/TrailFingerprint.php` must stay byte-identical to the app's `lib/domain/trail_identity.dart`. Both repositories test the same vector; change both together.
- **Dependencies**: change `composer.json` and `composer.lock` together, and keep unrelated upgrades out of your PR.
- **User data** (GPX names, descriptions, places) keeps its original bytes and language: preserve Unicode, and never "clean" or translate it.
- **No secrets or private infrastructure** in commits: no `.env`, credentials, host names, domains, IP addresses or personal data. Use `example.org`/`example.test` placeholders.

## Tests

| Command | What it covers |
| --- | --- |
| `make ci` | Everything, in an isolated Compose project |
| `make test` | PHP suites against your running development stack |
| `python tools/test_install.py` | The installer, with Docker stubbed |

Add a regression test with every bug fix: sync, authentication, geometry, encoding and migrations are the most sensitive areas.

## Licence

By contributing, you agree that your contributions are licensed under the [AGPL-3.0-or-later](LICENSE).
