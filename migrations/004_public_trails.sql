-- Collaborative trails: every shared line is stored once, whoever added it.
CREATE SEQUENCE public_trail_changes;
CREATE TABLE public_trails (
    id UUID PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    segments JSONB NOT NULL,
    pois JSONB NOT NULL,
    estimated BOOLEAN NOT NULL DEFAULT false,
    outline JSONB NOT NULL,
    metres DOUBLE PRECISION NOT NULL,
    south DOUBLE PRECISION NOT NULL,
    west DOUBLE PRECISION NOT NULL,
    north DOUBLE PRECISION NOT NULL,
    east DOUBLE PRECISION NOT NULL,
    author_id UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    reviews INTEGER NOT NULL DEFAULT 0 CHECK (reviews >= 0),
    rating_total INTEGER NOT NULL DEFAULT 0 CHECK (rating_total >= 0),
    -- Increases on every change a phone's cached catalogue must pick up.
    change BIGINT NOT NULL DEFAULT nextval('public_trail_changes')
);
CREATE INDEX public_trails_change ON public_trails(change);

-- A private library entry points to the shared trail it published or reused.
ALTER TABLE trails ADD COLUMN public_id UUID REFERENCES public_trails(id);
-- Identifiers are per owner: two walkers adding the same line share its identity.
ALTER TABLE trails DROP CONSTRAINT trails_pkey;
CREATE UNIQUE INDEX trails_owner_identity ON trails(owner_id, id) NULLS NOT DISTINCT;
CREATE INDEX trails_public ON trails(public_id) WHERE public_id IS NOT NULL;

CREATE TABLE trail_reviews (
    trail_id UUID NOT NULL REFERENCES public_trails(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (trail_id, user_id)
);
CREATE INDEX trail_reviews_recent ON trail_reviews(trail_id, updated_at DESC);
