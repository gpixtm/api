# Contrat d’authentification

JSON ; erreurs `{error: message}` avec 401 (auth), 422 (validation), 429 (limitation) ou 503 (service indisponible).

| Route | Corps / résultat |
|---|---|
| POST `/api/auth/register` | `username,email,password` → session |
| POST `/api/auth/login` | `identifier,password` (username ou email) → session |
| GET `/api/auth/me` | Bearer access → `{user}` |
| POST `/api/auth/refresh` | `refresh_token` → session renouvelée |
| POST `/api/auth/logout` | `refresh_token`, Bearer optionnel → invalide la session |
| POST `/api/auth/forgot-password` | `email` → réponse générique mise en file |
| POST `/api/auth/reset-password` | `token,password` → usage unique, invalide toutes les sessions |
| POST `/api/auth/change-password` | Bearer + `current_password,password` → invalide toutes les sessions |

Session : `user:{id,username,email,legacy_owner}`, `access_token`, `refresh_token`, `expires_at` ISO8601 UTC. Accès 15 minutes ; refresh opaque stable haché, durée fixe 30 jours depuis connexion. Refresh remplace l’accès sans prolonger le refresh, afin de pouvoir réessayer après perte d’une réponse. Logout et changement/reset révoquent le refresh. Une session locale continue d’ouvrir sa bibliothèque hors ligne : une révocation distante n’est détectable qu’avec réseau.

Username : 3–32 lettres ASCII/chiffres/underscore. Unicité email/username insensible à la casse, email normalisé. Mot de passe 8–128 caractères. Champs non textuels refusés. Login limité par IP (30/15 min) et identifiant (10/15 min), reset par email (4/15 min) et IP, autres routes publiques sensibles 20/15 min. Compteurs PostgreSQL/verrous transactionnels communs aux processus. Ne pas faire confiance à X-Forwarded-For non configuré.

Les listes de traces filtrent le propriétaire ; un UUID d’un autre propriétaire renvoie 404 sans fuite de révision. Reçus isolés par propriétaire et opération. Cartes communes protégées par session. Données historiques sans propriétaire invisibles aux inscriptions, affectées uniquement par fixture explicite personnelle.

Inscription sans validation email bloquante. Reset code SHA-256, 30 minutes, usage unique ; email contient lien et code manuel. File transactionnelle chiffrée libsodium avec APP_SECRET. Réponse connue/inconnue identique, 503 identique si SMTP non configuré. Livraison asynchrone et réessais : surveiller le worker ; « mis en file » ne signifie pas « livré ».

Port Domain/Authentication, orchestration Application/Authenticate, adaptateurs PDO/Symfony en infrastructure. Fixtures append-only Symfony Console adaptées au PDO existant, sans ORM parallèle.

Références officielles : [PasswordHasher](https://symfony.com/doc/current/security/passwords.html), [Mailer](https://symfony.com/doc/current/mailer.html), [RateLimiter](https://symfony.com/doc/7.4/rate_limiter.html).

Capture Dev : [Mailpit v1.31.2](https://github.com/axllent/mailpit/releases/tag/v1.31.2), image épinglée dans compose.mailpit.yaml.
