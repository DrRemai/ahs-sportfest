<?php
declare(strict_types=1);

/**
 * Double Elimination format engine.
 *
 * ALGORITHMIC DECISIONS
 * ─────────────────────
 * Losers bracket mapping (deterministic, no pre-generation):
 *   WB Round 1, match M → LB Round 1, match ceil(M/2).
 *     Odd M = home slot; even M = away slot.
 *   WB Round k (k≥2), match M → LB Round 2(k-1), match M.
 *     WB drop-in loser always occupies the 'away' slot.
 *     The 'home' slot is filled by the LB Round 2k-3 winner.
 *
 * LB internal advancement (odd LB rounds):
 *   Winner → LB Round r+1, same match number M, home slot.
 *
 * LB drop-in advancement (even LB rounds):
 *   Winner → LB Round r+1, match ceil(M/2), appropriate parity slot.
 *   LB Final winner (last even round) → Grand Final, away slot.
 *
 * Grand Final: single game, no bracket reset.
 *   WB Final winner = home; LB Final winner = away.
 *   Both GF slots are filled lazily as their respective matches complete.
 *
 * Match creation: fillOrCreateMatch() creates a match with one team when
 * the first of two teams is known, then fills the second team when it arrives.
 * Status becomes 'pending' (playable) only when both teams are set.
 *
 * total_wb_rounds is stored in tournaments.format_config on generate()
 * and re-read on every advance() call.
 */
