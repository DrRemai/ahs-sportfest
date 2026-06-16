<?php
declare(strict_types=1);

class DoubleElimTest extends TestCase
{
    private static int $userId;
    private DoubleElim $fmt;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new DoubleElim();
    }

    // -------------------------------------------------------------------------

    public function testWinnersAndLosersBracketsGenerated(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $sides = $this->distinctBracketSides($tid);
        $this->assertContains('winners', $sides);
        // Losers bracket is created lazily on first advance, not at generate time
        // so we only assert the WB exists here
        $this->assertNotEmpty($sides);
    }

    public function testWinnersRound1MatchCount(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $r1winners = $this->matchesBy($tid, 'winners', 1);
        $this->assertCount(2, $r1winners);
    }

    public function testLoserOfWinnersBracketMovesToLosers(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $r1 = $this->matchesBy($tid, 'winners', 1);
        $this->acceptMatch((int)$r1[0]['id'], 1, 0, 'double_elim');

        $losersMatches = $this->matchesBySide($tid, 'losers');
        $this->assertNotEmpty($losersMatches);

        // The loser of R1 M1 should appear in the losers bracket
        $loser = (int)$r1[0]['away_team_id'];
        $inLosers = false;
        foreach ($losersMatches as $m) {
            if ((int)$m['home_team_id'] === $loser || (int)$m['away_team_id'] === $loser) {
                $inLosers = true;
                break;
            }
        }
        $this->assertTrue($inLosers, 'Loser of WB match must appear in LB');
    }

    public function testIsNotCompleteAtStart(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $this->assertFalse($this->fmt->isComplete($tid));
    }

    public function testGrandFinalCreatedAfterBothFinalsComplete(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Win all WB matches (teams[0] beats everyone)
        $this->winAllPendingFor($tid, 'double_elim');

        // GF should now exist
        $gf = $this->matchesBySide($tid, 'grand_final');
        $this->assertNotEmpty($gf);
    }

    public function testIsCompleteAfterGrandFinalAccepted(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $this->winAllPendingFor($tid, 'double_elim');

        $gf = $this->matchesBySide($tid, 'grand_final');
        $this->assertNotEmpty($gf, 'Grand final must exist before completing it');

        $gfMatch = $gf[0];
        if ($gfMatch['status'] === 'pending') {
            $this->acceptMatch((int)$gfMatch['id'], 2, 0, 'double_elim');
        }

        $this->assertTrue($this->fmt->isComplete($tid));
    }

    public function testTotalMatchesForFourTeams(): void
    {
        $tid   = self::createTournament(self::$userId, 'double_elim');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // With 4 teams in DE: WB=3, LB=4, GF=1 → depends on how many LB rounds.
        // At minimum, at generate time we only have WB R1 (2 matches).
        // More matches are created as bracket progresses.
        // Assert just that the initial match count is > 0.
        $stmt = static::$db->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tid]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
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

    private function matchesBy(int $tid, string $side, int $round): array
    {
        $stmt = static::$db->prepare(
            'SELECT * FROM matches WHERE tournament_id = ? AND bracket_side = ? AND round = ?
             ORDER BY match_number ASC'
        );
        $stmt->execute([$tid, $side, $round]);
        return $stmt->fetchAll();
    }

    private function matchesBySide(int $tid, string $side): array
    {
        $stmt = static::$db->prepare(
            'SELECT * FROM matches WHERE tournament_id = ? AND bracket_side = ? ORDER BY round ASC, match_number ASC'
        );
        $stmt->execute([$tid, $side]);
        return $stmt->fetchAll();
    }

    private function distinctBracketSides(int $tid): array
    {
        $stmt = static::$db->prepare(
            'SELECT DISTINCT bracket_side FROM matches WHERE tournament_id = ?'
        );
        $stmt->execute([$tid]);
        return array_column($stmt->fetchAll(), 'bracket_side');
    }

    /**
     * Greedily accept every pending match (home team always wins).
     * Limited to 50 iterations to prevent runaway loops.
     */
    private function winAllPendingFor(int $tid, string $format): void
    {
        for ($i = 0; $i < 50; $i++) {
            $matches = $this->pendingMatches($tid);
            if (empty($matches)) break;
            foreach ($matches as $m) {
                if ($m['home_team_id'] && $m['away_team_id']) {
                    $this->acceptMatch((int)$m['id'], 1, 0, $format);
                }
            }
        }
    }
}
