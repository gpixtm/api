CREATE TABLE trails (
    id UUID PRIMARY KEY,
    payload JSONB NOT NULL,
    revision BIGINT NOT NULL CHECK (revision > 0),
    deleted BOOLEAN NOT NULL DEFAULT false,
    mutation UUID NOT NULL
);
CREATE TABLE receipts (
    operation_id UUID PRIMARY KEY,
    digest CHAR(64) NOT NULL,
    response JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
