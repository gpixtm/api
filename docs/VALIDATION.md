# Validation serveur — 27 septembre 2026

La stack est autonome dans `backend/`, avec identité Compose `gpix` et volume PostgreSQL `gpix_database`. Le conteneur PostgreSQL existant a été conservé. L’API écoute uniquement sur `127.0.0.1:8080` et Mailpit v1.31.2 sur `127.0.0.1:8025`. Aucun essai sur `0.0.0.0`, aucun envoi SMTP externe, aucun déploiement externe.

## Préservation et migration

Sauvegarde privée créée avant migration : `backups/20260927T084937Z.dump` et `backups/20260927T084937Z-config-maps.tar.gz`. Elles contiennent des données/configurations privées et sont exclues de la livraison. Aucun secret n’a été affiché ni tourné.

Migration additive `002_accounts.sql` appliquée après confirmation de l’utilisateur. Les colonnes historiques, comparées avant/après migration et fixture, sont strictement identiques :

| Données historiques | Nombre | Empreinte MD5 des lignes ordonnées |
|---|---:|---|
| traces : id,payload,revision,deleted,mutation | 6 | `05b56952817f6876676163775fb0d752` |
| reçus : operation_id,digest,response,created_at | 15 | `152cc65c9c634ee8b4b9cd9d784dc90b` |

Un seul utilisateur de fixture, propriétaire personnel ; aucune trace ni aucun reçu historique sans propriétaire. Deux exécutions réelles de `make fixtures` dans le conteneur : mot de passe haché strictement inchangé au second appel, aucun effacement de bibliothèque. L’installateur ne lance jamais ces fixtures.

## Résultats exécutés

- Images API et worker construites avec succès. Composer vérifie libsodium et ne signale aucune vulnérabilité connue dans les dépendances installées.
- `make test` : **18 contrôles comptes** et **9 contrôles persistance/API** réussis. Couverture : hash, login email/username, mauvais mot de passe, accès expiré, renouvellement et réponse perdue, déconnexion, expiration/usage unique du reset, révocation, limitation persistante, indisponibilité SMTP connue/inconnue, conflits, idempotence et coordonnées.
- `tests/http_acceptance.py --mailpit` : **8 groupes HTTP réels réussis**, avec comptes temporaires isolés. Contrat auth, propriétaires A/B par UUID et reçus, refresh réessayable, capture du reset dans Mailpit, réponse neutre, expiration/usage unique/révocation et 429. Nettoyage des comptes/données de test effectué.
- **Un test Flutter d’intégration SQLite + HTTP réel** réussi et nettoyé. Les autres tests mobile et APK sont consignés dans la validation globale mobile.
- Trois tests d’installation avec Docker simulé réussis : secrets internes valides acceptés sans clé personnelle, secrets DB et APP courts refusés. Aucune migration déclenchée par ces tests.

Les tests de reset ont utilisé exclusivement le capteur local. Le SMTP réel et l’URL HTTPS de production restent à configurer. La révocation distante n’est détectable sur le mobile qu’au retour du réseau ; le stockage privé reste disponible hors ligne après une première authentification.

## Livraison

L’archive serveur est générée par une liste explicite de sources avec manifeste et SHA-256 ; ni `.env`, données, sauvegardes, vendor ou SDK. Les identifiants de fixture demandés sont des données de développement publiques et ne sont jamais créés silencieusement lors d’une installation production. Regénérer l’archive après les dernières modifications documentaires. Les commandes optionnelles Dev utilisent le projet mobile voisin ; le serveur et ses commandes d’exploitation sont autonomes.

## Correction LAN du debug — 27 septembre

Après signalement connection refused : l’URL Dev 192.168.1.10:8080 ne correspondait pas au port Docker publié uniquement sur loopback. Publication corrigée sur la seule interface Wi-Fi 192.168.1.10. Depuis le Pixel, `/health` et `/api/auth/login` ont répondu HTTP 200 par le LAN ; session de vérification refermée. Aucun tunnel ADB, aucune installation sur le téléphone, aucune modification du pare-feu. Les quatre tests de configuration mobile passent.

Le profil Dev F5 prépare désormais cette publication via sa tâche préalable ; `make dev` depuis backend/ fait de même et conserve Mailpit local. Le contrôle du PC vers sa propre IP est indicatif lorsque Docker est healthy, car cet accès échoue ici tandis que l’accès réel depuis le Pixel réussit. Ces résultats remplacent la limite LAN mentionnée dans le bilan précédent.
