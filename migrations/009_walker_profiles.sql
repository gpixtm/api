-- Weight used by the app to estimate active calories; account data.
CREATE TABLE walker_profiles (
    user_id UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    weight_kg NUMERIC(4,1) CHECK (weight_kg BETWEEN 25 AND 300),
    pack_kg NUMERIC(3,1) CHECK (pack_kg BETWEEN 0 AND 60),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
