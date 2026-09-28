-- A place is either added by a walker where they stand, or imported onto a trail from a GPX
-- file's waypoints. Every existing place was added on site.
ALTER TABLE trail_places ADD COLUMN IF NOT EXISTS origin TEXT NOT NULL DEFAULT 'onSite';
ALTER TABLE trail_places DROP CONSTRAINT IF EXISTS trail_places_origin;
ALTER TABLE trail_places ADD CONSTRAINT trail_places_origin CHECK (origin IN ('onSite', 'imported'));
