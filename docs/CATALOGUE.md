# Open-data trail catalogue

Decision of 28 September 2026: the shared catalogue (`docs/PUBLIC_TRAILS.md`) is enriched with trails acquired from open data, country by country, starting with the whole of France. The first source is OpenStreetMap (ODbL). Commercial sites (AllTrails, Visorando, Wikiloc, Komoot…) are never scraped.

Phones never copy the catalogue. They load pins for the visible map area and search on the server. A catalogue trail is stored on a phone only when the walker makes it available offline or starts walking it.

## Acquisition

`bin/catalogue.php` runs in the `catalogue-worker` service:

1. It downloads the country's extract from Geofabrik, resolving the ISO code through Geofabrik's index. The extract is only downloaded again when Geofabrik published a newer one.
2. osmium keeps the `route=hiking|foot|walking` relations with their ways and node locations.
3. The worker reads them into compact memory and plans trails and groups (`OsmCataloguePlan`).
4. It writes them in short batches under the library lock (`CatalogueWriter`).

Extracts and working files live in `CATALOGUE_DIR`. In production, `CATALOGUE_STORAGE` mounts it from the host, ideally a large data disk, never the database volume.

### What a relation becomes

- **Trail.** A relation with its own ways. Its ways are joined in member order, reversed where needed. A break starts a new segment rather than drawing a straight line. Pieces listed out of order are merged back. The line is simplified at 2 m and has no elevation. Broken relations, with more than 60 pieces, are skipped.
- **Itinerary group.** A relation containing other routes, such as the GR 20 or Via Podiensis superroutes. Members keep their order and roles (`main`, `stage`, `variant`, `link`, `excursion`, `approach`); several main routes are the group's stages. If the relation also has a line of at least 1 km, that line is the group's first member.
- **Skipped.** Unnamed relations (no name, ref or from/to) are left out.

Identifiers are name-based UUIDs of the source reference (`relation/<id>`), so they are the same on every server and after every run.

- **A line already shared.** If a walker, or another relation, already shared the same line (same fingerprint), that trail is reused.
- **Border trails.** A trail crossing a border keeps the most complete geometry across the countries' extracts.
- **Withdrawals.** A run hides the country's trails and groups the source no longer has. They are never deleted, because walks, reviews and copies refer to them. A run that finds less than half of the country's previous trails hides nothing, which protects against a truncated extract.

### Ways of St James

Routes named unambiguously (for example Via Podiensis, Camino Francés, Jakobsweg or "chemin de Saint-Jacques") are listed in the editorial collection `collection/st-james`. So are routes tagged `pilgrimage=yes` with a St James name. Only the outermost such route is listed; its stages come through it. Membership combines every country's runs.

Phones translate the collection's name from `details.editorial` (`st-james`).

### Search

`search_text` holds the folded words of the trail: its names, reference, from/to/via, the names of every group containing it, and the St James keywords when inside that collection. Folding (`CatalogueText`) is shared by stored text and queries:

- case, accents and punctuation are ignored;
- `St` is read as `Saint`;
- references lose their separators (`GR 20` = `gr20`);
- equivalent words match each other (`Compostelle` ↔ `Santiago`, `Jacques`, `Jakobsweg`…).

Walkers' trails are searchable by name (`008_catalogue_search.php` backfills existing ones).

## Operations (production)

| Command | Effect |
| --- | --- |
| `make prod-catalogue-acquire COUNTRY=FR` | Queues the whole country. The worker starts it within a minute; a queued or running country is not queued twice. |
| `make prod-catalogue-runs` | Latest runs: status, statistics (created, updated, reused, broken, groups, hidden, seconds, peak memory) and error. |
| `make prod-catalogue-rollback RUN=<id>` | Hides what a run created. |

- Every country acquired once is queued again each night after 02:00 UTC.
- A run interrupted by a deployment or restart is marked failed; the next night retries it.
- Memory is limited by `CATALOGUE_MEMORY` (default 3G).
- Logs: `docker compose … logs catalogue-worker`.

## API (authenticated)

| Request | Response |
| --- | --- |
| `GET /api/public-trails/area?south&west&north&east&limit=<≤300>` | `{trails: [summary + outline], truncated}`. Visible trails whose bounds meet the area, longest first. 422 `invalidMapArea` for an invalid area. |
| `GET /api/catalogue/search?q=&cursor=&limit=<≤60>` | `{items: [{type: 'group', group} \| {type: 'trail', trail}], next}`. Groups come first: editorial, then from sources, then walkers'. Then trails. Every query word must match. Without any match, similar spellings are tried. An empty query browses everything. `next` is an opaque cursor, or null. |
| `GET /api/public-trails/{id}` | Adds `source`, `licence`, `attribution`, `details`, `metres`, `reviews`, `average`, `hidden`, `change` and `groups`. `groups` is `[{groups: [outermost … direct parent], role, stage}]`. |
| `GET /api/trail-groups/{id}` | Group summary, `description`, `details`, `licence`, `attribution`, `parents`, and `members: [{role, stage, type, trail (with outline) \| group}]` in order. |
| `PUT /api/trail-groups/{id}` `{kind, name, description, members: [{trailId \| groupId, role?}]}` | Creates or replaces the caller's group. The phone chooses the identifier, so a retry changes nothing. 403 `groupNotYours`; 422 `invalidGroup`, `unknownGroupMember`, `groupContainsItself`. |
| `DELETE /api/trail-groups/{id}` | Hides the caller's group for everyone. The call is idempotent. |
| `GET /api/my-trail-groups` | `{groups}`: the caller's groups, most recently changed first. |

Summaries:

- **Trail:** `{id, name, author, metres, bounds, reviews, average, source, ref, change}`.
- **Group:** `{id, kind, name, editorial, ref, metres, trailCount, bounds, source, author, mine}`.

`details` may contain:

- `ref`, `network`, `marking` (`osmc:symbol`), `colour`, `from`, `to`, `via`, `operator`, `website`, `wikidata`, `wikipedia`;
- `roundtrip`, `pilgrimage`, `distance`, `ascent`, `descent`;
- `descriptions` (`default` and per language);
- `osm {type, id}`;
- `editorial`, on collections.

The legacy `GET /api/public-trails?since=` index now lists only walkers' trails.

A finished walk may carry `walk.reference {name, segments}`: the simplified line it followed (at most 20,000 points), so history keeps it without the trail.

## Schema

- `007_catalogue.sql` creates:
  - `catalogue_runs`;
  - the source, licence, details, search and withdrawal columns of `public_trails`;
  - `trail_groups` and `trail_group_members`;
  - the area (GiST on the bounds box) and trigram search indexes, using `pg_trgm` (no PostGIS needed).
- `008_catalogue_search.php` makes existing walkers' trails searchable without changing their `change`.
- `005_publish_existing.php` is frozen at the schema of 004, so that databases upgrading from 003 still migrate.
- `tests/catalogue.php` covers acquisition, repeated runs, withdrawals, St James, browsing and groups. `tests/catalogue_upgrade.php` upgrades a representative schema 003 database.
