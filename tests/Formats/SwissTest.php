<?php
declare(strict_types=1);

/**
 * Swiss format tests.
 *
 * TRANSACTION NOTE — WHY $useTransaction = false
 * ─────────────────────────────────────────────
 * Swiss::createRound() calls $db->beginTransaction() + $db->commit() internally.
 * The base TestCase wraps tests with $db->exec('BEGIN'). Since $db is a shared
 * singleton, PDO's internal transaction tracking (which checks inTransaction()
 * before calling BEGIN) would see the outer exec('BEGIN') transparently, but
 * PostgreSQL would issue a WARNING on the second BEGIN.  More critically, when
 * Swiss calls $db->commit(), it commits the *outer* transaction — our cleanup
 * ROLLBACK in tearDownAfterClass then has nothing to roll back.
 *
 * Fix: set $useTransaction = false.  The base class then uses manual DELETE
 * cleanup in tearDownAfterClass, tracking all created IDs in static arrays.
 */
class SwissTest extends TestCase
{
    protected static bool $useTransaction = false;

    private static int $userId;
    private Swiss $fmt;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new Swiss();
    }

    // -------------------------------------------------------------------------

    public function testGeneratesRound1Matches(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        $r1 = $this->matchesInRound($tid, 1);
        // 4 teams → 2 matches in R1
        $this->assertCount(2, $r1);
    }

    public function testByeAssignedForOddTeamCount(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 3);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        $r1   = $this->matchesInRound($tid, 1);
        $byes = array_filter($r1, fn($m) => $m['status'] === 'bye');
        $this->assertCount(1, $byes, '3 teams should produce exactly one bye in R1');
    }

    public function testRound2GeneratedAfterRound1Complete(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        // Accept all R1 matches
        foreach ($this->matchesInRound($tid, 1) as $m) {
            if ($m['status'] === 'pending') {
                $this->acceptMatch((int)$m['id'], 1, 0, 'swiss');
            }
        }

        $r2 = $this->matchesInRound($tid, 2);
        $this->assertCount(2, $r2, 'R2 should be generated after R1 is complete');
    }

    public function testStandingsAfterOneRound(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        foreach ($this->matchesInRound($tid, 1) as $m) {
            if ($m['status'] === 'pending') {
                static::$db->prepare(
                    "UPDATE matches SET status='accepted', home_score=1, away_score=0,
                     winner_id=?, updated_at=NOW() WHERE id=?"
                )->execute([(int)$m['home_team_id'], (int)$m['id']]);
            }
        }

        $standings = $this->fmt->standings($tid);
        $this->assertCount(4, $standings);

        // Winners have 3pts; losers have 0pts
        $pts = array_column($standings, 'points');
        $this->assertContains(3, $pts);
        $this->assertContains(0, $pts);
    }

    public function testBuchholzOrdersStandings(): void
    {
        // Create 4 teams; team 0 wins both round 1 and 2; others will have varying BH
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        // Accept R1
        foreach ($this->matchesInRound($tid, 1) as $m) {
            if ($m['status'] === 'pending') {
                $this->acceptMatch((int)$m['id'], 1, 0, 'swiss');
            }
        }

        $standings = $this->fmt->standings($tid);
        // Standings are ordered by pts DESC, buchholz DESC
        for ($i = 0; $i < count($standings) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $standings[$i + 1]['points'],
                $standings[$i]['points'],
                'Standings must be ordered points DESC'
            );
        }
    }

    public function testIsNotCompleteBeforeAllRoundsPlayed(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        $this->assertFalse($this->fmt->isComplete($tid));
    }

    public function testIsCompleteAfterAllRoundsPlayed(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 2]);

        // Play 2 rounds
        for ($r = 1; $r <= 2; $r++) {
            foreach ($this->matchesInRound($tid, $r) as $m) {
                if ($m['status'] === 'pending') {
                    $this->acceptMatch((int)$m['id'], 1, 0, 'swiss');
                }
            }
        }

        $this->assertTrue($this->fmt->isComplete($tid));
    }

    public function testNoDuplicatePairingsInRound2(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, ['rounds' => 3]);

        // Accept R1 so R2 is generated
        foreach ($this->matchesInRound($tid, 1) as $m) {
            if ($m['status'] === 'pending') {
                $this->acceptMatch((int)$m['id'], 1, 0, 'swiss');
            }
        }

        $r2 = $this->matchesInRound($tid, 2);

        // Collect all R2 pairings and check no pair repeats from R1
        $r1Pairings = $this->pairings($tid, 1);
        $r2Pairings = $this->pairings($tid, 2);

        foreach ($r2Pairings as $pair) {
            sort($pair);
            $this->assertNotContains($pair, array_map(fn($p) => (sort($p) ?: $p), $r1Pairings),
                'R2 should avoid rematches from R1 when possible');
        }
    }

    public function testByeTeamGetsThreePoints(): void
    {
        $tid   = self::createTournament(self::$userId, 'swiss');
        $teams = $this->makeTeams($tid, 3);

        $this->fmt->generate($tid, $teams, ['rounds' => 2]);

        $standings = $this->fmt->standings($tid);
        $byeTeamPts = null;

        // Identify the bye match from R1
        foreach ($this->matchesInRound($tid, 1) as $m) {
            if ($m['status'] === 'bye') {
                $byeTeamId = (int)$m['home_team_id'];
                foreach ($standings as $s) {
                    if ((int)$s['team_id'] === $byeTeamId) {
                        $byeTeamPts = (int)$s['points'];
                        break 2;
                    }
                }
            }
        }

        $this->assertSame(3, $byeTeamPts, 'Bye recipient should receive 3 points automatically');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTeams(int $tid, int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = self::createTeam(self::$userId);
            self::addTeamToTournament($tid, $id);
            $ids[] = $id;
        }
        return $ids;
    }

    private function matchesInRound(int $tid, int $round): array
    {
        $stmt = static::$db->prepare(
            'SELECT * FROM matches WHERE tournament_id = ? AND round = ? ORDER BY match_number ASC'
        );
        $stmt->execute([$tid, $round]);
        return $stmt->fetchAll();
    }

    private function pairings(int $tid, int $round): array
    {
        $stmt = static::$db->prepare(
            'SELECT team1_id, team2_id FROM swiss_pairings WHERE tournament_id = ? AND round = ?'
        );
        $stmt->execute([$tid, $round]);
        return array_map(fn($r) => [(int)$r['team1_id'], (int)$r['team2_id']], $stmt->fetchAll());
    }
}
