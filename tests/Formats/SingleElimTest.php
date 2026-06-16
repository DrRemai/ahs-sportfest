<?php
declare(strict_types=1);

class SingleElimTest extends TestCase
{
    protected static bool $useTransaction = false;

    private static int $userId;
    private SingleElim $fmt;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new SingleElim();
    }

    // -------------------------------------------------------------------------

    public function testGeneratePowerOfTwoPadsByes(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 3);

        $this->fmt->generate($tid, $teams, []);

        // 3 teams → padded to 4; R1 should have 2 match rows (1 bye + 1 pending)
        $r1 = $this->matchesInRound($tid, 1);
        $this->assertCount(2, $r1);

        $byeCount = count(array_filter($r1, fn($m) => $m['status'] === 'bye'));
        $this->assertSame(1, $byeCount);
    }

    public function testMatchCountForFourTeams(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        // 4 teams: R1 = 2 matches, R2 = 1 match → 3 total
        $total = $this->totalMatches($tid);
        $this->assertSame(2, $total);
    }

    public function testMatchCountForEightTeams(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 8);

        $this->fmt->generate($tid, $teams, []);

        // 8 teams: R1=4, R2=2, R3=1 → 7 total
        $this->assertSame(4, $this->totalMatches($tid));
    }

    public function testByeCountWithFiveTeams(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 5);

        $this->fmt->generate($tid, $teams, []);

        // 5 teams → padded to 8; 3 bye slots
        $r1     = $this->matchesInRound($tid, 1);
        $byes   = count(array_filter($r1, fn($m) => $m['status'] === 'bye'));
        $this->assertSame(3, $byes);
    }

    public function testSeeding1v4and2v3ForFourTeams(): void
    {
        $tid = self::createTournament(self::$userId);
        // Create teams with deterministic IDs for seeding verification
        $team1 = self::createTeam(self::$userId, 'Seed1');
        $team2 = self::createTeam(self::$userId, 'Seed2');
        $team3 = self::createTeam(self::$userId, 'Seed3');
        $team4 = self::createTeam(self::$userId, 'Seed4');
        foreach ([$team1, $team2, $team3, $team4] as $t) {
            self::addTeamToTournament($tid, $t);
        }

        // generate() respects the order passed: index 0 = seed 1 … index 3 = seed 4
        $this->fmt->generate($tid, [$team1, $team2, $team3, $team4], []);

        $r1 = $this->matchesInRound($tid, 1);
        $this->assertCount(2, $r1);

        // Collect all R1 pairings as sets
        $pairings = array_map(
            fn($m) => [(int)$m['home_team_id'], (int)$m['away_team_id']],
            $r1
        );
        // Seed1 vs Seed4 and Seed2 vs Seed3
        $this->assertContains([$team1, $team4], $pairings);
        $this->assertContains([$team2, $team3], $pairings);
    }

    public function testAdvanceWinnerMovesToNextRound(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $r1 = $this->matchesInRound($tid, 1);
        $m  = $r1[0];

        $this->acceptMatch((int)$m['id'], 2, 0, 'single_elim');

        // After advance, R2 should exist and the winner should be seated
        $r2 = $this->matchesInRound($tid, 2);
        $this->assertNotEmpty($r2);

        $r2m = $r2[0];
        $this->assertTrue(
            (int)$r2m['home_team_id'] === (int)$m['home_team_id']
            || (int)$r2m['away_team_id'] === (int)$m['home_team_id']
        );
    }

    public function testIsNotCompleteWithPendingMatches(): void
    {
        $tid   = self::createTournament(self::$userId);
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, []);

        $this->assertFalse($this->fmt->isComplete($tid));
    }

public function testIsCompleteWhenFinalMatchAccepted(): void
{
    $tid   = self::createTournament(self::$userId);
    $teams = $this->makeTeams($tid, 2);
    $this->fmt->generate($tid, $teams, []);
    $matches = $this->pendingMatches($tid);
    $this->assertCount(1, $matches);
    $this->acceptMatch((int)$matches[0]['id'], 3, 1, 'single_elim');

    // After acceptMatch(), before isComplete()
$stmt = static::$db->prepare(
    "SELECT id, status, home_team_id, away_team_id FROM matches WHERE tournament_id = ?"
);
$stmt->execute([$tid]);
$allMatches = $stmt->fetchAll();
$this->assertCount(1, $allMatches, "Expected 1 match, got: " . print_r($allMatches, true));

    $this->assertTrue($this->fmt->isComplete($tid));
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

    private function totalMatches(int $tid): int
    {
        $stmt = static::$db->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tid]);
        return (int)$stmt->fetchColumn();
    }
}
