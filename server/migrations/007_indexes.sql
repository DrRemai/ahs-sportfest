-- Endgame Tournaments — Session 15: missing index audit
-- Run after all prior migrations.
-- All statements are IF NOT EXISTS — safe to re-run.
--
-- Indexes that already exist from prior migrations and are NOT duplicated here:
--   idx_tournaments_featured    (002_phase2.sql)
--   idx_matches_round           (schema.sql — covers tournament_id, round)
--   idx_matches_stage           (004_formats.sql)
--   idx_matches_bracket         (004_formats.sql — covers tournament_id, bracket_side)
--   idx_tt_status               (002_phase2.sql — covers tournament_id, status)
--   idx_notifications_user_unread (002_phase2.sql — partial on user_uid, read_at)
--   idx_swiss_pairings_tournament (004_formats.sql)
--   users_username_ci_idx       (schema.sql — lower(username))

BEGIN;

-- Tournaments: status and sport filters appear in api_tournaments() WHERE clauses
CREATE INDEX IF NOT EXISTS idx_tournaments_status ON tournaments(status);
CREATE INDEX IF NOT EXISTS idx_tournaments_sport  ON tournaments(sport);

-- Matches: status filter is used in every format engine's standings/advance queries
CREATE INDEX IF NOT EXISTS idx_matches_tournament_status ON matches(tournament_id, status);

-- Tournament teams: team-side lookups (api_team_view history, archive block check)
CREATE INDEX IF NOT EXISTS idx_tt_team ON tournament_teams(team_id, status);

-- Tournament roles: user-side lookup (tournament_role() called on every authed request)
-- Note: column is user_id (not user_uid) — see schema.sql
CREATE INDEX IF NOT EXISTS idx_roles_user       ON tournament_roles(user_id, tournament_id);
CREATE INDEX IF NOT EXISTS idx_roles_tournament ON tournament_roles(tournament_id);

-- Notifications: ordered list for the drawer (user + recency)
CREATE INDEX IF NOT EXISTS idx_notif_user_created ON notifications(user_uid, created_at DESC);

-- Swiss pairings: round-level lookup used in advance() round-completion check
CREATE INDEX IF NOT EXISTS idx_swiss_tournament_round ON swiss_pairings(tournament_id, round);

-- Teams: owner dashboard and sport browse
CREATE INDEX IF NOT EXISTS idx_teams_owner ON teams(owner_uid, status);
CREATE INDEX IF NOT EXISTS idx_teams_sport  ON teams(sport);

COMMIT;
