-- Endgame Tournaments — Phase 2 migration
-- Run after schema.sql (001):
--   psql -U postgres -d endgame -f migrations/002_phase2.sql
--
-- Notes on constraint names:
--   PostgreSQL auto-names inline CHECK constraints as {table}_{column}_check.
--   If your install generated different names, run:
--     SELECT conname FROM pg_constraint WHERE conrelid='tournaments'::regclass AND contype='c';
--   and substitute below.

BEGIN;

-- ===========================================================================
-- 1. TOURNAMENTS — status enum, visibility, is_featured
-- ===========================================================================

-- 'active' → 'in_progress'; add 'archived'
ALTER TABLE tournaments DROP CONSTRAINT IF EXISTS tournaments_status_check;
UPDATE tournaments SET status = 'in_progress' WHERE status = 'active';
ALTER TABLE tournaments
    ADD CONSTRAINT tournaments_status_check
    CHECK (status IN ('draft', 'in_progress', 'finalised', 'archived'));

-- visibility: open (public registration) vs invite_only (direct adds only)
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS visibility VARCHAR(32) NOT NULL DEFAULT 'open'
        CHECK (visibility IN ('open', 'invite_only'));

-- is_featured: Admins only may set this; drives sort order on home page
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS is_featured BOOLEAN NOT NULL DEFAULT false;

-- sport was already added in schema.sql — no-op here.
-- scheduled_at on matches was already added in schema.sql — no-op here.

-- ===========================================================================
-- 2. MATCHES — status enum revision
-- ===========================================================================

-- 'scheduled' → 'pending'; 'completed' → 'accepted'; add 'disputed'
ALTER TABLE matches DROP CONSTRAINT IF EXISTS matches_status_check;
UPDATE matches SET status = 'pending'  WHERE status = 'scheduled';
UPDATE matches SET status = 'accepted' WHERE status = 'completed';
ALTER TABLE matches
    ADD CONSTRAINT matches_status_check
    CHECK (status IN ('pending', 'in_progress', 'accepted', 'disputed', 'bye'));

-- ===========================================================================
-- 3. TEAMS — rename created_by → owner_uid, per-owner name uniqueness
-- ===========================================================================

-- Rename column (FK is preserved automatically in PostgreSQL)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name='teams' AND column_name='created_by'
    ) THEN
        ALTER TABLE teams RENAME COLUMN created_by TO owner_uid;
    END IF;
END;
$$;

-- Per-owner case-insensitive name uniqueness
DROP INDEX IF EXISTS teams_owner_name_idx;
CREATE UNIQUE INDEX teams_owner_name_idx ON teams (owner_uid, lower(name));

-- ===========================================================================
-- 4. TOURNAMENT_TEAMS — add registered_at, status
-- ===========================================================================

ALTER TABLE tournament_teams
    ADD COLUMN IF NOT EXISTS registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE tournament_teams
    ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected'));

-- Existing rows (from seed or prior inserts) are assumed approved
UPDATE tournament_teams SET status = 'approved' WHERE status = 'pending';

-- ===========================================================================
-- 5. REEVALUATION_REQUESTS — rename reviewed_* → resolved_*; force_approved
-- ===========================================================================

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name='reevaluation_requests' AND column_name='reviewed_by'
    ) THEN
        ALTER TABLE reevaluation_requests RENAME COLUMN reviewed_by  TO resolved_by_uid;
        ALTER TABLE reevaluation_requests RENAME COLUMN reviewed_at  TO resolved_at;
    END IF;
END;
$$;

ALTER TABLE reevaluation_requests DROP CONSTRAINT IF EXISTS reevaluation_requests_status_check;
ALTER TABLE reevaluation_requests
    ADD CONSTRAINT reevaluation_requests_status_check
    CHECK (status IN ('pending', 'approved', 'rejected', 'force_approved'));

-- resolved_at and resolved_by_uid were renamed above; add if they somehow don't exist
ALTER TABLE reevaluation_requests
    ADD COLUMN IF NOT EXISTS resolved_at      TIMESTAMPTZ;
ALTER TABLE reevaluation_requests
    ADD COLUMN IF NOT EXISTS resolved_by_uid  INTEGER REFERENCES users(id);

-- ===========================================================================
-- 6. NOTIFICATIONS — new table
-- ===========================================================================

CREATE TABLE IF NOT EXISTS notifications (
    id        SERIAL      PRIMARY KEY,
    user_uid  INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type      VARCHAR(64) NOT NULL,
    payload   JSONB       NOT NULL DEFAULT '{}',
    read_at   TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
    ON notifications (user_uid, read_at)
    WHERE read_at IS NULL;

-- ===========================================================================
-- 7. updated_at trigger for notifications (read_at updates don't need it,
--    but notifications are append-only so the trigger is skipped intentionally)
-- ===========================================================================

-- Indexes for new columns
CREATE INDEX IF NOT EXISTS idx_tournaments_featured   ON tournaments (is_featured) WHERE is_featured = true;
CREATE INDEX IF NOT EXISTS idx_tournaments_visibility ON tournaments (visibility);
CREATE INDEX IF NOT EXISTS idx_tt_status             ON tournament_teams (tournament_id, status);

COMMIT;
