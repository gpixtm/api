# Installation du serveur Gpix

Le guidage piéton vers le point le plus proche du GPX utilise `POST /api/approach`, authentifié. `ROUTING_URL` dans `.env` configure une API Valhalla `/route` : par défaut `https://valhalla1.openstreetmap.de/route`, service public pour les essais et un faible volume. Prévoir une instance dédiée avant un déploiement à grande échelle. Les coordonnées de position et de jonction sont transmises au fournisseur configuré. Une demande par seconde au maximum sur l’instance API ; recalculs explicites. Après modification, redémarrer avec la commande adaptée à l’environnement (`make dev` conserve la publication LAN).

## Prérequis

Linux avec Docker Engine, plugin **Docker Compose v2** récent (`--wait`), GNU Make, shell POSIX et utilitaires `od`, `tr`, `grep`, `tar`. Accès réseau aux registres Docker/Composer pendant construction. Architecture x86_64 testée via Docker Desktop avec conteneurs Linux ; arm64 utilise les images multiarchitectures mais n’a pas été testée. Prévoir au moins 2 Go de RAM pour la petite API/BDD, 4 Go recommandés pour construction, et de l’espace supplémentaire pour vos régions et sauvegardes. Les gros traitements cartographiques/DEM ont des besoins propres, non mesurés ici.

## Démarrage rapide

Extraire l’archive dans un dossier, puis :

```sh
cd gpix-server
make install
make test
```

Dans le projet complet, utiliser `cd backend` à la place de `cd gpix-server`. Toutes les commandes serveur de ce guide s’exécutent dans ce dossier. Le fichier réel est `backend/.env`, les cartes `backend/data/maps`, les sauvegardes `backend/backups`. L’archive extraite est autonome : elle ne nécessite ni dossier mobile ni SDK Flutter pour installer, tester ou exploiter l’API.

Les scripts sont appelés avec `sh` : aucun bit exécutable n’est nécessaire. Ils sont fournis avec fins de ligne LF.

Sous Windows, GNU Make et Docker doivent être dans le PATH. Le Makefile sélectionne Git Bash à `C:/Program Files/Git/bin/sh.exe` pour les scripts POSIX ; si Git est installé ailleurs, préciser `make install SH="chemin/vers/sh.exe"` (idem pour backup/restore). Les commandes Dev sélectionnent le SDK Dart isolé `../.tooling/flutter`.

`make install` génère `.env` avec deux secrets internes aléatoires **uniquement s’il n’existe pas**, construit l’image, attend PostgreSQL sur TCP, applique les migrations puis attend l’API. Le projet Compose s’appelle `gpix`, le volume `gpix_database`. Une seconde exécution conserve les données et secrets. Si un volume existe sans `.env`, l’installation s’arrête : restaurer le fichier original. Ne pas lancer cette stack dans deux dossiers concurrents sur le même hôte : leur identité est volontairement stable.

L’API écoute par défaut **127.0.0.1:8080**. Connexion par email ou username et mot de passe ; aucune clé personnelle. `DB_PASSWORD` et `APP_SECRET` gardent leur minimum de 32 caractères. Ne pas partager `.env`. Les sessions sont stockées dans le stockage sécurisé Android. Les bibliothèques sont privées par compte.

## HTTPS pour le téléphone

Option intégrée : disposer d’un nom DNS pointant vers le serveur et rendre les ports 80/443 disponibles. Renseigner `SERVER_NAME=rando.votre-domaine.fr` dans `.env`, puis :

```sh
make https
```

Caddy termine TLS et gère les certificats dans des volumes persistants. Le téléphone utilise `https://rando.votre-domaine.fr`, avec sa connexion utilisateur. Cette option n’a pas été déployée sur un domaine réel. Un reverse proxy HTTPS déjà présent convient aussi vers `127.0.0.1:8080`. Le profil mobile Prod impose HTTPS ; Dev accepte HTTP sur une IPv4 privée.

## Commandes

Pour le test mobile sur le réseau local, les commandes optionnelles `make debug-config` et `make dev` nécessitent le projet complet avec `../mobile` et `../.tooling/flutter`. Le guide est `mobile/docs/DEBUG-VSCODE.md` dans ce projet. `make dev` lit `../mobile/config/dev.local.json` et applique l’override LAN explicite ; `make dev-off` rétablit le loopback. Cela ne change pas la publication de production par défaut.

| Commande | Effet |
| --- | --- |
| `make up` | Démarrer API/BDD et worker avec la configuration `.env` |
| `make fixtures` | Ajouter explicitement le compte personnel dans le conteneur API, sans purge |
| `make mailpit` | Démarrer API/worker avec SMTP de capture local et Mailpit sur loopback |
| `make down` | Arrêter sans supprimer les volumes |
| `make logs` | Suivre les journaux |
| `make migrate` | Appliquer les migrations encore absentes |
| `make test` | Tests isolés en base, idempotence, conflits, validation et authentification |
| `make backup` | Dump PostgreSQL + archive privée `.env` et cartes dans `backups/` |
| `make restore FILE=backups/date.dump` | Restaurer uniquement dans une base **vide** ; refus sur une bibliothèque existante |

