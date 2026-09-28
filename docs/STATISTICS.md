# Trail statistics

The API keeps running totals for each GPX a user walks repeatedly, so the mobile app can compare the current walk with the usual one without reloading every past walk.

## Model

- `trail_statistics (owner_id, trail_id)`: `walks`, total `metres` and total active `seconds`. The usual average speed is `metres / seconds`, which weights each walk by its distance.
- `trail_statistic_walks (owner_id, walk_id)`: the contribution each counted walk made. Because contributions are stored, a change or deletion adjusts the totals by that walk's difference, never by rescanning walks.

A synced finished walk (`payload.walk.ended` present) counts for `walk.routeId`, falling back to `walk.sourceTrailId`. `sourceTrailId` is the GPX followed with guidance. `routeId` is set by the app when a free walk creates a route or stays on a known one. Distance is the haversine length of the recorded segments on the same axis as the app, so segment gaps add nothing. A walk counts only from **500 m and 60 active seconds**, so an accidental start does not skew averages. `App\Domain\WalkContribution` and the mobile `WalkContribution` apply the same rule.

## Consistency

Statistics stay private to their owner even though the trail itself is shared ([shared trails](PUBLIC_TRAILS.md)).


`PostgresTrailStatistics::record` runs inside the mutation transaction of `PostgresTrailStore::apply`, after revision and ownership checks:

- A receipt replay returns the stored response and does not count twice.
- A conflict changes nothing.
- An update, such as adding a health summary, replaces the contribution.
- A tombstone removes it.

Statistics survive deletion of the GPX itself, because the walks still exist.

## API

`GET /api/statistics` (authenticated) returns the current user's totals:

```json
[{"trailId": "…", "walks": 4, "metres": 20012.4, "seconds": 14400}]
```

The app caches them after each full sync, once its outbox has been pushed. It adds a walk finished offline locally until the next refresh. An app talking to an older API without this route keeps working, just without comparisons.

## Migration

`003_trail_statistics.sql` creates both tables and backfills owned, non-deleted, finished walks, using the same rule in SQL. Unowned legacy rows and malformed legacy source identifiers are skipped. `tests/statistics_upgrade.php` runs in `make ci`: it builds a separate disposable database at schema 002 with representative walks, applies 003, and compares the result with the PHP rule.
