<?php
declare(strict_types=1);

/**
 * Permissions — static helpers that centralise role-based access checks.
 *
 * All logic here was extracted from inline checks scattered across
 * src/router.php.  The router handlers themselves are NOT yet updated to use
 * this class; they still contain equivalent inline checks.  This class exists
 * to make permission logic unit-testable without invoking the HTTP layer.
 *
 * EXTRACTION MAP — router.php handlers whose logic is mirrored here:
 *
 *   canEnterMatchResult  ← api_match_result (~line 796)
 *                          in_array($role, ['organiser','admin','staff'], true)
 *
 *   canAcceptResult      ← api_match_result (~line 803)
 *                          staff on accepted match → reevaluation path only;
 *                          direct accept requires organiser or admin
 *
 *   canEditTournament    ← api_tournament_settings (~line 540),
 *                          api_tournament_seed (~line 584),
 *                          api_tournament_bracket (bracket write path)
 *                          in_array($role, ['organiser','admin'], true)
 *
 *   canResolveReevaluation ← api_reevaluation_resolve (~line 868)
 *                            in_array($role, ['organiser','admin'], true)
 *
 *   canForceApproveReevaluation ← api_reevaluation_force_approve (uses api_require_admin)
 *
 *   canViewDraft         ← api_tournament_view (~line 385)
 *                          !in_array($role, ['organiser','admin','staff'], true) → 404
 *
 *   canArchiveTeam       ← api_team_archive (team owner or admin check)
 *
 *   resolveRole          ← wraps tournament_role(); returns 'guest' instead of null
 *
 * UPDATING ROUTER HANDLERS (recommended, not yet done):
 *   Each handler listed above could replace its inline check with the
 *   corresponding Permissions:: call.  The behaviour is identical.
 *   No handler is broken or incorrect in its current form.
 */
class Permissions
{
    /**
     * Whether the user may enter (post) a match result.
     * Staff, organiser, and admin may submit results.
     */
    public static function canEnterMatchResult(?string $role): bool
    {
        return in_array($role, ['organiser', 'admin', 'staff'], true);
    }

    /**
     * Whether the user may directly accept a match result (no reevaluation).
     * Staff must go through the reevaluation path instead.
     */
    public static function canAcceptResult(?string $role): bool
    {
        return in_array($role, ['organiser', 'admin'], true);
    }

    /**
     * Whether the user may edit tournament settings, seeding, or bracket config.
     */
    public static function canEditTournament(?string $role): bool
    {
        return in_array($role, ['organiser', 'admin'], true);
    }

    /**
     * Whether the user may approve or reject a reevaluation request.
     */
    public static function canResolveReevaluation(?string $role): bool
    {
        return in_array($role, ['organiser', 'admin'], true);
    }

    /**
     * Whether the user may force-approve a reevaluation (admin-only action).
     * Takes the full session user array so is_admin can be read directly.
     */
    public static function canForceApproveReevaluation(array $user): bool
    {
        return (bool)($user['is_admin'] ?? false);
    }

    /**
     * Whether the user may view a draft or archived tournament.
     * Guests and unauthenticated users may not.
     */
    public static function canViewDraft(?string $role): bool
    {
        return in_array($role, ['organiser', 'admin', 'staff'], true);
    }

    /**
     * Whether the user may archive the given team.
     * Requires either team ownership or global admin status.
     *
     * @param array $user     Session user array (uid, is_admin).
     * @param int   $ownerUid teams.owner_uid of the team being archived.
     */
    public static function canArchiveTeam(array $user, int $ownerUid): bool
    {
        return ((int)($user['uid'] ?? 0) === $ownerUid)
            || (bool)($user['is_admin'] ?? false);
    }

    /**
     * Resolve the calling user's role in a tournament.
     * Returns 'admin'|'organiser'|'staff'|'guest' (never null).
     * Delegates to tournament_role() from auth.php.
     */
    public static function resolveRole(int $tournamentId): string
    {
        return tournament_role($tournamentId) ?? 'guest';
    }
}
