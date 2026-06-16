<?php
declare(strict_types=1);

/**
 * MultiStage format tests.
 *
 * TRANSACTION NOTE — same as SwissTest.
 * MultiStage delegates to sub-formats. When the sub-format is Swiss,
 * createRound() calls $db->beginTransaction() + $db->commit(), which would
 * commit the outer test transaction.
 * Fix: $useTransaction = false with manual DELETE cleanup.
 */
class MultiStageTest extends TestCase
{
    protected static bool $useTransaction = false;

    private static int $userId;
    private MultiStage $fmt;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$userId = self::createUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new MultiStage();
    }

    // -------------------------------------------------------------------------

    public function testGenerateCreatesStageRows(): void
    {
        $tid   = self::createTournament(self::$userId, 'multi_stage');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, [
            'stages' => [
                ['format' => 'round_robin', 'config' => [], 'advance_count' => 2],
                ['format' => 'single_elim', 'config' => []],
            ],
        ]);

        $stages = $this->stages($tid);
        $this->assertCount(2, $stages);
    }

    public function testFirstStageIsInProgressAfterGenerate(): void
    {
        $tid   = self::createTournament(self::$userId, 'multi_stage');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, [
            'stages' => [
                ['format' => 'round_robin', 'config' => [], 'advance_count' => 2],
                ['format' => 'single_elim', 'config' => []],
            ],
        ]);

        $stages = $this->stages($tid);
        $this->assertSame('in_progress', $stages[0]['status']);
        $this->assertSame('pending', $stages[1]['status']);
    }

    public function testStage1MatchesAreLinkedToStageId(): void
    {
        $tid   = self::createTournament(self::$userId, 'multi_stage');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, [
            'stages' => [
                ['format' => 'round_robin', 'config' => [], 'advance_count' => 2],
                ['format' => 'single_elim', 'config' => []],
            ],
        ]);

        $stages  = $this->stages($tid);
        $stage1Id = (int)$stages[0]['id'];

        $stmt = static::$db->prepare(
            'SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND stage_id = ?'
        );
        $stmt->execute([$tid, $stage1Id]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }

    public function testStage2SeededWhenStage1Completes(): void
    {
        $tid   = self::createTournament(self::$userId, 'multi_stage');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, [
            'stages' => [
                ['format' => 'round_robin', 'config' => [], 'advance_count' => 2],
                ['format' => 'single_elim', 'config' => []],
            ],
        ]);

        $stages   = $this->stages($tid);
        $stage1Id = (int)$stages[0]['id'];
        $stage2Id = (int)$stages[1]['id'];

        // Accept all stage-1 matches
        $stmt = static::$db->prepare(
            "SELECT id, home_team_id FROM matches WHERE tournament_id = ? AND stage_id = ? AND status = 'pending'"
        );
        $stmt->execute([$tid, $stage1Id]);
        foreach ($stmt->fetchAll() as $m) {
            static::$db->prepare(
                "UPDATE matches SET status='accepted', home_score=2, away_score=0,
                 winner_id=?, updated_at=NOW() WHERE id=?"
            )->execute([(int)$m['home_team_id'], (int)$m['id']]);
            $this->fmt->advance((int)$m['id']);
        }

        // Stage 2 should now have matches
        $stmt2 = static::$db->prepare(
            'SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND stage_id = ?'
        );
        $stmt2->execute([$tid, $stage2Id]);
        $this->assertGreaterThan(0, (int)$stmt2->fetchColumn(), 'Stage 2 must be seeded after stage 1 completes');
    }

    public function testIsNotCompleteUntilLastStageComplete(): void
    {
        $tid   = self::createTournament(self::$userId, 'multi_stage');
        $teams = $this->makeTeams($tid, 4);

        $this->fmt->generate($tid, $teams, [
            'stages' => [
                ['format' => 'round_robin', 'config' => [], 'advance_count' => 2],
                ['format' => 'single_elim', 'config' => []],
            ],
        ]);

        $this->assertFalse($this->fmt->isComplete($tid));
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

    private function stages(int $tid): array
    {
        $stmt = static::$db->prepare(
            'SELECT * FROM tournament_stages WHERE tournament_id = ? ORDER BY stage_order ASC'
        );
        $stmt->execute([$tid]);
        return $stmt->fetchAll();
    }
}
