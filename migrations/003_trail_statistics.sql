-- Running statistics per followed GPX, and each finished walk's contribution to them.
CREATE TABLE trail_statistics (
    owner_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    trail_id UUID NOT NULL,
    walks INTEGER NOT NULL CHECK (walks > 0),
    metres DOUBLE PRECISION NOT NULL,
    seconds BIGINT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (owner_id, trail_id)
);
CREATE TABLE trail_statistic_walks (
    owner_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    walk_id UUID NOT NULL,
    trail_id UUID NOT NULL,
    metres DOUBLE PRECISION NOT NULL,
    seconds BIGINT NOT NULL,
    PRIMARY KEY (owner_id, walk_id)
);
-- Backfill existing finished walks with the same rule as App\Domain\WalkContribution.
INSERT INTO trail_statistic_walks (owner_id, walk_id, trail_id, metres, seconds)
SELECT t.owner_id, t.id, COALESCE(t.payload->'walk'->>'routeId', t.payload->'walk'->>'sourceTrailId')::uuid, d.metres, (t.payload->'walk'->>'seconds')::bigint
FROM trails t
CROSS JOIN LATERAL (
    SELECT COALESCE(sum(6371008.8 * 2 * asin(sqrt(least(1, greatest(0,
        power(sin(radians(pair.lat - pair.previous_lat) / 2), 2)
        + cos(radians(pair.previous_lat)) * cos(radians(pair.lat)) * power(sin(radians(pair.lon - pair.previous_lon) / 2), 2)))))), 0) AS metres
    FROM (
        SELECT (p.value->>0)::float8 AS lat, (p.value->>1)::float8 AS lon,
            lag((p.value->>0)::float8) OVER w AS previous_lat, lag((p.value->>1)::float8) OVER w AS previous_lon
        FROM jsonb_array_elements(CASE WHEN jsonb_typeof(t.payload->'segments') = 'array' THEN t.payload->'segments' ELSE '[]'::jsonb END) WITH ORDINALITY AS s(value, segment)
        CROSS JOIN LATERAL jsonb_array_elements(s.value) WITH ORDINALITY AS p(value, point)
        WINDOW w AS (PARTITION BY s.segment ORDER BY p.point)
    ) pair
    WHERE pair.previous_lat IS NOT NULL
) d
WHERE NOT t.deleted
  AND t.owner_id IS NOT NULL
  AND jsonb_typeof(t.payload->'walk') = 'object'
  AND jsonb_typeof(t.payload->'walk'->'ended') = 'string'
  AND jsonb_typeof(t.payload->'walk'->'seconds') = 'number'
  AND COALESCE(t.payload->'walk'->>'routeId', t.payload->'walk'->>'sourceTrailId') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
  AND d.metres >= 500
  AND (t.payload->'walk'->>'seconds')::bigint >= 60;
INSERT INTO trail_statistics (owner_id, trail_id, walks, metres, seconds)
SELECT owner_id, trail_id, count(*), sum(metres), sum(seconds)
FROM trail_statistic_walks
GROUP BY owner_id, trail_id;
