-- Places walkers add on a shared trail from where they stand (a viewpoint, a spring…).
CREATE SEQUENCE trail_place_changes;
CREATE TABLE trail_places (
    id UUID PRIMARY KEY,
    trail_id UUID NOT NULL REFERENCES public_trails(id) ON DELETE CASCADE,
    author_id UUID REFERENCES users(id) ON DELETE SET NULL,
    lat DOUBLE PRECISION NOT NULL CHECK (lat BETWEEN -90 AND 90),
    lon DOUBLE PRECISION NOT NULL CHECK (lon BETWEEN -180 AND 180),
    elevation DOUBLE PRECISION,
    name TEXT NOT NULL,
    comment TEXT NOT NULL DEFAULT '',
    -- Tombstones travel to phones like any other change.
    deleted BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    change BIGINT NOT NULL DEFAULT nextval('trail_place_changes')
);
CREATE INDEX trail_places_change ON trail_places(change);
CREATE INDEX trail_places_trail ON trail_places(trail_id) WHERE NOT deleted;
