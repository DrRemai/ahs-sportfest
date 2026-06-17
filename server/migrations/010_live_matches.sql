-- Endgame Tournaments — Session 21: is_live flag on matches
-- Run after 009_user_delete_fks.sql

-- 1. Column + index
ALTER TABLE matches ADD COLUMN IF NOT EXISTS is_live boolean NOT NULL DEFAULT false;
CREATE INDEX IF NOT EXISTS idx_matches_live ON matches(tournament_id, is_live) WHERE is_live = true;

-- 2. Update notify_tournament_event to include is_live in the SSE payload
--    and to fire on is_live changes in addition to existing columns.
CREATE OR REPLACE FUNCTION notify_tournament_event()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify(
        'tournament_' || NEW.tournament_id::text,
        json_build_object(
            'event',          CASE WHEN TG_OP = 'INSERT' THEN 'match_created' ELSE 'match_update' END,
            'match_id',       NEW.id,
            'tournament_id',  NEW.tournament_id,
            'round',          NEW.round,
            'match_number',   NEW.match_number,
            'status',         NEW.status,
            'home_score',     NEW.home_score,
            'away_score',     NEW.away_score,
            'winner_id',      NEW.winner_id,
            'is_live',        NEW.is_live
        )::text
    );
    RETURN NEW;
END;
$$;

-- 3. Rebuild the UPDATE trigger to watch is_live in addition to existing columns
DROP TRIGGER IF EXISTS matches_after_update ON matches;
CREATE TRIGGER matches_after_update
    AFTER UPDATE OF status, home_score, away_score, winner_id, is_live ON matches
    FOR EACH ROW
    EXECUTE FUNCTION notify_tournament_event();
