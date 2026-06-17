<?php
declare(strict_types=1);

/**
 * Round Robin format engine.
 *
 * ALGORITHMIC DECISIONS
 * ─────────────────────
 * Scheduling: Circle method — fix team at position 0, rotate all others.
 * Produces (n-1) rounds for n teams (n even). Odd team counts receive one
 * dummy null team; the team paired with null gets a bye that round.
 * Minimises repeat opponents and distributes home/away roughly evenly.
 *
 * All matches are generated upfront on generate(). advance() is a no-op
 * because there is no next-match creation needed — all match stubs exist.
 *
 * Points: 3/1/0 (win/draw/loss) by default; overridable via config.
 *
 * Tiebreaking order: Points → Goal Ratio (GF/GA) → Goals For → Team ID.
 * Goal ratio (quotient) replaces goal difference; GA=0 treated as PHP_FLOAT_MAX
 * for sorting purposes, serialised as null in the API response (displayed as ∞).
 *
 * When config['groups'] > 1 the team list is split into equal groups.
 * Each group plays its own round-robin; bracket_side ('A', 'B', …) identifies
 * which group a match belongs to. standingsByGroup() returns per-group rankings
 * and is used by MultiStage for cross-bracket seeding.
 */
class RoundRobin implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        if (count($teamIds) < 2) {
            throw new \InvalidArgumentException('At least 2 teams required.');
        }

        $mode    = ($config['mode'] ?? 'manual') === 'random' ? 'random' : 'manual';
        $stageId = isset($config['stage_id']) ? (int)$config['stage_id'] : null;
        $groups  = max(1, (int)($config['groups'] ?? 1));

        if ($mode === 'random') shuffle($teamIds);

        $db = db();

        // Clear existing matches
        if ($stageId) {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND stage_id = ?')
               ->execute([$tournamentId, $stageId]);
        } else {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND stage_id IS NULL')
               ->execute([$tournamentId]);
        }

        // bracket_side is a parameter now (group letter or 'none')
        $insertStmt = $db->prepare(
            "INSERT INTO matches
             (tournament_id, stage_id, bracket_side, round, match_number, match_order,
              home_team_id, away_team_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );

        if ($groups > 1) {
            $perGroup = intdiv(count($teamIds), $groups);
            for ($gi = 0; $gi < $groups; $gi++) {
                $groupTeams = array_slice($teamIds, $gi * $perGroup, $perGroup);
                $side       = chr(65 + $gi); // 'A', 'B', …
                $schedule   = $this->circleSchedule($groupTeams);
                foreach ($schedule as $roundIdx => $pairs) {
                    $round    = $roundIdx + 1;
                    $matchNum = 1;
                    foreach ($pairs as [$home, $away]) {
                        $insertStmt->execute([$tournamentId, $stageId, $side, $round, $matchNum, $matchNum, $home, $away]);
                        $matchNum++;
                    }
                }
            }
        } else {
            $schedule = $this->circleSchedule($teamIds);
            foreach ($schedule as $roundIdx => $pairs) {
                $round    = $roundIdx + 1;
                $matchNum = 1;
                foreach ($pairs as [$home, $away]) {
                    $insertStmt->execute([$tournamentId, $stageId, 'none', $round, $matchNum, $matchNum, $home, $away]);
                    $matchNum++;
                }
            }
        }
    }

    /** No next-match creation needed — all stubs were created in generate(). */
    public function advance(int $matchId): void {}

    public function standings(int $tournamentId, ?int $stageId = null): array
    {
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';

        $stmt = db()->prepare(
            "SELECT tt.team_id, t.name
             FROM tournament_teams tt
             JOIN teams t ON t.id = tt.team_id
             WHERE tt.tournament_id = ? AND tt.status = 'approved'"
        );
        $stmt->execute([$tournamentId]);

        $record = [];
        foreach ($stmt->fetchAll() as $row) {
            $record[(int)$row['team_id']] = [
                'team_id' => (int)$row['team_id'],
                'name'    => $row['name'],
                'p'  => 0, 'w' => 0, 'd' => 0, 'l' => 0,
                'gf' => 0, 'ga' => 0, 'pts' => 0,
            ];
        }

        $mStmt = db()->prepare(
            "SELECT home_team_id, away_team_id, home_score, away_score, winner_id, status
             FROM matches WHERE tournament_id = ? AND status IN ('accepted') $stageClause"
        );
        $mParams = [$tournamentId];
        if ($stageId) $mParams[] = $stageId;
        $mStmt->execute($mParams);

        foreach ($mStmt->fetchAll() as $m) {
            $home = (int)$m['home_team_id'];
            $away = (int)$m['away_team_id'];
            if (!$home || !$away) continue;
            if (!isset($record[$home], $record[$away])) continue;

            $hs = (int)$m['home_score'];
            $as = (int)$m['away_score'];

            $record[$home]['p']++;
            $record[$away]['p']++;
            $record[$home]['gf'] += $hs;
            $record[$home]['ga'] += $as;
            $record[$away]['gf'] += $as;
            $record[$away]['ga'] += $hs;

            if ($m['winner_id'] === null) {
                $record[$home]['d']++;
                $record[$away]['d']++;
                $record[$home]['pts']++;
                $record[$away]['pts']++;
            } elseif ((int)$m['winner_id'] === $home) {
                $record[$home]['w']++;
                $record[$away]['l']++;
                $record[$home]['pts'] += 3;
            } else {
                $record[$home]['l']++;
                $record[$away]['w']++;
                $record[$away]['pts'] += 3;
            }
        }

        // Compute goal ratio for each team; PHP_FLOAT_MAX is the sort key when GA=0
        foreach ($record as &$r) {
            $r['_ratio_sort'] = $r['ga'] === 0 ? PHP_FLOAT_MAX : $r['gf'] / $r['ga'];
        }
        unset($r);

        $standings = array_values($record);
        usort($standings, fn($a, $b) =>
            $b['pts']          <=> $a['pts']
            ?: ($b['_ratio_sort'] <=> $a['_ratio_sort'])
            ?: $b['gf']        <=> $a['gf']
            ?: $a['team_id']   <=> $b['team_id']
        );

        // Serialise ratio: PHP_FLOAT_MAX → null (displayed as ∞ on frontend)
        foreach ($standings as &$s) {
            $s['goals_ratio'] = $s['_ratio_sort'] >= PHP_FLOAT_MAX / 2
                ? null
                : round($s['_ratio_sort'], 4);
            unset($s['_ratio_sort']);
        }
        unset($s);

        return $standings;
    }

    /**
     * Return standings split by group (bracket_side).
     * Used by MultiStage for cross-bracket seeding.
     * Falls back to ['A' => standings()] when no groups exist.
     */
    public function standingsByGroup(int $tournamentId, ?int $stageId = null): array
    {
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $base        = [$tournamentId];
        if ($stageId) $base[] = $stageId;

        $gStmt = db()->prepare(
            "SELECT DISTINCT bracket_side FROM matches
             WHERE tournament_id = ? $stageClause
               AND bracket_side NOT IN ('none', 'winners', 'losers')
             ORDER BY bracket_side"
        );
        $gStmt->execute($base);
        $sides = $gStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($sides)) {
            return ['A' => $this->standings($tournamentId, $stageId)];
        }

        $allStandings = $this->standings($tournamentId, $stageId);
        $result       = [];

        foreach ($sides as $side) {
            $p = [$tournamentId];
            if ($stageId) $p[] = $stageId;
            $p[] = $side;
            $p[] = $tournamentId;
            if ($stageId) $p[] = $stageId;
            $p[] = $side;

            $tStmt = db()->prepare(
                "SELECT home_team_id FROM matches
                 WHERE tournament_id = ? $stageClause AND bracket_side = ? AND home_team_id IS NOT NULL
                 UNION
                 SELECT away_team_id FROM matches
                 WHERE tournament_id = ? $stageClause AND bracket_side = ? AND away_team_id IS NOT NULL"
            );
            $tStmt->execute($p);
            $groupIds = array_map('intval', $tStmt->fetchAll(\PDO::FETCH_COLUMN));

            $result[$side] = array_values(
                array_filter($allStandings, fn($s) => in_array($s['team_id'], $groupIds))
            );
        }

        return $result;
    }

    public function isComplete(int $tournamentId, ?int $stageId = null): bool
    {
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = ? $stageClause
               AND status NOT IN ('accepted', 'bye')"
        );
        $params = [$tournamentId];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() === 0;
    }

    // -------------------------------------------------------------------------

    /**
     * Circle method: fix first team, rotate the rest.
     * Returns array of rounds, each an array of [home_id, away_id] pairs.
     * Null team = bye; those pairs are skipped.
     */
    private function circleSchedule(array $teams): array
    {
        $n = count($teams);
        if ($n % 2 !== 0) {
            $teams[] = null; // dummy bye team
            $n++;
        }

        $half      = $n / 2;
        $numRounds = $n - 1;
        $fixed     = $teams[0];
        $rotating  = array_slice($teams, 1);
        $rounds    = [];

        for ($r = 0; $r < $numRounds; $r++) {
            $current = array_merge([$fixed], $rotating);
            $round   = [];

            for ($i = 0; $i < $half; $i++) {
                $home = $current[$i];
                $away = $current[$n - 1 - $i];
                if ($home !== null && $away !== null) {
                    $round[] = [$home, $away];
                }
            }

            if (!empty($round)) {
                $rounds[] = $round;
            }

            // Rotate: move last element to the front of the rotating array
            $last = array_pop($rotating);
            array_unshift($rotating, $last);
        }

        return $rounds;
    }
}
