-- Session 5: registration flow additions
-- Run after: schema.sql → 002_phase2.sql → seed.sql
--   psql -U postgres -d endgame -f migrations/003_phase3.sql

BEGIN;

-- teams.sport: describes what sport this team competes in.
-- Decision: sport lives on teams (not tournament_teams) because it is a
-- persistent property of the club/group, not a per-tournament attribute.
-- Nullable so existing rows are unaffected without a backfill.
ALTER TABLE teams ADD COLUMN IF NOT EXISTS sport VARCHAR(64);

COMMIT;