Avec Caddy, utiliser `docker compose -f compose.yaml -f compose.https.yaml down` pour arrêter aussi la passerelle. Ne pas ajouter `-v` : cela détruirait les volumes.

Restauration de sinistre : préparer un hôte de récupération distinct, remettre la configuration privée et `data/maps`, démarrer seulement `docker compose up -d --wait db`, lancer `make restore FILE=...` **avant** les migrations, puis `make install`. Le script refuse d’écraser une base non vide. Sauvegarder les certificats Caddy si nécessaire, ou les faire réémettre. Le dump et l’archive de configuration doivent être conservés hors du serveur. Un test réel de restauration sur nouvel hôte reste à faire.

## Cartes et altitudes

L’installation ne télécharge pas une carte du monde. Aucun faux endpoint de production n’est configuré. Préparer une fois les régions à distribuer dans `data/maps` : voir [OFFLINE.md](docs/OFFLINE.md). Après cette mise en place, la préparation de chaque randonnée se fait depuis le téléphone : catalogue, taille, téléchargement et validation locale. Les régions partagées sont réutilisées.

Pour les altitudes manquantes, installer votre instance Open Topo Data avec un jeu libre approprié et définir par exemple `ELEVATION_URL=http://votre-service-interne:5000/v1/srtm`, puis `make up` (recréation si environnement changé). Aucun fournisseur public n’est utilisé implicitement. L’API envoie au service configuré des groupes de 100 coordonnées maximum ; les lacunes restent nulles. L’échec de ce service ne bloque jamais l’accès aux cartes/GPX. Le logiciel de DEM et les données ne sont pas inclus et leur exploitation réelle n’a pas encore été testée.

## Mise à jour

Sauvegarder, remplacer le code/lockfiles en préservant `.env` et `data`, puis `make install`. Ne pas changer arbitrairement `DB_PASSWORD` sur une base existante : changer une variable ne modifie pas le mot de passe interne PostgreSQL. Aucun nettoyage destructif n’est effectué par les recettes.

## Comptes, fixtures et emails

Depuis `backend/` (ou la racine de l’archive extraite) :

```sh
make install
make up
make fixtures
make test
```

`make fixtures` exécute `php bin/fixtures.php` dans le conteneur API déjà démarré : aucun PHP/Composer sur l’hôte. La commande ajoute exactement `walker`, `walker@example.test`, mot de passe `REDACTED-FIXTURE-PASSWORD`, haché en base. Elle est explicite, jamais exécutée par `make install`, ne purge rien et préserve un mot de passe modifié. Les traces et reçus historiques sans propriétaire sont affectés uniquement à ce compte. Un compte homonyme public non créé par fixture provoque un refus, pour revue manuelle avant affectation.

Capture locale des emails, sans envoi externe :

```sh
make mailpit
```

Ouvrir `http://127.0.0.1:8025`. Le SMTP interne `mailpit:1025` et l’expéditeur fictif ne sont configurés que par `compose.mailpit.yaml`, pour l’API et le worker. La publication API reste loopback. `make up` ou `make dev-off` reviennent à la configuration SMTP de `.env` ; relancer `make mailpit` pour réactiver le capteur. Mailpit ne relaie rien à un SMTP externe. L’API indique une demande **mise en file**, puis le worker livre au capteur. Le mail contient le code à copier dans Gpix → Mot de passe oublié → J’ai un code, ainsi qu’un lien `gpix://reset-password?token=…`. Le code expire après 30 minutes et ne sert qu’une fois ; le formulaire manuel fonctionne aussi sans ouverture automatique du lien.

Production : renseigner un véritable `MAILER_DSN=smtps://…` (ou SMTP/STARTTLS adapté au fournisseur) et `MAIL_FROM` dans `.env`, puis `make up`. Aucun fournisseur ni expéditeur réel n’est inventé. Sans configuration, reset connu/inconnu → 503 identique. Avec configuration, réponse générique mise en file identique ; l’échec SMTP ultérieur est retenté toutes les 30 secondes jusqu’à expiration. Surveiller `docker compose logs --tail=100 mail-worker` et les compteurs `attempts` de `mail_outbox`, sans afficher les codes. Mis en file ne garantit pas livré.

`APP_SECRET` chiffre les codes de la file via libsodium ; le préserver avec les sauvegardes de configuration. Les tables de sessions et codes ne stockent que leurs hashes. Mots de passe hachés via Symfony PasswordHasher. Voir [contrat auth](docs/AUTH.md).

VS Code : ouvrir `../mobile`, sélectionner profil et téléphone puis F5. Depuis ce terminal, `cd ../backend` pour Make. Les profils debug préremplissent le compte de fixture ; aucune création automatique en production. Première authentification en ligne obligatoire, puis session et bibliothèque privées disponibles hors ligne même après expiration de l’accès court. Une révocation distante ne peut être constatée qu’au retour du réseau ; une déconnexion locale ferme immédiatement la bibliothèque.
