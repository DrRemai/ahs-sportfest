-- Endgame Tournaments — Session 13: PostgreSQL LISTEN/NOTIFY triggers for SSE
-- Run after 005_teams.sql

-- ---------------------------------------------------------------------------
-- 1. notify_user_event
--    Fires on new notification rows.
--    Channel: user_{user_uid}
--    Payload: { event, id, type, payload }
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION notify_user_event()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify(
        'user_' || NEW.user_uid::text,
        json_build_object(
            'event',   'notification',
            'id',      NEW.id,
            'type',    NEW.type,
            'payload', NEW.payload
        )::text
    );
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- 2. notify_tournament_event
--    Fires on match inserts and on status/score/winner updates.
--    Channel: tournament_{tournament_id}
--    Payload: { event, match_id, tournament_id, round, match_number,
--               status, home_score, away_score, winner_id }
-- ---------------------------------------------------------------------------
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
            'winner_id',      NEW.winner_id
        )::text
    );
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- 3. notify_global_tournament
--    Fires when a tournament's status, name, or is_featured changes.
--    Channel: tournaments
--    Payload: { event, id, name, status, is_featured }
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION notify_global_tournament()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify(
        'tournaments',
        json_build_object(
            'event',       'tournament_update',
            'id',          NEW.id,
            'name',        NEW.name,
            'status',      NEW.status,
            'is_featured', NEW.is_featured
        )::text
    );
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- Triggers
-- ---------------------------------------------------------------------------

DROP TRIGGER IF EXISTS notifications_after_insert ON notifications;
CREATE TRIGGER notifications_after_insert
    AFTER INSERT ON notifications
    FOR EACH ROW
    EXECUTE FUNCTION notify_user_event();

DROP TRIGGER IF EXISTS matches_after_update ON matches;
CREATE TRIGGER matches_after_update
    AFTER UPDATE OF status, home_score, away_score, winner_id ON matches
    FOR EACH ROW
    EXECUTE FUNCTION notify_tournament_event();

DROP TRIGGER IF EXISTS matches_after_insert ON matches;
CREATE TRIGGER matches_after_insert
    AFTER INSERT ON matches
    FOR EACH ROW
    EXECUTE FUNCTION notify_tournament_event();

DROP TRIGGER IF EXISTS tournaments_after_update ON tournaments;
CREATE TRIGGER tournaments_after_update
    AFTER UPDATE OF status, name, is_featured ON tournaments
    FOR EACH ROW
    EXECUTE FUNCTION notify_global_tournament();
