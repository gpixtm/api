# Shared trails and reviews

Trails acquired from open data, groups, area browsing and search are described in [the catalogue guide](CATALOGUE.md).

Every trail added by any account is stored once and visible to every account. This follows the product decision of 28 September 2026. What stays private to the owner:

- walks, speeds and statistics;
- health summaries;
- planned days.

## Model

### `public_trails`

A row holds one shared line:

- the trail's `name`, `description`, `segments`, `pois` and `estimated`;
- a simplified `outline` (at most about 120 points), with `metres` and bounds;
- `author_id`, which becomes null if the account is deleted;
- the review aggregates `reviews` and `rating_total`;
- `change`, which increases on every visible change.

`fingerprint` is unique.

### `trails.public_id`

This column links a private library entry to the shared trail it published or reused. Library identifiers are unique **per owner**, `(owner_id, id)`. Two accounts adding the same line can therefore hold the same identifier without seeing each other's copy.

### `trail_reviews (trail_id, user_id)`

Each walker has one review per trail:

- a `rating` from 1 to 5;
- a trimmed `comment` of at most 2,000 characters, rejected when damaged.

## Identity and deduplication

`App\Domain\TrailFingerprint` computes a trail's identity from its line only.

1. Each point is quantized with `floor(degrees × 100000 + 0.5)`, which is about one metre.
2. Consecutive equal points collapse into one.
3. Segments with fewer than two points are dropped.
4. The remaining lines are serialized as `gpix-trail-v1\n` + `lat,lon;…` per segment, joined by `\n`.
5. That text is hashed with SHA-256.

Names, descriptions, elevations and places do not affect the fingerprint. The reverse direction produces a different trail.

`publicId(fingerprint)` is a version 5 UUID in the fixed namespace `8f5c2a61-3b7e-4d9a-a1c4-6e0b9d2f7a35`. The mobile app gives new lines this identifier offline, so the same GPX meets itself on every phone. Both repositories test the same vector; keep them identical.

`POST /api/sync` of a library trail (no `walk`, not deleted) publishes it inside the mutation transaction:

- If the fingerprint already exists, the private copy is linked to that trail, and the first name and description are kept.
- Otherwise the trail is shared under its library identifier. If another trail already uses that identifier, the name-based identifier is used instead.

The ACK and the receipt gain `publicId`, and `GET /api/trails` rows carry it too. Deleting a private copy never unpublishes the shared trail. Walks are never published.

All writes, sync mutations and reviews alike, take the library advisory lock (`7140931`). `change` values therefore commit in order, and a phone resuming after its last seen change cannot miss a trail.

## Reviews and completion

A review is accepted only when one of the walker's synced, finished walks covered at least 90 % of the trail (`WalkCoverage::COMPLETE`). A walk counts for this trail through either of these fields:

- `walk.sourceTrailId`, for a guided walk;
- `walk.routeId`, for a free walk that created or matched a route.

Either field may name the shared identifier or one of the walker's private copies.

Coverage is the share of the trail's length passed within 50 m, in either direction. The trail is sampled every 20 m. The walk is densified every 20 m inside each recorded segment, so a recording gap adds no line. The check runs at review time on the stored walks; nothing is trusted from the phone.

## API (authenticated)

| Request | Response |
| --- | --- |
| `GET /api/public-trails?since=<change>&limit=<≤500>` | `{trails: [{id, fingerprint, name, author, metres, bounds: [south, west, north, east], outline: [[[lat, lon]…]…], reviews, average, change}], next, more}`. A phone stores `next` with the page and repeats while `more`. |
| `GET /api/public-trails/{id}` | `{id, name, description, segments, pois, estimated, author, createdAt}`: the mobile trail payload shape, without days or walk. |
| `GET /api/public-trails/{id}/reviews` | `{trailId, count, average, completion, canReview, reviews: [{author, rating, comment, createdAt, updatedAt, mine}]}`. `completion` is the viewer's best coverage, or null without a walk. The viewer's own review comes first. |
| `PUT /api/public-trails/{id}/review` `{rating, comment}` | The same shape as the reviews response. Status 403 with code `completionRequired` before a complete walk; 422 with `invalidRating` or `reviewTooLong`. |
| `DELETE /api/public-trails/{id}/review` | The same shape as the reviews response. It removes only the caller's review. |

An unknown or malformed identifier returns 404.

## Migration

- `004_public_trails.sql` creates the tables, the per-owner identity index and `public_id`.
- `005_publish_existing.php` publishes every owned, non-deleted library trail that has a line:
  - The legacy owner's copies go first, then older accounts, so a line added twice keeps its first name and identifier.
  - Places-only entries, walks, tombstones and unowned rows stay private.
  - Running it again changes nothing.

`bin/migrate.php` applies `.sql` and `.php` files in filename order within one transaction. A PHP migration returns a callable that receives the PDO connection.

`tests/public_trails_upgrade.php` builds a separate disposable database at schema 003 and applies both migrations. It then checks that private libraries are unchanged byte for byte.

## Places added on a trail

`trail_places` holds the places of a shared trail: position (with optional elevation), `name` (1–200 characters) and `comment` (at most 2,000), `origin`, `author_id`, a `deleted` tombstone and its own `change` sequence. `006_trail_places.sql` creates it; `010_place_origin.sql` adds `origin`.

A place has one of two origins:

- **`onSite`**: a walker added it where they stand. It lies within 100 m of the line. The phone checks the walker's real position; the API checks the distance. Every place created before `010` is `onSite`.
- **`imported`**: the waypoints of a GPX file, attached to the trail by a walker (hostels, springs, churches along the Way of St James). It lies within 5 km of the line, since a hostel in the village may be off the path.

A new imported place is never added twice. If a visible place of the same trail lies within 30 m and has an equivalent name (case, accents and punctuation ignored, as in search), the API returns that place, under its own identifier, instead of creating another. The phone then replaces its pending copy with it. A place added on site is always created.

| Request | Response |
| --- | --- |
| `GET /api/public-places?since=<change>` | `{places: [{id, trailId, lat, lon, elevation, name, comment, origin, author, mine, deleted, updatedAt, change}], next, more}`, tombstones included, oldest change first. |
| `PUT /api/public-trails/{trailId}/places/{placeId}` `{lat, lon, elevation, name, comment, origin?}` | The place, or the existing place a new imported one duplicates. The phone chooses `placeId`, so a retry changes nothing. Without `origin`, a new place is `onSite` and an edited place keeps its origin, so older apps keep working. 422 `placeTooFar` beyond the origin's distance of the line (between vertices included), 422 `invalidPlace` (including an unknown origin), 403 `placeNotYours` for another walker's place, 404 for an unknown trail. |
| `DELETE /api/public-trails/{trailId}/places/{placeId}` | The tombstone; idempotent, author only. |

Writes take the same library lock as sync and reviews, so place changes commit in `change` order. `tests/place_origin_upgrade.php` upgrades a database with existing places and tombstones.
