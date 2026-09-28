# Authentication contract

JSON errors contain `{error: English diagnostic, code: stable English identifier}` with HTTP 401 (authentication), 422 (validation), 429 (rate limit) or 503 (unavailable). Clients localize `code`; unknown/internal failures use a generic localized message and do not display raw exceptions.

| Route | Body / result |
| --- | --- |
| POST `/api/auth/register` | `username,email,password` → session |
| POST `/api/auth/login` | `identifier,password` (username or email) → session |
| GET `/api/auth/me` | Bearer access → `{user}` |
| POST `/api/auth/refresh` | `refresh_token` → renewed session |
| POST `/api/auth/logout` | `refresh_token`, optional Bearer → revoke session |
| POST `/api/auth/forgot-password` | `email`, `Accept-Language` header → neutral queued response |
| POST `/api/auth/reset-password` | `token,password` → single use, revoke all sessions |
| POST `/api/auth/change-password` | Bearer + `current_password,password` → revoke all sessions |

A session contains `user:{id,username,email,legacy_owner}`, `access_token`, `refresh_token`, `expires_at` (UTC ISO 8601). Access lasts 15 minutes; the opaque, hashed refresh token has a fixed 30-day lifetime from login. Refresh rotates access without extending or replacing the refresh token, allowing retry after a lost response. Logout and password change/reset revoke it. Local offline access remains possible; remote revocation is discovered only online.

Username: 3–32 ASCII letters/digits/underscores. Username/email uniqueness is case-insensitive and email is normalized. Passwords contain 8–128 characters. Non-text fields are rejected. Login limits are 30 requests/15 minutes per IP and 10 per identifier; reset is 4 per email/15 minutes plus IP limits; other sensitive public routes allow 20/15 minutes. PostgreSQL counters and transactional locks are shared across processes. Trust forwarded IP headers only with explicitly configured proxies.

Trail lists, mutations and idempotency receipts are owner-scoped. Another owner's UUID yields 404 without revision disclosure. Shared map downloads still require a session. Legacy unowned data is invisible to new registrations and can be assigned only through the explicit historical-owner fixture.

Registration does not require blocking email verification. Reset codes are SHA-256 hashed, expire after 30 minutes and are single-use. Transactional mail payloads are encrypted with libsodium and `APP_SECRET`; preserve that secret. Known and unknown accounts receive the same reply, including the same 503 when SMTP is unconfigured. The worker retries delivery; queued does not mean delivered.

Reset email language comes from `Accept-Language` (`en` or `fr`, English fallback), is stored inside the encrypted payload, and uses `translations/mail.<locale>.json`. Legacy queued payloads without locale remain readable. Emails contain both the manual code and `gpix://reset-password?token=...` link.

Domain port: `Authentication`; application orchestration: `Authenticate`; PDO/Symfony adapters: Infrastructure. Append-only Symfony Console fixtures reuse PDO instead of adding a parallel ORM.

References: [PasswordHasher](https://symfony.com/doc/current/security/passwords.html), [Mailer](https://symfony.com/doc/current/mailer.html), [RateLimiter](https://symfony.com/doc/7.4/rate_limiter.html). Development capture uses the Mailpit version pinned in Compose.
