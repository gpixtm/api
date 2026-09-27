# Production — api.example.org

Le dépôt `https://github.com/gpixtm/api` contient uniquement le backend. Les pushes sur `develop` et les PR vers `main` sont testés sur un runner GitHub jetable. Chaque push sur `main` teste puis déploie le commit exact via le runner dédié `gpix-production`, installé chez `walker@192.0.2.10`. Un merge local de `develop` dans `main` suivi d'un push suffit, sans PR obligatoire. Une fusion via GitHub fonctionne aussi. Un lancement manuel depuis `main` reste disponible pour la reprise. Aucun accès SSH entrant depuis GitHub n'est nécessaire.

```sh
git switch main
git pull --ff-only origin main
git merge develop
git push origin main
```

## Déploiement

Le workflow appelle `make ci`, puis `make deploy`. Le dépôt doit rester privé et seuls les mainteneurs autorisés doivent pouvoir pousser sur `main` : ce runner dispose de Docker sur le serveur de production. Les PR ne déclenchent jamais le job de déploiement.

La CI vérifie aussi par HTTP la demande de réinitialisation, l'envoi SMTP via le worker dans un Mailpit isolé, la réception du code et son usage unique/expiration. Aucun mail de test CI ne quitte ce réseau Docker. `make ci` nécessite Python 3 pour ces tests HTTP.

`/srv/gpix/` contient :

- `releases/<commit>/` : sources exactes de chaque version ;
- `current` : lien vers la dernière version déployée avec succès ;
- `shared/.env` : secrets générés une fois, mode 600, jamais transmis à GitHub ;
- `shared/maps/` : catalogue et paquets de cartes persistants ;
- `backups/` : dump PostgreSQL et archive des secrets/cartes avant chaque migration ;
- `deployed-revision` : commit actif ; `deploy.lock` empêche deux déploiements simultanés.

PostgreSQL utilise le volume Docker externe `gpix_prod_database`. Il n'est jamais supprimé par le déploiement ni par `compose down`. Les versions applicatives et les conteneurs peuvent être remplacés sans perdre comptes, sessions et randonnées. Les caches Symfony sont propres à chaque nouveau conteneur.

Les dépendances sont installées lors du build avec `composer install --no-dev` depuis `composer.lock`. Pour changer de version, faire `composer update` en développement, tester et committer le lock ; ne jamais résoudre de nouvelles versions directement en production.

Le build précède l'interruption du service. Ensuite : démarrage de la base si nécessaire, arrêt API/worker pour figer les écritures, sauvegarde, migrations SQL transactionnelles sous verrou, recréation API/worker et contrôle HTTP + base. Une brève indisponibilité est attendue pendant cette phase. Aucune fixture ni aucun test créant des utilisateurs n'est exécuté en production. La base de CI est jetable et distincte.

Une base déjà démarrée n'est pas recréée à chaque livraison. Les mises à jour PostgreSQL (surtout les changements de version majeure) constituent une opération dédiée avec sauvegarde et procédure de migration, jamais un changement de tag improvisé.

## Nginx Proxy Manager

Le conteneur NPM existant est sur le réseau Docker `proxy`. Seule l'API rejoint ce réseau avec l'alias `gpix-api` ; PostgreSQL reste sur le réseau privé de la stack. Aucun port API n'est publié sur l'hôte.

1. Dans votre DNS, faire pointer `api.example.org` vers l'IP publique du serveur/routeur. Les ports publics TCP 80 et 443 doivent parvenir à NPM sur `192.0.2.10`.
2. Ouvrir l'administration NPM : `http://192.0.2.10:81`.
3. **Hosts → Proxy Hosts → Add Proxy Host** :
   - Domain Names : `api.example.org`
   - Scheme : `http`
   - Forward Hostname / IP : `gpix-api`
   - Forward Port : `80`
   - Access List : `Publicly Accessible` (l'authentification est assurée par l'API)
   - Cache Assets : désactivé ; Websockets : inutile.
4. **Advanced** :

   ```nginx
   client_max_body_size 50m;
   proxy_read_timeout 120s;
   proxy_send_timeout 120s;
   ```

5. **SSL → Request a new SSL Certificate** : choisir Let's Encrypt, accepter les conditions et activer **Force SSL** et **HTTP/2 Support**. Enregistrer.
6. Vérifier `https://api.example.org/health` : réponse `{"status":"ok"}`. Un appel non authentifié à `/api/trails` doit retourner HTTP 401.

Si le réseau de NPM change, modifier `PROXY_NETWORK` dans `shared/.env`, puis redéployer. Ne pas utiliser `localhost` comme upstream NPM : il désignerait son propre conteneur.

## SMTP et mobile

Configurer `MAILER_DSN` et `MAIL_FROM` dans `/srv/gpix/shared/.env` pour permettre les réinitialisations de mot de passe. Ne pas utiliser Mailpit en production. Sans SMTP, inscription et connexion restent possibles mais la récupération de mot de passe est indisponible. Redéployer après modification des variables.

Le profil VS Code **Gpix · Prod** du projet mobile utilise `https://api.example.org`. Il ne préremplit aucun compte de fixture : créer son compte depuis l'application. Le DNS et le certificat doivent être opérationnels avant de lancer F5.

## Exploitation et reprise

```sh
cd /srv/gpix/current
export GPIX_RELEASE=$(cat /srv/gpix/deployed-revision)
export GPIX_SHARED_DIR=/srv/gpix/shared
docker compose --env-file "$GPIX_SHARED_DIR/.env" -f compose.prod.yaml ps
docker compose --env-file "$GPIX_SHARED_DIR/.env" -f compose.prod.yaml logs --tail=100 api mail-worker
systemctl --user status gpix-actions-runner
```

Après un échec du build, l'ancienne version continue de fonctionner. Après un échec pendant les migrations ou le démarrage, API et worker restent arrêtés : la CI échoue et les données sont conservées. Le lien `current` reste sur la dernière version réussie. Examiner les logs du job et corriger le code, puis pousser ou relancer le workflow. Les migrations déjà validées ne sont pas rejouées. Une migration SQL en échec est annulée avec la transaction entière.

Ne pas restaurer automatiquement un ancien dump : cela pourrait perdre les écritures faites après une livraison. Si les migrations ont réussi, revenir à une ancienne image exige de vérifier sa compatibilité avec le schéma. Une restauration doit être vérifiée dans une base de récupération distincte avant toute bascule explicite.

Les sauvegardes locales ne protègent pas contre la perte du disque : copier régulièrement dumps et archives vers un stockage externe privé, puis tester une restauration. Leur rétention n'est pas automatique. Surveiller l'espace disque et supprimer seulement les anciennes sauvegardes/versions déjà archivées selon la durée de conservation souhaitée ; ne jamais lancer `docker system prune --volumes` sur ce serveur partagé.

Le runner est un service systemd utilisateur `gpix-actions-runner` et le compte dispose de `Linger=yes`, ce qui permet son démarrage au boot sans session SSH. Pour une nouvelle installation, `tools/setup-runner.sh` lit sur stdin un jeton d'enregistrement GitHub de courte durée et vérifie le SHA-256 de l'archive officielle. Aucun jeton personnel ni aucune clé SSH n'est conservé dans le dépôt.

Références : [runners GitHub](https://docs.github.com/en/actions/concepts/runners/self-hosted-runners), [Composer et composer.lock](https://getcomposer.org/doc/01-basic-usage.md), [réseaux Docker avec NPM](https://nginxproxymanager.com/advanced-config/).
