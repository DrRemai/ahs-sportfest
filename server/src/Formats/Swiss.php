<?php
declare(strict_types=1);

/**
 * Swiss format engine.
 *
 * ALGORITHMIC DECISIONS
 * ─────────────────────
 * Total rounds: ceil(log2(n)) by default, overridable via config['rounds'].
 *
 * Round 1 pairings: sequential by seed order (manual) or random.
 *
 * Subsequent rounds — conflict-free greedy pairing:
 *   1. Sort teams by points DESC, Buchholz DESC.
 *   2. Greedily pair each unseeded team with the first available opponent
 *      they have not yet played.
 *   3. If no conflict-free partner exists, allow a rematch (fallback) to
 *      guarantee every team gets a match each round.
 *   Rationale: O(n²) worst case, correct for typical player counts (≤128).
 *   More sophisticated Edmond-blossom matching is not warranted here.
 *
 * Bye assignment: lowest-ranked team that has not yet received a bye.
 * If all teams have had a bye, the lowest-ranked team gets another.
 * Bye = automatic win (3 points), recorded with status = 'bye'.
 *
 * Buchholz: simple sum of all opponents' current points.
 * Rationale: transparent, verifiable by players, standard in amateur use.
 *
 * advance() generates the next round only after all matches in the current
 * round have accepted results. Calling it on an intermediate match is safe
 * (no-op until the round is complete).
 */
