-- Endgame Tournaments — Session 8: teams expansion
-- Run after 004_formats.sql

ALTER TABLE teams ADD COLUMN IF NOT EXISTS description VARCHAR(280);
ALTER TABLE teams ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'active'
    CHECK (status IN ('active', 'archived'));

CREATE TABLE IF NOT EXISTS team_members (
    id         SERIAL       PRIMARY KEY,
    team_id    INTEGER      NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    name       VARCHAR(80)  NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_team_members_team ON team_members(team_id);
