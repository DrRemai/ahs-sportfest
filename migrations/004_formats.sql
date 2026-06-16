-- Session 7: full formats engine
-- Run after: schema.sql → 002_phase2.sql → 003_phase3.sql → seed.sql
--   psql -U postgres -d endgame -f migrations/004_formats.sql
--
-- Note: The spec names this 003_formats.sql, but 003_phase3.sql already exists.
-- Named 004 to maintain strict ordering.

BEGIN;

-- ===========================================================================
-- 1. TOURNAMENTS — new format values, format_config
-- ===========================================================================

-- Rename format values to the short-form identifiers used by the format engine.
-- 'round_robin' and 'swiss' are unchanged; only the elimination names shorten.
UPDATE tournaments SET format = 'single_elim'  WHERE format = 'single_elimination';
UPDATE tournaments SET format = 'double_elim'  WHERE format = 'double_elimination';

-- Drop the old constraint (may have been added ad-hoc or not at all).
ALTER TABLE tournaments DROP CONSTRAINT IF EXISTS tournaments_format_check;

-- New constraint covers all engine-supported values.
ALTER TABLE tournaments
    ADD CONSTRAINT tournaments_format_check
    CHECK (format IN ('single_elim', 'double_elim', 'round_robin', 'swiss', 'multi_stage'));

-- format_config holds format-specific settings as JSON:
--   single_elim / double_elim : { "total_wb_rounds": N }
--   round_robin               : { "points_win": 3, "points_draw": 1, "points_loss": 0 }
--   swiss                     : { "rounds": N }
--   multi_stage               : { "stages": [{ "format": "...", "config": {}, "advance_count": N }, ...] }
ALTER TABLE tournaments
    ADD COLUMN IF NOT EXISTS format_config JSONB;

-- ===========================================================================
-- 2. TOURNAMENT_STAGES — multi-stage orchestration
-- ===========================================================================

CREATE TABLE IF NOT EXISTS tournament_stages (
    id            SERIAL       PRIMARY KEY,
    tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    stage_order   INTEGER      NOT NULL,             -- 1-indexed execution order
    format        VARCHAR(32)  NOT NULL,             -- format engine for this stage
    config        JSONB        NOT NULL DEFAULT '{}',
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'in_progress', 'complete')),
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (tournament_id, stage_order)
);

-- ===========================================================================
-- 3. MATCHES — stage link, bracket side, display ordering
-- ===========================================================================

-- stage_id: which stage this match belongs to (null = standalone tournament).
ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS stage_id INTEGER REFERENCES tournament_stages(id) ON DELETE SET NULL;

-- bracket_side: distinguishes winners / losers / grand_final in double elim.
-- Default 'none' so all pre-existing rows are valid without a backfill.
ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS bracket_side VARCHAR(16) NOT NULL DEFAULT 'none'
        CHECK (bracket_side IN ('none', 'winners', 'losers', 'grand_final'));

-- match_order: display position within a round (separate from match_number,
-- which is used for the next-round slot calculation).
ALTER TABLE matches
    ADD COLUMN IF NOT EXISTS match_order INTEGER NOT NULL DEFAULT 0;

-- ===========================================================================
-- 4. GROUPS — group stage (round robin group stage or multi-stage group phase)
-- ===========================================================================

CREATE TABLE IF NOT EXISTS groups (
    id            SERIAL       PRIMARY KEY,
    tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    stage_id      INTEGER      REFERENCES tournament_stages(id) ON DELETE SET NULL,
    name          VARCHAR(64)  NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS group_teams (
    group_id  INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    team_id   INTEGER NOT NULL REFERENCES teams(id)  ON DELETE CASCADE,
    PRIMARY KEY (group_id, team_id)
);

-- ===========================================================================
-- 5. SWISS_PAIRINGS — per-round pairing record for conflict detection
-- ===========================================================================

CREATE TABLE IF NOT EXISTS swiss_pairings (
    id            SERIAL       PRIMARY KEY,
    tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    round         INTEGER      NOT NULL,
    team1_id      INTEGER      NOT NULL REFERENCES teams(id),
    team2_id      INTEGER      NOT NULL REFERENCES teams(id),
    match_id      INTEGER      REFERENCES matches(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_swiss_pairings_tournament ON swiss_pairings (tournament_id);

-- ===========================================================================
-- 6. Indexes
-- ===========================================================================

CREATE INDEX IF NOT EXISTS idx_matches_stage     ON matches (stage_id) WHERE stage_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_matches_bracket   ON matches (tournament_id, bracket_side);

COMMIT;
