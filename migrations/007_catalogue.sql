-- Open-data catalogue: trails acquired from sources such as OpenStreetMap join the walkers'
-- shared trails, with their details, groups (itineraries and collections) and search.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- One acquisition of one country from one source, queued by an operator or the nightly update.
CREATE TABLE catalogue_runs (
    id BIGSERIAL PRIMARY KEY,
    country TEXT NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
    source TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'rolled_back')),
    queued_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at TIMESTAMPTZ,
    finished_at TIMESTAMPTZ,
    stats JSONB NOT NULL DEFAULT '{}',
    error TEXT
);
CREATE INDEX catalogue_runs_status ON catalogue_runs(status, id);

-- `source` is 'gpix' for trails shared by walkers; acquired trails keep their origin and
-- licence. `hidden` withdraws a trail that disappeared from its source without deleting the
-- walks, reviews and private copies that refer to it.
ALTER TABLE public_trails
    ADD COLUMN source TEXT NOT NULL DEFAULT 'gpix',
    ADD COLUMN source_ref TEXT,
    ADD COLUMN licence TEXT,
    ADD COLUMN attribution TEXT,
    ADD COLUMN details JSONB NOT NULL DEFAULT '{}',
    ADD COLUMN ref_key TEXT,
    ADD COLUMN search_text TEXT NOT NULL DEFAULT '',
    ADD COLUMN country TEXT,
    ADD COLUMN run_id BIGINT REFERENCES catalogue_runs(id) ON DELETE SET NULL,
    ADD COLUMN created_run_id BIGINT REFERENCES catalogue_runs(id) ON DELETE SET NULL,
    ADD COLUMN hidden BOOLEAN NOT NULL DEFAULT false;
CREATE UNIQUE INDEX public_trails_source ON public_trails(source, source_ref) WHERE source_ref IS NOT NULL;
CREATE INDEX public_trails_area ON public_trails USING gist (box(point(west, south), point(east, north))) WHERE NOT hidden;
CREATE INDEX public_trails_search ON public_trails USING gin (search_text gin_trgm_ops) WHERE NOT hidden;
CREATE INDEX public_trails_ref ON public_trails(ref_key text_pattern_ops) WHERE ref_key IS NOT NULL AND NOT hidden;
CREATE INDEX public_trails_country ON public_trails(source, country) WHERE source_ref IS NOT NULL;

-- A group is an ordered itinerary (stages, variants, links: GR 20, Via Podiensis) or an
-- unordered collection (Ways of St James, a walker's summer trails). Groups nest.
CREATE SEQUENCE trail_group_changes;
CREATE TABLE trail_groups (
    id UUID PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('itinerary', 'collection')),
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'gpix',
    source_ref TEXT,
    licence TEXT,
    attribution TEXT,
    details JSONB NOT NULL DEFAULT '{}',
    ref_key TEXT,
    search_text TEXT NOT NULL DEFAULT '',
    author_id UUID REFERENCES users(id) ON DELETE SET NULL,
    country TEXT,
    run_id BIGINT REFERENCES catalogue_runs(id) ON DELETE SET NULL,
    created_run_id BIGINT REFERENCES catalogue_runs(id) ON DELETE SET NULL,
    hidden BOOLEAN NOT NULL DEFAULT false,
    -- Aggregates refreshed whenever members change: main line length, trail count, bounds.
    metres DOUBLE PRECISION NOT NULL DEFAULT 0,
    trail_count INTEGER NOT NULL DEFAULT 0,
    south DOUBLE PRECISION,
    west DOUBLE PRECISION,
    north DOUBLE PRECISION,
    east DOUBLE PRECISION,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    change BIGINT NOT NULL DEFAULT nextval('trail_group_changes')
);
CREATE UNIQUE INDEX trail_groups_source ON trail_groups(source, source_ref) WHERE source_ref IS NOT NULL;
CREATE INDEX trail_groups_search ON trail_groups USING gin (search_text gin_trgm_ops) WHERE NOT hidden;
CREATE INDEX trail_groups_ref ON trail_groups(ref_key text_pattern_ops) WHERE ref_key IS NOT NULL AND NOT hidden;
CREATE INDEX trail_groups_author ON trail_groups(author_id) WHERE author_id IS NOT NULL AND NOT hidden;

-- Each member is a trail or a nested group, in order, with its role in the group.
CREATE TABLE trail_group_members (
    group_id UUID NOT NULL REFERENCES trail_groups(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    trail_id UUID REFERENCES public_trails(id) ON DELETE CASCADE,
    member_group_id UUID REFERENCES trail_groups(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('main', 'stage', 'variant', 'link', 'excursion', 'approach')),
    PRIMARY KEY (group_id, position),
    CHECK ((trail_id IS NULL) <> (member_group_id IS NULL)),
    CHECK (member_group_id IS DISTINCT FROM group_id)
);
CREATE INDEX trail_group_members_trail ON trail_group_members(trail_id) WHERE trail_id IS NOT NULL;
CREATE INDEX trail_group_members_group ON trail_group_members(member_group_id) WHERE member_group_id IS NOT NULL;
