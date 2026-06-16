<?php
declare(strict_types=1);

/**
 * Base class for all Endgame tests.
 *
 * Transaction strategy (default — $useTransaction = true):
 *   setUpBeforeClass  → exec('BEGIN')      all writes stay invisible outside this connection
 *   tearDownAfterClass → exec('ROLLBACK')  every row vanishes; DB stays pristine
 *
 * Non-transaction strategy ($useTransaction = false):
 *   Used by SwissTest and MultiStageTest because Swiss::createRound() calls
 *   $db->beginTransaction() internally.  When an outer exec('BEGIN') is active,
 *   PDO's implicit BEGIN from beginTransaction() triggers a PostgreSQL
 *   WARNING and Swiss's subsequent commit() commits the outer transaction,
 *   defeating the rollback wrapper.
 *   Fix: disable the outer transaction and instead track created IDs for
 *   manual DELETE in tearDownAfterClass.
 */
abstract class TestCase extends PHPUnit\Framework\TestCase
{
    protected static PDO $db;

    /**
     * Set to false in subclasses whose SUT calls $db->beginTransaction()
     * internally (Swiss, MultiStage). Uses manual ID-tracked DELETE cleanup.
     */
    protected static bool $useTransaction = true;

    // ID tracking for non-transaction cleanup
    protected static array $_createdUserIds       = [];
    protected static array $_createdTournamentIds = [];
    protected static array $_createdTeamIds       = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        static::$db = db();

        static::$_createdUserIds       = [];
        static::$_createdTournamentIds = [];
        static::$_createdTeamIds       = [];

        if (static::$useTransaction) {
            // Use exec (not beginTransaction) so PDO's own transaction tracking
            // remains false — this prevents PDO from refusing a second
            // beginTransaction() if any path calls it explicitly.
            static::$db->exec('BEGIN');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$useTransaction) {
            static::$db->exec('ROLLBACK');
            return;
        }

