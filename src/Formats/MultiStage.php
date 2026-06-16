<?php
declare(strict_types=1);

/**
 * Multi-stage format orchestrator.
 *
 * Delegates generate/advance/standings/isComplete to per-stage format engines.
 * Stages are initialised lazily: only stage 1 is created on generate();
 * subsequent stages are seeded automatically when the previous stage completes.
 *
 * Stage completion check: all matches in the stage have 'accepted' or 'bye' status.
 * This works for all sub-formats because:
 *   - single_elim generates matches lazily, completing when the final is played.
 *   - round_robin pre-generates all matches; all must be played.
 *   - swiss generates rounds lazily; complete when the last configured round is done.
 *
 * Advancing between stages: the top N teams per stage advance to stage N+1.
 * N is specified as 'advance_count' in the stage config (default: all teams).
 * Teams are taken from the sub-format's standings() in order.
 *
 * Cross-bracket seeding (stages[1].config.seeding === 'cross_bracket'):
 * Expects stage 1 to be a grouped round_robin (groups=2, bracket_side='A'/'B').
 * Interleaves per-group standings as [1A,4B,2A,3B,2B,3A,1B,4A] before seeding
 * stage 2, which then uses sequential pairing for correct QF matchups.
 */
class MultiStage implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        $stages = $config['stages'] ?? [];
        if (count($stages) < 2) {
            throw new \InvalidArgumentException('multi_stage requires at least 2 stages.');
        }

        $db = db();

        // Create stage rows — PERF: prepare once outside loop
        $stageStmt = $db->prepare(
            "INSERT INTO tournament_stages (tournament_id, stage_order, format, config, status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        foreach ($stages as $idx => $stageDef) {
            $stageStmt->execute([
                $tournamentId,
                $idx + 1,
                $stageDef['format'],
                json_encode($stageDef['config'] ?? []),
            ]);
        }

        // Initialise stage 1 immediately
        $stage1Stmt = $db->prepare(
            'SELECT id FROM tournament_stages WHERE tournament_id = ? AND stage_order = 1'
        );
        $stage1Stmt->execute([$tournamentId]);
        $stage1 = $stage1Stmt->fetch();
        if (!$stage1) return;

        $stage1Def = $stages[0];
        $format    = FormatFactory::make($stage1Def['format']);
        $format->generate(
            $tournamentId,
            $teamIds,
            array_merge($stage1Def['config'] ?? [], ['stage_id' => (int)$stage1['id']])
        );

        $db->prepare("UPDATE tournament_stages SET status = 'in_progress' WHERE id = ?")
           ->execute([$stage1['id']]);
    }

    public function advance(int $matchId): void
    {
        $db   = db();
        $stmt = $db->prepare(
            'SELECT m.stage_id, m.tournament_id, ts.format, ts.config, ts.stage_order
             FROM matches m
             JOIN tournament_stages ts ON ts.id = m.stage_id
             WHERE m.id = ?'
        );
        $stmt->execute([$matchId]);
        $info = $stmt->fetch();
        if (!$info) return;

        $stageId      = (int)$info['stage_id'];
        $tournamentId = (int)$info['tournament_id'];
        $stageOrder   = (int)$info['stage_order'];

        // Delegate advance to the stage's format
        $subFormat = FormatFactory::make($info['format']);
        $subFormat->advance($matchId);

        // Check if this stage is now complete
        if (!$this->isStageComplete($tournamentId, $stageId)) return;

        $db->prepare("UPDATE tournament_stages SET status = 'complete' WHERE id = ?")
           ->execute([$stageId]);

        // Find the next stage
        $nextStmt = $db->prepare(
            'SELECT id, format, config FROM tournament_stages
             WHERE tournament_id = ? AND stage_order = ?'
        );
        $nextStmt->execute([$tournamentId, $stageOrder + 1]);
        $nextStage = $nextStmt->fetch();
        if (!$nextStage) return; // No next stage — tournament complete

        // Determine how many teams advance from current stage
        $currConfig   = json_decode($info['config'] ?? '{}', true) ?? [];
        $advanceCount = (int)($currConfig['advance_count'] ?? 0);
        $nextConfig   = json_decode($nextStage['config'] ?? '{}', true) ?? [];

        if (($nextConfig['seeding'] ?? '') === 'cross_bracket') {
            $advancingIds = $this->crossBracketSeeding($tournamentId, $stageId, $advanceCount);
        } else {
            // Default: top-N teams from current stage standings
            $standings = $subFormat->standings($tournamentId, $stageId);
            if ($advanceCount > 0) {
                $standings = array_slice($standings, 0, $advanceCount);
            }
            $advancingIds = array_column($standings, 'team_id');
        }

        if (empty($advancingIds)) return;

        // Seed the next stage
        $nextFormat = FormatFactory::make($nextStage['format']);
        $nextFormat->generate(
            $tournamentId,
            $advancingIds,
            array_merge($nextConfig, ['stage_id' => (int)$nextStage['id'], 'mode' => 'manual'])
        );

        $db->prepare("UPDATE tournament_stages SET status = 'in_progress' WHERE id = ?")
           ->execute([$nextStage['id']]);
    }

    public function standings(int $tournamentId, ?int $stageId = null): array
    {
        // Return standings for the most-advanced active/complete stage
        $db   = db();
        $stmt = $db->prepare(
            "SELECT id, format, config FROM tournament_stages
             WHERE tournament_id = ?
             ORDER BY stage_order DESC
             LIMIT 1"
        );
        $stmt->execute([$tournamentId]);
        $stage = $stmt->fetch();
        if (!$stage) return [];

        $format = FormatFactory::make($stage['format']);
        return $format->standings($tournamentId, (int)$stage['id']);
    }

    public function isComplete(int $tournamentId, ?int $stageId = null): bool
    {
        // Complete when the last stage is done
        $stmt = db()->prepare(
            "SELECT id, format FROM tournament_stages
             WHERE tournament_id = ?
             ORDER BY stage_order DESC LIMIT 1"
        );
        $stmt->execute([$tournamentId]);
        $last = $stmt->fetch();
        if (!$last) return false;

        return $this->isStageComplete($tournamentId, (int)$last['id']);
    }

    // -------------------------------------------------------------------------

    /**
     * Cross-bracket seeding for groups A and B.
     * Interleaves per-group standings as: [1A, 4B, 2A, 3B, 2B, 3A, 1B, 4A]
     * so that sequential pairing (0,1)(2,3)(4,5)(6,7) produces:
     *   QF1 = 1A vs 4B · QF2 = 2A vs 3B · QF3 = 2B vs 3A · QF4 = 1B vs 4A
     *
     * perGroup = advanceCount / number_of_groups (default 4 for 2 groups of 8).
     */
    private function crossBracketSeeding(int $tournamentId, int $stageId, int $advanceCount): array
    {
        $rrFormat  = new RoundRobin();
        $byGroup   = $rrFormat->standingsByGroup($tournamentId, $stageId);
        $numGroups = max(1, count($byGroup));
        $perGroup  = $advanceCount > 0 ? intdiv($advanceCount, $numGroups) : PHP_INT_MAX;

        $groupA = array_slice($byGroup['A'] ?? [], 0, $perGroup);
        $groupB = array_slice($byGroup['B'] ?? [], 0, $perGroup);

        // [A[0], B[3], A[1], B[2], B[1], A[2], B[0], A[3]]
        $slots = [
            $groupA[0] ?? null, $groupB[3] ?? null,
            $groupA[1] ?? null, $groupB[2] ?? null,
            $groupB[1] ?? null, $groupA[2] ?? null,
            $groupB[0] ?? null, $groupA[3] ?? null,
        ];

        return array_values(array_filter(
            array_map(fn($s) => $s !== null ? (int)$s['team_id'] : null, $slots)
        ));
    }

    private function isStageComplete(int $tournamentId, int $stageId): bool
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = ? AND stage_id = ?
               AND status NOT IN ('accepted', 'bye')"
        );
        $stmt->execute([$tournamentId, $stageId]);
        // A stage with zero matches is not complete (generate may not have run yet)
        $totalStmt = db()->prepare(
            'SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND stage_id = ?'
        );
        $totalStmt->execute([$tournamentId, $stageId]);
        return (int)$totalStmt->fetchColumn() > 0
            && (int)$stmt->fetchColumn() === 0;
    }
}
