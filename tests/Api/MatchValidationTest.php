<?php
declare(strict_types=1);

/**
 * Validation and permission tests for api_match_result().
 *
 * api_match_result (~line 784 in router.php):
 *   - Requires auth
 *   - Match must exist
 *   - Caller must have role organiser, admin, or staff in the tournament
 *   - Staff entering a result on an already-accepted match → reevaluation path
 *   - Organiser/admin entering any result → direct accept
 */
class MatchValidationTest extends ApiTestCase
{
    private static int $organiserUid;
    private static int $staffUid;
    private static int $guestUid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$organiserUid = self::createUser();
        self::$staffUid     = self::createUser();
        self::$guestUid     = self::createUser();
    }

    // -------------------------------------------------------------------------

    public function testMatchResultRequiresAuth(): void
    {
        $this->clearSession();
        $this->setBody(['home_score' => 1, 'away_score' => 0]);
        $res = $this->captureOutput(fn() => api_match_result(999999));
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsStringIgnoringCase('unauthenticated', (string)($res['error'] ?? ''));
    }

    public function testMatchNotFoundReturns404(): void
    {
        $this->setSession(self::$organiserUid, true); // admin bypasses all role checks
        $this->setBody(['home_score' => 1, 'away_score' => 0]);
        $res = $this->captureOutput(fn() => api_match_result(999999));
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsString('not found', strtolower((string)($res['error'] ?? '')));
    }

    public function testGuestCannotEnterResult(): void
    {
        [$tid, $matchId] = $this->scaffoldMatch();

        $this->setSession(self::$guestUid); // no role in this tournament
        $this->setBody(['home_score' => 1, 'away_score' => 0]);
        $res = $this->captureOutput(fn() => api_match_result($matchId));
        $this->assertFalse($res['ok'] ?? true);
        $this->assertStringContainsStringIgnoringCase('forbidden', (string)($res['error'] ?? ''));
    }

    public function testOrganiserCanEnterResultDirectly(): void
    {
        [$tid, $matchId] = $this->scaffoldMatch();

        self::assignRole($tid, self::$organiserUid, 'organiser', self::$organiserUid);
        $this->setSession(self::$organiserUid);
        $this->setBody(['home_score' => 2, 'away_score' => 1]);
        $res = $this->captureOutput(fn() => api_match_result($matchId));
        $this->assertOk($res);

        // Confirm match is now accepted
        $stmt = static::$db->prepare("SELECT status FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $this->assertSame('accepted', $stmt->fetchColumn());
    }

    public function testStaffEnteringResultOnAcceptedMatchCreatesReevaluation(): void
    {
        [$tid, $matchId] = $this->scaffoldMatch();

        self::assignRole($tid, self::$organiserUid, 'organiser', self::$organiserUid);
        self::assignRole($tid, self::$staffUid, 'staff', self::$organiserUid);

        // First, accept the match as organiser
        $this->setSession(self::$organiserUid);
        $this->setBody(['home_score' => 1, 'away_score' => 0]);
        $this->captureOutput(fn() => api_match_result($matchId));

        // Now staff contests the result
        $this->setSession(self::$staffUid);
        $this->setBody(['home_score' => 0, 'away_score' => 1, 'reason' => 'Score was wrong']);
        $res = $this->captureOutput(fn() => api_match_result($matchId));
        $this->assertOk($res);
        $this->assertTrue($res['data']['reevaluation'] ?? false);

        // Confirm a reevaluation_request row was created
        $stmt = static::$db->prepare(
            "SELECT COUNT(*) FROM reevaluation_requests WHERE match_id = ? AND status = 'pending'"
        );
        $stmt->execute([$matchId]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    public function testStaffCanEnterResultOnPendingMatch(): void
    {
        [$tid, $matchId] = $this->scaffoldMatch();

        self::assignRole($tid, self::$organiserUid, 'organiser', self::$organiserUid);
        self::assignRole($tid, self::$staffUid, 'staff', self::$organiserUid);

        $this->setSession(self::$staffUid);
        $this->setBody(['home_score' => 1, 'away_score' => 0]);
        $res = $this->captureOutput(fn() => api_match_result($matchId));
        $this->assertOk($res);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a tournament with one pending match (2 teams, single_elim, seeded).
     * Returns [$tournamentId, $matchId].
     */
    private function scaffoldMatch(): array
    {
        $tid    = self::createTournament(self::$organiserUid, 'single_elim');
        $team1  = self::createTeam(self::$organiserUid);
        $team2  = self::createTeam(self::$organiserUid);
        self::addTeamToTournament($tid, $team1);
        self::addTeamToTournament($tid, $team2);

        (new SingleElim())->generate($tid, [$team1, $team2], []);

        $stmt = static::$db->prepare(
            "SELECT id FROM matches WHERE tournament_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$tid]);
        $matchId = (int)$stmt->fetchColumn();

        return [$tid, $matchId];
    }
}
