-- Endgame Tournaments — Session 16: global team-name claim system
-- Run after 007_indexes.sql

-- Drop the old per-owner unique index
DROP INDEX IF EXISTS teams_owner_name_idx;

-- New table: claims a team name to a single owner, globally.
-- Once a name is claimed, no other user may use it in any sport.
-- The same user may own multiple teams with that name (e.g. Bears FC + Bears Chess).
CREATE TABLE IF NOT EXISTS team_name_claims (
    name_lower  TEXT        PRIMARY KEY,
    owner_uid   INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    claimed_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_team_name_claims_owner ON team_name_claims(owner_uid);

-- Backfill: for each distinct name (case-insensitive), claim it to whichever
-- owner created a team with that name first.
INSERT INTO team_name_claims (name_lower, owner_uid, claimed_at)
SELECT DISTINCT ON (lower(name)) lower(name), owner_uid, created_at
FROM teams
ORDER BY lower(name), created_at ASC
ON CONFLICT (name_lower) DO NOTHING;
