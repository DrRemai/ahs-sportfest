<?php
declare(strict_types=1);

class RoundRobinTest extends TestCase
{
    private static int $userId;
    private RoundRobin $fmt;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new RoundRobin();
    }

    // -------------------------------------------------------------------------

    public function testGeneratesNMinusOneRoundsForEvenTeams(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Circle method: 4 teams → 3 rounds
        $maxRound = $this->maxRound($tid);
        $this->assertSame(3, $maxRound);
    }

    public function testTotalMatchCountForFourTeams(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // n*(n-1)/2 = 4*3/2 = 6 matches
        $this->assertSame(6, $this->totalMatches($tid));
    }

    public function testEachRoundHasN2MatchesForFourTeams(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Each round should have exactly n/2 = 2 matches
        for ($r = 1; $r <= 3; $r++) {
            $this->assertCount(2, $this->matchesInRound($tid, $r), "Round $r should have 2 matches");
        }
    }

    public function testAdvanceIsNoOp(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $pending = $this->pendingMatches($tid);
        $m       = $pending[0];

        // Accept the match
        static::$db->prepare(
            "UPDATE matches SET status='accepted', home_score=2, away_score=1,
             winner_id=?, updated_at=NOW() WHERE id=?"
        )->execute([(int)$m['home_team_id'], (int)$m['id']]);

        $countBefore = $this->totalMatches($tid);
        $this->fmt->advance((int)$m['id']); // should be a no-op
        $countAfter  = $this->totalMatches($tid);

        $this->assertSame($countBefore, $countAfter, 'advance() must not create new matches in round robin');
    }

    public function testStandingsPointsAfterOneRound(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Accept round 1: home always wins
        foreach ($this->matchesInRound($tid, 1) as $m) {
            static::$db->prepare(
                "UPDATE matches SET status='accepted', home_score=2, away_score=1,
                 winner_id=?, updated_at=NOW() WHERE id=?"
            )->execute([(int)$m['home_team_id'], (int)$m['id']]);
        }

        $standings = $this->fmt->standings($tid);
        $this->assertNotEmpty($standings);

        // Every team should have a record in standings
        $this->assertCount(4, $standings);
    }

    public function testTiebreakGoalDifference(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 2);

        $this->fmt->generate($tid, $teams, []);

        // One match: team A (home) wins 3-1
        $m = $this->pendingMatches($tid)[0];
        static::$db->prepare(
            "UPDATE matches SET status='accepted', home_score=3, away_score=1,
             winner_id=?, updated_at=NOW() WHERE id=?"
        )->execute([(int)$m['home_team_id'], (int)$m['id']]);

        $standings = $this->fmt->standings($tid);
        $this->assertSame((int)$m['home_team_id'], (int)$standings[0]['team_id'], 'Winner should rank first');
    }

    public function testIsNotCompleteWithPendingMatches(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $this->assertFalse($this->fmt->isComplete($tid));
    }

    public function testIsCompleteWhenAllMatchesAccepted(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Accept all matches
        $stmt = static::$db->prepare(
            "SELECT id, home_team_id FROM matches WHERE tournament_id = ? AND status = 'pending'"
        );
        $stmt->execute([$tid]);
        foreach ($stmt->fetchAll() as $m) {
            static::$db->prepare(
                "UPDATE matches SET status='accepted', home_score=1, away_score=0,
                 winner_id=?, updated_at=NOW() WHERE id=?"
            )->execute([(int)$m['home_team_id'], (int)$m['id']]);
        }

        $this->assertTrue($this->fmt->isComplete($tid));
    }

    public function testEveryTeamPlaysEachOpponentOnce(): void
    {
        $tid   = self::createTournament(self::$userId, 'round_robin');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // Each team should face the other 3 teams exactly once
        $stmt = static::$db->prepare(
            'SELECT home_team_id, away_team_id FROM matches WHERE tournament_id = ?'
        );
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();

        foreach ($teams as $t) {
            $opponents = [];
            foreach ($rows as $m) {
                if ((int)$m['home_team_id'] === $t) $opponents[] = (int)$m['away_team_id'];
                if ((int)$m['away_team_id'] === $t) $opponents[] = (int)$m['home_team_id'];
            }
            $this->assertCount(3, array_unique($opponents), "Team $t should face 3 unique opponents");
        }
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

    private function maxRound(int $tid): int
    {
        $stmt = static::$db->prepare('SELECT MAX(round) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tid]);
        return (int)$stmt->fetchColumn();
    }

    private function totalMatches(int $tid): int
    {
        $stmt = static::$db->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tid]);
        return (int)$stmt->fetchColumn();
    }
}
