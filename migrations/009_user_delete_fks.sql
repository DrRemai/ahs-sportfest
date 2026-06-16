-- Endgame Tournaments — Session 16: fix FK constraints to allow safe user deletion
-- Run after 008_team_names.sql
--
-- Decision: deleting a user who OWNS TEAMS or CREATED TOURNAMENTS is blocked at
-- the application layer (api_admin_user_delete returns 422). That keeps those
-- referential chains intact without cascading data loss.
--
-- Everything else either cascades to nothing useful without the user or can be
-- SET NULL (audit-trail rows, resolution records).
--
-- notifications.user_uid already has ON DELETE CASCADE (from 002_phase2.sql).
-- tournament_roles.user_id already has ON DELETE CASCADE (from schema.sql).

BEGIN;

-- tournament_roles.assigned_by: NOT NULL with no cascade → blocks deletion of
-- any admin/organiser who ever assigned a role. Change to nullable + SET NULL.
ALTER TABLE tournament_roles ALTER COLUMN assigned_by DROP NOT NULL;

ALTER TABLE tournament_roles
    DROP CONSTRAINT IF EXISTS tournament_roles_assigned_by_fkey;
ALTER TABLE tournament_roles
    ADD CONSTRAINT tournament_roles_assigned_by_fkey
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL;

-- reevaluation_requests.requested_by: NOT NULL → blocks deletion if any reeval
-- was submitted by this user. Change to nullable + SET NULL (record preserved).
ALTER TABLE reevaluation_requests ALTER COLUMN requested_by DROP NOT NULL;

ALTER TABLE reevaluation_requests
    DROP CONSTRAINT IF EXISTS reevaluation_requests_requested_by_fkey;
ALTER TABLE reevaluation_requests
    ADD CONSTRAINT reevaluation_requests_requested_by_fkey
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL;

-- reevaluation_requests.resolved_by_uid: already nullable; add SET NULL so the
-- reeval record survives after the resolver user is deleted.
ALTER TABLE reevaluation_requests
    DROP CONSTRAINT IF EXISTS reevaluation_requests_resolved_by_uid_fkey;
ALTER TABLE reevaluation_requests
    ADD CONSTRAINT reevaluation_requests_resolved_by_uid_fkey
    FOREIGN KEY (resolved_by_uid) REFERENCES users(id) ON DELETE SET NULL;

-- matches.result_entered_by: already nullable; SET NULL preserves match history.
ALTER TABLE matches
    DROP CONSTRAINT IF EXISTS matches_result_entered_by_fkey;
ALTER TABLE matches
    ADD CONSTRAINT matches_result_entered_by_fkey
    FOREIGN KEY (result_entered_by) REFERENCES users(id) ON DELETE SET NULL;

-- audit_log.user_id: already nullable; SET NULL keeps audit rows.
ALTER TABLE audit_log
    DROP CONSTRAINT IF EXISTS audit_log_user_id_fkey;
ALTER TABLE audit_log
    ADD CONSTRAINT audit_log_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

COMMIT;
