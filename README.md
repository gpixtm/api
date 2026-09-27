# Serveur Gpix

Production `https://api.example.org` : [CI, déploiement, Nginx Proxy Manager et sauvegardes](docs/PRODUCTION.md). Le dépôt GitHub `gpixtm/api` a ce dossier pour racine ; chaque push sur `main` déclenche les tests puis le déploiement (merge local de `develop` + push ou fusion d'une PR).

Ce dossier contient toute la stack API Symfony/PostgreSQL : configuration Compose, Makefile, secrets locaux, scripts, cartes serveur, sauvegardes et documentation d’exploitation.

Depuis ce dossier :

```sh
make install
make up
make test
```

Lire [INSTALL.md](INSTALL.md) pour les prérequis, HTTPS, données cartographiques et sauvegardes. `.env` est privé et n’est jamais inclus dans l’archive produite par `python tools/bundle_server.py`. Les fichiers livrables sont dans `dist/`.

La stack conserve l’identité Compose `gpix` et le volume `gpix_database`. La publication de base reste `127.0.0.1:8080`. Ne pas lancer en parallèle une autre copie de cette stack sur le même hôte.

Les commandes de développement mobile `make debug-config` / `make dev` utilisent le SDK `../.tooling/flutter` et `../mobile/config/dev.local.json` dans le projet complet. Le serveur autonome n’en dépend pas pour son exploitation. `make dev-off` revient au loopback.

Structure : `src/`, `bin/`, `migrations/`, `public/`, `tests/`, `docker/`, `tools/`, `docs/`, `data/maps/`, `backups/`, `dist/` et `.env`. Les données privées et sauvegardes sont exclues du contexte Docker et de l’archive.

Connexion email/username et mot de passe. `make fixtures` initialise explicitement le compte personnel dans le conteneur API, sans purge ; `make mailpit` active la capture locale. Voir [contrat auth](docs/AUTH.md).