        // Non-transaction cleanup: CASCADE on tournaments removes tournament_teams,
        // matches, tournament_roles, tournament_stages, swiss_pairings.
        // Delete order: tournaments → teams → users (respects FK direction).
        foreach (array_reverse(static::$_createdTournamentIds) as $id) {
            try { static::$db->exec("DELETE FROM tournaments WHERE id = $id"); } catch (\Throwable $e) {}
        }
        foreach (array_reverse(static::$_createdTeamIds) as $id) {
            try { static::$db->exec("DELETE FROM teams WHERE id = $id"); } catch (\Throwable $e) {}
        }
        foreach (array_reverse(static::$_createdUserIds) as $id) {
            try { static::$db->exec("DELETE FROM users WHERE id = $id"); } catch (\Throwable $e) {}
        }
    }

    protected function setUp(): void
    {
        unset($_SESSION['user']);
        unset($GLOBALS['_test_api_body']);
    }

    // -------------------------------------------------------------------------
    // DB helpers
    // -------------------------------------------------------------------------

    protected static function createUser(string $username = '', bool $isAdmin = false): int
    {
        if ($username === '') {
            $username = 'tu_' . bin2hex(random_bytes(6));
        }
        $hash = password_hash('Password1', PASSWORD_BCRYPT);
        $stmt = static::$db->prepare(
            'INSERT INTO users (username, display_name, password_hash, is_admin)
             VALUES (?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$username, 'Test User', $hash, $isAdmin ? 1 : 0]);
        $id = (int)$stmt->fetchColumn();
        static::$_createdUserIds[] = $id;
        return $id;
    }

    /**
     * @param  array $config format_config JSON, e.g. ['rounds'=>3] for Swiss,
     *                       ['stages'=>[...]] for MultiStage.
     */
    protected static function createTournament(
        int    $createdBy,
        string $format  = 'single_elim',
        array  $config  = [],
        string $status  = 'draft'
    ): int {
        $name = 'T_' . bin2hex(random_bytes(6));
        $stmt = static::$db->prepare(
            "INSERT INTO tournaments (name, sport, format, status, visibility, created_by)
             VALUES (?, 'Test Sport', ?, ?, 'open', ?) RETURNING id"
        );
        $stmt->execute([$name, $format, $status, $createdBy]);
        $id = (int)$stmt->fetchColumn();

        if (!empty($config)) {
            static::$db->prepare('UPDATE tournaments SET format_config = ?::jsonb WHERE id = ?')
                       ->execute([json_encode($config), $id]);
        }

        static::$_createdTournamentIds[] = $id;
        return $id;
    }

    protected static function createTeam(int $ownerUid, string $name = ''): int
    {
        if ($name === '') {
            $name = 'Team_' . bin2hex(random_bytes(6));
        }
        $stmt = static::$db->prepare(
            "INSERT INTO teams (name, sport, owner_uid) VALUES (?, 'Test Sport', ?) RETURNING id"
        );
        $stmt->execute([$name, $ownerUid]);
        $id = (int)$stmt->fetchColumn();
        static::$_createdTeamIds[] = $id;
        return $id;
    }

    protected static function addTeamToTournament(
        int    $tournamentId,
        int    $teamId,
        string $status = 'approved'
    ): void {
        static::$db->prepare(
            "INSERT INTO tournament_teams (tournament_id, team_id, status, registered_at)
             VALUES (?, ?, ?, NOW())
             ON CONFLICT (tournament_id, team_id) DO UPDATE SET status = EXCLUDED.status"
        )->execute([$tournamentId, $teamId, $status]);
    }

    /**
     * Assigns a tournament-scoped role.
     * Actual column is user_id (not user_uid), and assigned_by is NOT NULL.
     */
    protected static function assignRole(
        int    $tournamentId,
        int    $userId,
        string $role,
        int    $assignedBy = 0
    ): void {
        if ($assignedBy === 0) {
            $assignedBy = $userId;
        }
        static::$db->prepare(
            "INSERT INTO tournament_roles (tournament_id, user_id, role, assigned_by)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (tournament_id, user_id) DO UPDATE SET role = EXCLUDED.role"
        )->execute([$tournamentId, $userId, $role, $assignedBy]);
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    protected function setSession(int $userId, bool $isAdmin = false, string $displayName = 'Test User'): void
    {
        $_SESSION['user'] = [
            'uid'          => $userId,
            'display_name' => $displayName,
            'is_admin'     => $isAdmin,
        ];
    }

    protected function clearSession(): void
    {
        unset($_SESSION['user']);
    }

    // -------------------------------------------------------------------------
    // Format helpers
    // -------------------------------------------------------------------------

    /**
     * Directly accepts a match (bypassing the API layer) and calls format->advance().
     * Used by format tests to progress brackets without going through HTTP.
     */
    protected function acceptMatch(
        int    $matchId,
        int    $homeScore,
        int    $awayScore,
        string $format
    ): void {
        $stmt = static::$db->prepare('SELECT home_team_id, away_team_id FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        $m = $stmt->fetch();

        $winnerId = null;
        if ($homeScore > $awayScore) {
            $winnerId = $m['home_team_id'];
        } elseif ($awayScore > $homeScore) {
            $winnerId = $m['away_team_id'];
        }

        static::$db->prepare(
            "UPDATE matches SET home_score=?, away_score=?, status='accepted',
             winner_id=?, updated_at=NOW() WHERE id=?"
        )->execute([$homeScore, $awayScore, $winnerId, $matchId]);

        FormatFactory::make($format)->advance($matchId);
    }

    /**
     * Returns all non-bye pending matches for a tournament, ordered by round.
     */
    protected function pendingMatches(int $tournamentId): array
    {
        $stmt = static::$db->prepare(
            "SELECT * FROM matches
             WHERE tournament_id = ? AND status = 'pending'
               AND home_team_id IS NOT NULL AND away_team_id IS NOT NULL
             ORDER BY round ASC, match_number ASC"
        );
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll();
    }
}