class DoubleElim implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        if (count($teamIds) < 2) {
            throw new \InvalidArgumentException('At least 2 teams required.');
        }

        $mode    = ($config['mode'] ?? 'manual') === 'random' ? 'random' : 'manual';
        $stageId = isset($config['stage_id']) ? (int)$config['stage_id'] : null;

        if ($mode === 'random') shuffle($teamIds);

        // Expand to next power of 2
        $n    = count($teamIds);
        $size = 1;
        while ($size < $n) $size <<= 1;
        while (count($teamIds) < $size) $teamIds[] = null;

        $totalWbRounds = (int)log($size, 2);

        // Persist total_wb_rounds in format_config for use during advance()
        $db = db();
        $stmt = $db->prepare('SELECT format_config FROM tournaments WHERE id = ?');
        $stmt->execute([$tournamentId]);
        $existing = json_decode($stmt->fetchColumn() ?: '{}', true) ?? [];
        $existing['total_wb_rounds'] = $totalWbRounds;
        $db->prepare('UPDATE tournaments SET format_config = ? WHERE id = ?')
           ->execute([json_encode($existing), $tournamentId]);

        // Clear existing WB round-1 matches
        if ($stageId) {
            $db->prepare("DELETE FROM matches WHERE tournament_id=? AND round=1 AND bracket_side='winners' AND stage_id=?")
               ->execute([$tournamentId, $stageId]);
        } else {
            $db->prepare("DELETE FROM matches WHERE tournament_id=? AND round=1 AND bracket_side='winners' AND stage_id IS NULL")
               ->execute([$tournamentId]);
        }

        // Generate WB Round 1 with standard seeding (seed 1 vs seed N, etc.)
        $matchNum = 1;
        for ($i = 0; $i < $size / 2; $i++) {
            $home = $teamIds[$i];
            $away = $teamIds[$size - 1 - $i];

            if ($home === null && $away === null) continue;

            if ($home === null || $away === null) {
                $winner = $home ?? $away;
                $db->prepare(
                    "INSERT INTO matches
                     (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                      home_team_id, away_team_id, status, winner_id)
                     VALUES (?, ?, 'winners', 1, ?, ?, ?, ?, 'bye', ?)"
                )->execute([$tournamentId, $stageId, $matchNum, $matchNum, $home, $away, $winner]);
            } else {
                $db->prepare(
                    "INSERT INTO matches
                     (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                      home_team_id, away_team_id, status)
                     VALUES (?, ?, 'winners', 1, ?, ?, ?, ?, 'pending')"
                )->execute([$tournamentId, $stageId, $matchNum, $matchNum, $home, $away]);
            }
            $matchNum++;
        }

        // Advance bye matches immediately
        $byes = $db->prepare(
            $stageId
                ? "SELECT id FROM matches WHERE tournament_id=? AND round=1 AND bracket_side='winners' AND status='bye' AND stage_id=?"
                : "SELECT id FROM matches WHERE tournament_id=? AND round=1 AND bracket_side='winners' AND status='bye' AND stage_id IS NULL"
        );
        $byes->execute($stageId ? [$tournamentId, $stageId] : [$tournamentId]);
        foreach ($byes->fetchAll() as $row) {
            $this->advance((int)$row['id']);
        }
    }

    public function advance(int $matchId): void
    {
        $stmt = db()->prepare(
            'SELECT m.*, t.format_config
             FROM matches m
             JOIN tournaments t ON t.id = m.tournament_id
             WHERE m.id = ?'
        );
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();

        if (!$match || !$match['winner_id']) return;

        $config = json_decode($match['format_config'] ?? '{}', true) ?? [];
        $totalWbRounds = (int)($config['total_wb_rounds'] ?? 0);
        if ($totalWbRounds < 1) return;

        $tid      = (int)$match['tournament_id'];
        $stageId  = $match['stage_id'] ? (int)$match['stage_id'] : null;
        $round    = (int)$match['round'];
        $matchNum = (int)$match['match_number'];
        $winner   = (int)$match['winner_id'];
        $loser    = ($winner === (int)$match['home_team_id'])
                    ? (int)$match['away_team_id']
                    : (int)$match['home_team_id'];

        switch ($match['bracket_side'] ?? 'winners') {
            case 'winners':
                $this->advanceWinners($tid, $stageId, $round, $matchNum, $winner, $loser, $totalWbRounds);
                break;
            case 'losers':
                $this->advanceLosers($tid, $stageId, $round, $matchNum, $winner, $totalWbRounds);
                break;
            case 'grand_final':
                // Tournament complete — nothing to create
                break;
        }
    }

    private function advanceWinners(
        int $tid, ?int $stageId,
        int $round, int $matchNum,
        int $winner, int $loser,
        int $totalWbRounds
    ): void {
        // Advance winner in WB (or to Grand Final home slot if WB Final)
        if ($round < $totalWbRounds) {
            $nextRound = $round + 1;
            $nextMatch = (int)ceil($matchNum / 2);
            $slot      = ($matchNum % 2 === 1) ? 'home' : 'away';
            $this->fillOrCreateMatch($tid, $stageId, 'winners', $nextRound, $nextMatch, $winner, $slot);
        } else {
            // WB Final winner → Grand Final home
            $this->fillOrCreateMatch($tid, $stageId, 'grand_final', 1, 1, $winner, 'home');
        }

        // Drop loser into the losers bracket
        if ($round === 1) {
            // WB R1 losers pair up in LB R1: match ceil(M/2), parity slot
            $lbRound = 1;
            $lbMatch = (int)ceil($matchNum / 2);
            $slot    = ($matchNum % 2 === 1) ? 'home' : 'away';
        } else {
            // WB Rk (k≥2) loser drops into LB R2(k-1), same match number, away slot.
            // The home slot of that LB match is the LB R(2k-3) winner (filled separately).
            $lbRound = 2 * ($round - 1);
            $lbMatch = $matchNum;
            $slot    = 'away';
        }
        $this->fillOrCreateMatch($tid, $stageId, 'losers', $lbRound, $lbMatch, $loser, $slot);
    }

    private function advanceLosers(
        int $tid, ?int $stageId,
        int $round, int $matchNum,
        int $winner,
        int $totalWbRounds
    ): void {
        $totalLbRounds = 2 * ($totalWbRounds - 1);

        if ($round === $totalLbRounds) {
            // LB Final winner → Grand Final away
            $this->fillOrCreateMatch($tid, $stageId, 'grand_final', 1, 1, $winner, 'away');
            return;
        }

        if ($round % 2 === 1) {
            // Odd LB round (internal pairing from previous even drop-in round).
            // Winner advances to the next drop-in round at the same match position,
            // taking the home slot that waits for the incoming WB drop-in as away.
            $this->fillOrCreateMatch($tid, $stageId, 'losers', $round + 1, $matchNum, $winner, 'home');
        } else {
            // Even LB round (drop-in round from a WB losers batch).
            // Winners pair up in the next internal round: match ceil(M/2).
            $nextMatch = (int)ceil($matchNum / 2);
            $slot      = ($matchNum % 2 === 1) ? 'home' : 'away';
            $this->fillOrCreateMatch($tid, $stageId, 'losers', $round + 1, $nextMatch, $winner, $slot);
        }
    }

    private function fillOrCreateMatch(
        int $tid, ?int $stageId,
        string $side, int $round, int $matchNum,
        int $teamId, string $slot
    ): void {
        $db          = db();
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';

        $stmt = $db->prepare(
            "SELECT id, home_team_id, away_team_id FROM matches
             WHERE tournament_id = ? AND bracket_side = ? AND round = ? AND match_number = ?
               $stageClause"
        );
        $params = [$tid, $side, $round, $matchNum];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        $existing = $stmt->fetch();

        if ($existing) {
            $col = $slot === 'home' ? 'home_team_id' : 'away_team_id';
            $db->prepare("UPDATE matches SET {$col} = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$teamId, $existing['id']]);

            $home = $slot === 'home' ? $teamId : (int)($existing['home_team_id'] ?? 0);
            $away = $slot === 'away' ? $teamId : (int)($existing['away_team_id'] ?? 0);
            if ($home > 0 && $away > 0) {
                $db->prepare("UPDATE matches SET status = 'pending', updated_at = NOW() WHERE id = ?")
                   ->execute([$existing['id']]);
            }
        } else {
            $homeId = $slot === 'home' ? $teamId : null;
            $awayId = $slot === 'away' ? $teamId : null;
            $db->prepare(
                "INSERT INTO matches
                 (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                  home_team_id, away_team_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$tid, $stageId, $side, $round, $matchNum, $matchNum, $homeId, $awayId]);
        }
    }

    public function standings(int $tournamentId, ?int $stageId = null): array
    {
        // DE standings: placement by round eliminated (losers bracket aware)
        $stageClause = $stageId ? 'AND m.stage_id = ?' : 'AND m.stage_id IS NULL';
        $stmt = db()->prepare(
            "SELECT m.bracket_side, m.round, m.winner_id, m.home_team_id, m.away_team_id,
                    ht.name AS home_name, at2.name AS away_name
             FROM matches m
             LEFT JOIN teams ht  ON ht.id = m.home_team_id
             LEFT JOIN teams at2 ON at2.id = m.away_team_id
             WHERE m.tournament_id = ? AND m.status = 'accepted' $stageClause
             ORDER BY m.bracket_side, m.round DESC"
        );
        $params = [$tournamentId];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        $matches = $stmt->fetchAll();

        $placements = [];
        $place      = 1;

        foreach ($matches as $m) {
            if (!$m['winner_id']) continue;
            $loserId   = ((int)$m['winner_id'] === (int)$m['home_team_id'])
                         ? (int)$m['away_team_id'] : (int)$m['home_team_id'];
            $loserName = ((int)$m['winner_id'] === (int)$m['home_team_id'])
                         ? $m['away_name'] : $m['home_name'];

            if ($m['bracket_side'] === 'grand_final' && !isset($placements[$m['winner_id']])) {
                $placements[$m['winner_id']] = 1;
            }
            if ($loserId && !isset($placements[$loserId])) {
                $placements[$loserId] = $place++;
            }
        }

        return array_map(fn($tid, $p) => ['team_id' => $tid, 'place' => $p],
            array_keys($placements), array_values($placements));
    }

    public function isComplete(int $tournamentId, ?int $stageId = null): bool
    {
        // Complete when the Grand Final has an accepted result
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM matches
             WHERE tournament_id = ? AND bracket_side = 'grand_final'
               AND status = 'accepted' $stageClause"
        );
        $params = [$tournamentId];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}
