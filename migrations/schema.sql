-- Endgame Tournaments — PostgreSQL schema
-- Run as: psql -U postgres -d endgame -f schema.sql

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id            SERIAL       PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL,
    display_name  VARCHAR(128) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin      BOOLEAN      NOT NULL DEFAULT false,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Case-insensitive uniqueness enforced at the index level, not a UNIQUE column,
-- so application code must always query via lower(username).
CREATE UNIQUE INDEX users_username_ci_idx ON users (lower(username));

-- ---------------------------------------------------------------------------
-- Tournaments
-- ---------------------------------------------------------------------------
CREATE TABLE tournaments (
    id          SERIAL       PRIMARY KEY,
    name        VARCHAR(256) NOT NULL,
    sport       VARCHAR(64)  NOT NULL,
    -- Kept open-ended; validation happens in application code.
    -- Known values: single_elimination, double_elimination, round_robin, swiss
    format      VARCHAR(64)  NOT NULL,
    status      VARCHAR(32)  NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft', 'active', 'finalised')),
    description TEXT,
    created_by  INTEGER      NOT NULL REFERENCES users(id),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- Tournament-scoped roles (Organiser / Staff)
-- One row per user per tournament. A user may hold different roles across
-- different tournaments simultaneously.
-- ---------------------------------------------------------------------------
CREATE TABLE tournament_roles (
    id             SERIAL      PRIMARY KEY,
    tournament_id  INTEGER     NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    user_id        INTEGER     NOT NULL REFERENCES users(id)       ON DELETE CASCADE,
    role           VARCHAR(32) NOT NULL CHECK (role IN ('organiser', 'staff')),
    assigned_by    INTEGER     NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tournament_id, user_id)
);

-- ---------------------------------------------------------------------------
-- Teams (persistent entities — not per-tournament strings)
-- Full team profile schema is deferred; this stub holds identity columns and
-- leaves room for logo, home city, etc. in a later session.
-- ---------------------------------------------------------------------------
CREATE TABLE teams (
    id          SERIAL       PRIMARY KEY,
    name        VARCHAR(128) NOT NULL,
    short_name  VARCHAR(16),
    logo_url    VARCHAR(512),
    created_by  INTEGER      REFERENCES users(id),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- Tournament ↔ Team junction
-- A team can participate in many tournaments; seeding is per-tournament.
-- ---------------------------------------------------------------------------
CREATE TABLE tournament_teams (
    id             SERIAL      PRIMARY KEY,
    tournament_id  INTEGER     NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    team_id        INTEGER     NOT NULL REFERENCES teams(id),
    seed           INTEGER,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tournament_id, team_id)
);

-- ---------------------------------------------------------------------------
-- Matches
-- home_team_id / away_team_id are nullable to represent TBD slots in a
-- bracket before earlier rounds complete.
-- winner_id is denormalised for query convenience; it must always agree with
-- home_score / away_score when status = 'completed'.
-- ---------------------------------------------------------------------------
CREATE TABLE matches (
    id                 SERIAL      PRIMARY KEY,
    tournament_id      INTEGER     NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    round              INTEGER     NOT NULL,
    match_number       INTEGER     NOT NULL,
    home_team_id       INTEGER     REFERENCES teams(id),
    away_team_id       INTEGER     REFERENCES teams(id),
    home_score         INTEGER,
    away_score         INTEGER,
    status             VARCHAR(32) NOT NULL DEFAULT 'scheduled'
                           CHECK (status IN ('scheduled', 'in_progress', 'completed', 'bye')),
    winner_id          INTEGER     REFERENCES teams(id),
    scheduled_at       TIMESTAMPTZ,
    played_at          TIMESTAMPTZ,
    result_entered_by  INTEGER     REFERENCES users(id),
    result_entered_at  TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- Reevaluation requests
-- Created when Staff edits a result that is already 'completed'.
-- Organiser (or Admin) approves or rejects; approval overwrites the match row.
-- ---------------------------------------------------------------------------
CREATE TABLE reevaluation_requests (
    id                   SERIAL      PRIMARY KEY,
    match_id             INTEGER     NOT NULL REFERENCES matches(id),
    tournament_id        INTEGER     NOT NULL REFERENCES tournaments(id),
    requested_by         INTEGER     NOT NULL REFERENCES users(id),
    requested_home_score INTEGER     NOT NULL,
    requested_away_score INTEGER     NOT NULL,
    reason               TEXT,
    status               VARCHAR(32) NOT NULL DEFAULT 'pending'
                             CHECK (status IN ('pending', 'approved', 'rejected')),
    reviewed_by          INTEGER     REFERENCES users(id),
    reviewed_at          TIMESTAMPTZ,
    review_note          TEXT,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- Audit log
-- Append-only. No updated_at — rows are never mutated.
-- old_data / new_data store JSONB snapshots of the affected row.
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     INTEGER     REFERENCES users(id),
    action      VARCHAR(64) NOT NULL,   -- e.g. 'result_entered', 'settings_updated'
    entity_type VARCHAR(64) NOT NULL,   -- e.g. 'match', 'tournament'
    entity_id   INTEGER,
    old_data    JSONB,
    new_data    JSONB,
    ip_address  VARCHAR(45),            -- supports IPv6
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------------
-- updated_at auto-maintenance trigger
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_tournaments_updated_at
    BEFORE UPDATE ON tournaments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_tournament_roles_updated_at
    BEFORE UPDATE ON tournament_roles
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_teams_updated_at
    BEFORE UPDATE ON teams
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_tournament_teams_updated_at
    BEFORE UPDATE ON tournament_teams
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_matches_updated_at
    BEFORE UPDATE ON matches
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_reevaluation_requests_updated_at
    BEFORE UPDATE ON reevaluation_requests
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------------
-- Indexes
-- ---------------------------------------------------------------------------
CREATE INDEX idx_tournament_roles_user       ON tournament_roles(user_id);
CREATE INDEX idx_tournament_teams_tournament ON tournament_teams(tournament_id);
CREATE INDEX idx_matches_tournament          ON matches(tournament_id);
CREATE INDEX idx_matches_round               ON matches(tournament_id, round);
CREATE INDEX idx_reeval_tournament_pending   ON reevaluation_requests(tournament_id, status);
CREATE INDEX idx_audit_entity               ON audit_log(entity_type, entity_id);