class Swiss implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        if (count($teamIds) < 2) {
            throw new \InvalidArgumentException('At least 2 teams required.');
        }

        $mode    = ($config['mode'] ?? 'manual') === 'random' ? 'random' : 'manual';
        $stageId = isset($config['stage_id']) ? (int)$config['stage_id'] : null;

        if ($mode === 'random') shuffle($teamIds);

        $n           = count($teamIds);
        $totalRounds = (int)($config['rounds'] ?? (int)ceil(log($n, 2)));

        // Persist total rounds in format_config
        $db = db();
        $stmt = $db->prepare('SELECT format_config FROM tournaments WHERE id = ?');
        $stmt->execute([$tournamentId]);
        $existing = json_decode($stmt->fetchColumn() ?: '{}', true) ?? [];
        $existing['rounds'] = $totalRounds;
        $db->prepare('UPDATE tournaments SET format_config = ? WHERE id = ?')
           ->execute([json_encode($existing), $tournamentId]);

        // Clear any existing matches/pairings
        if ($stageId) {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND stage_id = ?')
               ->execute([$tournamentId, $stageId]);
        } else {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND stage_id IS NULL')
               ->execute([$tournamentId]);
        }
        $db->prepare('DELETE FROM swiss_pairings WHERE tournament_id = ?')
           ->execute([$tournamentId]);

        // Generate round 1
        $this->createRound($tournamentId, $stageId, 1, $teamIds);
    }

    public function advance(int $matchId): void
    {
        $stmt = db()->prepare(
            'SELECT m.round, m.tournament_id, m.stage_id, t.format_config
             FROM matches m
             JOIN tournaments t ON t.id = m.tournament_id
             WHERE m.id = ?'
        );
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) return;

        $config      = json_decode($match['format_config'] ?? '{}', true) ?? [];
        $totalRounds = (int)($config['rounds'] ?? 0);
        if ($totalRounds < 1) return;

        $tournamentId = (int)$match['tournament_id'];
        $stageId      = $match['stage_id'] ? (int)$match['stage_id'] : null;
        $currentRound = (int)$match['round'];

        if ($currentRound >= $totalRounds) return; // Final round already

        // Check whether all matches in the current round are settled
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = ? AND round = ? $stageClause
               AND status NOT IN ('accepted', 'bye')"
        );
        $params = [$tournamentId, $currentRound];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);

        if ((int)$stmt->fetchColumn() > 0) return; // Round not yet complete

        // Build standings to determine next-round order
        $standings = $this->standings($tournamentId, $stageId);
        $teamIds   = array_column($standings, 'team_id');

        $this->createRound($tournamentId, $stageId, $currentRound + 1, $teamIds);
    }

    public function standings(int $tournamentId, ?int $stageId = null): array
    {
        $db = db();

        // Fetch all accepted / bye matches
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $mStmt = db()->prepare(
            "SELECT home_team_id, away_team_id, home_score, away_score, winner_id, status
             FROM matches WHERE tournament_id = ? AND status IN ('accepted', 'bye') $stageClause"
        );
        $mParams = [$tournamentId];
        if ($stageId) $mParams[] = $stageId;
        $mStmt->execute($mParams);
        $matches = $mStmt->fetchAll();

        // Initialise records for all approved teams
        $stmt = $db->prepare(
            "SELECT tt.team_id, t.name FROM tournament_teams tt
             JOIN teams t ON t.id = tt.team_id
             WHERE tt.tournament_id = ? AND tt.status = 'approved'"
        );
        $stmt->execute([$tournamentId]);

        $pts       = [];
        $opponents = [];
        $names     = [];

        foreach ($stmt->fetchAll() as $row) {
            $id            = (int)$row['team_id'];
            $pts[$id]      = 0;
            $opponents[$id] = [];
            $names[$id]    = $row['name'];
        }

        foreach ($matches as $m) {
            $home = (int)$m['home_team_id'];
            $away = (int)$m['away_team_id'];

            if ($m['status'] === 'bye') {
                // Bye match: home_team_id = the team, away = null, winner = home
                $pts[$home] = ($pts[$home] ?? 0) + 3;
                continue;
            }

            if (!$home || !$away) continue;

            $opponents[$home][] = $away;
            $opponents[$away][] = $home;

            if ($m['winner_id'] === null) {
                $pts[$home] = ($pts[$home] ?? 0) + 1;
                $pts[$away] = ($pts[$away] ?? 0) + 1;
            } elseif ((int)$m['winner_id'] === $home) {
                $pts[$home] = ($pts[$home] ?? 0) + 3;
            } else {
                $pts[$away] = ($pts[$away] ?? 0) + 3;
            }
        }

        // Buchholz = sum of opponents' current points
        $buchholz = [];
        foreach (array_keys($pts) as $t) {
            $bh = 0;
            foreach ($opponents[$t] ?? [] as $opp) {
                $bh += $pts[$opp] ?? 0;
            }
            $buchholz[$t] = $bh;
        }

        $standings = [];
        foreach (array_keys($pts) as $t) {
            $standings[] = [
                'team_id'  => $t,
                'name'     => $names[$t] ?? '?',
                'points'   => $pts[$t] ?? 0,
                'buchholz' => $buchholz[$t] ?? 0,
            ];
        }

        usort($standings, fn($a, $b) =>
            $b['points'] <=> $a['points'] ?: $b['buchholz'] <=> $a['buchholz']
        );

        return $standings;
    }

    public function isComplete(int $tournamentId, ?int $stageId = null): bool
    {
        $db = db();

        // Complete when all rounds have been played
        $stmt = $db->prepare('SELECT format_config FROM tournaments WHERE id = ?');
        $stmt->execute([$tournamentId]);
        $config      = json_decode($stmt->fetchColumn() ?: '{}', true) ?? [];
        $totalRounds = (int)($config['rounds'] ?? 0);
        if ($totalRounds < 1) return false;

        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $stmt = $db->prepare(
            "SELECT MAX(round) FROM matches WHERE tournament_id = ? $stageClause"
        );
        $params = [$tournamentId];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        $lastRound = (int)$stmt->fetchColumn();

        if ($lastRound < $totalRounds) return false;

        // And all matches in the last round are settled
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = ? AND round = ? $stageClause
               AND status NOT IN ('accepted', 'bye')"
        );
        $params = [$tournamentId, $lastRound];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() === 0;
    }

    // -------------------------------------------------------------------------

    private function createRound(int $tournamentId, ?int $stageId, int $round, array $teamIds): void
    {
        $db  = db();
        $bye = null;

        // Odd number of teams — assign a bye
        if (count($teamIds) % 2 !== 0) {
            $bye     = $this->pickByeTeam($teamIds, $tournamentId);
            $teamIds = array_values(array_filter($teamIds, fn($t) => $t !== $bye));
        }

        $pairs = $this->greedyPair($teamIds, $tournamentId);

        $db->beginTransaction();

        // PERF: prepare both statements once — reused per pair
        $matchStmt = $db->prepare(
            "INSERT INTO matches
             (tournament_id, stage_id, bracket_side, round, match_number, match_order,
              home_team_id, away_team_id, status)
             VALUES (?, ?, 'none', ?, ?, ?, ?, ?, 'pending')
             RETURNING id"
        );
        $pairingStmt = $db->prepare(
            'INSERT INTO swiss_pairings (tournament_id, round, team1_id, team2_id, match_id)
             VALUES (?, ?, ?, ?, ?)'
        );

        $matchNum = 1;

        foreach ($pairs as [$home, $away]) {
            $matchStmt->execute([$tournamentId, $stageId, $round, $matchNum, $matchNum, $home, $away]);
            $matchId = (int)$matchStmt->fetchColumn();
            $pairingStmt->execute([$tournamentId, $round, $home, $away, $matchId]);
            $matchNum++;
        }

        if ($bye !== null) {
            $db->prepare(
                "INSERT INTO matches
                 (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                  home_team_id, status, winner_id)
                 VALUES (?, ?, 'none', ?, ?, ?, ?, 'bye', ?)"
            )->execute([$tournamentId, $stageId, $round, $matchNum, $matchNum, $bye, $bye]);
        }

        $db->commit();
    }

    /**
     * Pick the bye team: lowest-ranked team without a prior bye.
     * Falls back to lowest-ranked if all have had one.
     */
    private function pickByeTeam(array $teamIds, int $tournamentId): int
    {
        $hasBye = db()->prepare(
            "SELECT DISTINCT home_team_id FROM matches
             WHERE tournament_id = ? AND status = 'bye'"
        );
        $hasBye->execute([$tournamentId]);
        $byeSet = array_flip(array_column($hasBye->fetchAll(), 'home_team_id'));

        // teamIds is already sorted worst-first (reversed standings), pick last
        $reversed = array_reverse($teamIds);
        foreach ($reversed as $t) {
            if (!isset($byeSet[$t])) return $t;
        }
        return end($reversed); // fallback: lowest again
    }

    /**
     * Greedy pairing: pair each team with the closest-ranked opponent they
     * haven't already played. Falls back to a forced rematch if needed.
     *
     * PERF: prior pairings are loaded in one query before the loop, replacing
     * the O(n²) per-pair havePlayed() DB calls that existed previously.
     */
    private function greedyPair(array $teams, int $tournamentId): array
    {
        // Load all prior pairings for this tournament in one shot
        $stmt = db()->prepare(
            'SELECT team1_id, team2_id FROM swiss_pairings WHERE tournament_id = ?'
        );
        $stmt->execute([$tournamentId]);
        $played = [];
        foreach ($stmt->fetchAll() as $row) {
            $a = min((int)$row['team1_id'], (int)$row['team2_id']);
            $b = max((int)$row['team1_id'], (int)$row['team2_id']);
            $played["{$a}_{$b}"] = true;
        }

        $pairs = [];
        $used  = [];

        foreach ($teams as $i => $t) {
            if (in_array($t, $used, true)) continue;

            $found = false;
            for ($j = $i + 1; $j < count($teams); $j++) {
                $opp = $teams[$j];
                if (in_array($opp, $used, true)) continue;
                $a = min($t, $opp);
                $b = max($t, $opp);
                if (!isset($played["{$a}_{$b}"])) {
                    $pairs[] = [$t, $opp];
                    $used[]  = $t;
                    $used[]  = $opp;
                    $found   = true;
                    break;
                }
            }

            if (!$found) {
                // Force rematch with closest available
                for ($j = $i + 1; $j < count($teams); $j++) {
                    $opp = $teams[$j];
                    if (!in_array($opp, $used, true)) {
                        $pairs[] = [$t, $opp];
                        $used[]  = $t;
                        $used[]  = $opp;
                        break;
                    }
                }
            }
        }

        return $pairs;
    }
}
