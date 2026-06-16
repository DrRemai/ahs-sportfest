<?php
declare(strict_types=1);

class SingleElim implements FormatInterface
{
    public function generate(int $tournamentId, array $teamIds, array $config): void
    {
        if (count($teamIds) < 2) {
            throw new \InvalidArgumentException('At least 2 teams required.');
        }

        $mode    = ($config['mode'] ?? 'manual') === 'random' ? 'random' : 'manual';
        $pairing = $config['pairing'] ?? 'standard';
        $stageId = isset($config['stage_id']) ? (int)$config['stage_id'] : null;

        if ($mode === 'random') shuffle($teamIds);

        // Expand to next power of 2; null slots become byes
        $n    = count($teamIds);
        $size = 1;
        while ($size < $n) $size <<= 1;
        while (count($teamIds) < $size) $teamIds[] = null;

        $db = db();

        // Idempotent: clear existing round-1 matches for this tournament/stage
        if ($stageId) {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND round = 1 AND stage_id = ?')
               ->execute([$tournamentId, $stageId]);
        } else {
            $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND round = 1 AND stage_id IS NULL')
               ->execute([$tournamentId]);
        }

        $matchNum = 1;

        if ($pairing === 'sequential') {
            // Sequential pairing: (0,1), (2,3), (4,5), (6,7), …
            // Used with cross-bracket seeding so manually ordered seed list
            // is paired consecutively rather than by bracket position.
            for ($i = 0; $i < $size / 2; $i++) {
                $home = $teamIds[$i * 2]     ?? null;
                $away = $teamIds[$i * 2 + 1] ?? null;
                $this->insertMatch($db, $tournamentId, $stageId, $matchNum, $home, $away);
                $matchNum++;
            }
        } else {
            // Standard seeding: slot[0] vs slot[size-1], slot[1] vs slot[size-2], …
            // Ensures seed 1 and seed 2 are on opposite halves of the bracket.
            for ($i = 0; $i < $size / 2; $i++) {
                $home = $teamIds[$i];
                $away = $teamIds[$size - 1 - $i];
                $this->insertMatch($db, $tournamentId, $stageId, $matchNum, $home, $away);
                $matchNum++;
            }
        }

        // Advance bye matches immediately so later rounds get their slots filled
        $byes = $db->prepare(
            $stageId
                ? "SELECT id FROM matches WHERE tournament_id = ? AND round = 1 AND status = 'bye' AND stage_id = ?"
                : "SELECT id FROM matches WHERE tournament_id = ? AND round = 1 AND status = 'bye' AND stage_id IS NULL"
        );
        $params = $stageId ? [$tournamentId, $stageId] : [$tournamentId];
        $byes->execute($params);
        foreach ($byes->fetchAll() as $row) {
            $this->advance((int)$row['id']);
        }
    }

    private function insertMatch(\PDO $db, int $tournamentId, ?int $stageId, int $matchNum, $home, $away): void
    {
        if ($home === null && $away === null) return;

        if ($home === null || $away === null) {
            $winner = $home ?? $away;
            $db->prepare(
                "INSERT INTO matches
                 (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                  home_team_id, away_team_id, status, winner_id)
                 VALUES (?, ?, 'none', 1, ?, ?, ?, ?, 'bye', ?)"
            )->execute([$tournamentId, $stageId, $matchNum, $matchNum, $home, $away, $winner]);
        } else {
            $db->prepare(
                "INSERT INTO matches
                 (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                  home_team_id, away_team_id, status)
                 VALUES (?, ?, 'none', 1, ?, ?, ?, ?, 'pending')"
            )->execute([$tournamentId, $stageId, $matchNum, $matchNum, $home, $away]);
        }
    }

    public function advance(int $matchId): void
    {
        $stmt = db()->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match || !$match['winner_id']) return;

        $nextRound    = (int)$match['round'] + 1;
        $nextMatchNum = (int)ceil((int)$match['match_number'] / 2);
        $isHome       = ((int)$match['match_number'] % 2 !== 0);
        $stageId      = $match['stage_id'] ? (int)$match['stage_id'] : null;
        $db           = db();

        // If only one match in this round it is the final — do not create next round
        $stageClause = $stageId ? 'AND stage_id = ?' : 'AND stage_id IS NULL';
        $rcStmt  = $db->prepare(
            "SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round = ? $stageClause"
        );
        $rcParams = [$match['tournament_id'], $match['round']];
        if ($stageId) $rcParams[] = $stageId;
        $rcStmt->execute($rcParams);
        $matchesInRound = (int)$rcStmt->fetchColumn();
        if ($matchesInRound === 1) return;

        // Find or create the next-round match
        $findStmt = $db->prepare(
            "SELECT id FROM matches
             WHERE tournament_id = ? AND round = ? AND match_number = ?
               AND bracket_side = 'none' $stageClause"
        );
        $findParams = [$match['tournament_id'], $nextRound, $nextMatchNum];
        if ($stageId) $findParams[] = $stageId;
        $findStmt->execute($findParams);
        $nextMatch = $findStmt->fetch();

        if ($nextMatch) {
            $col = $isHome ? 'home_team_id' : 'away_team_id';
            $db->prepare("UPDATE matches SET {$col} = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$match['winner_id'], $nextMatch['id']]);
        } else {
            if ($isHome) {
                $db->prepare(
                    "INSERT INTO matches
                     (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                      home_team_id, status)
                     VALUES (?, ?, 'none', ?, ?, ?, ?, 'pending')"
                )->execute([
                    $match['tournament_id'], $stageId,
                    $nextRound, $nextMatchNum, $nextMatchNum,
                    $match['winner_id'],
                ]);
            } else {
                $db->prepare(
                    "INSERT INTO matches
                     (tournament_id, stage_id, bracket_side, round, match_number, match_order,
                      away_team_id, status)
                     VALUES (?, ?, 'none', ?, ?, ?, ?, 'pending')"
                )->execute([
                    $match['tournament_id'], $stageId,
                    $nextRound, $nextMatchNum, $nextMatchNum,
                    $match['winner_id'],
                ]);
            }
        }
    }

    public function standings(int $tournamentId, ?int $stageId = null): array
    {
        // Standings for single elim = placement based on round reached.
        $stageClause = $stageId ? 'AND m.stage_id = ?' : 'AND m.stage_id IS NULL';
        $stmt = db()->prepare(
            "SELECT m.round, m.match_number, m.winner_id,
                    ht.name AS home_name, at2.name AS away_name,
                    m.home_team_id, m.away_team_id
             FROM matches m
             LEFT JOIN teams ht  ON ht.id  = m.home_team_id
             LEFT JOIN teams at2 ON at2.id = m.away_team_id
             WHERE m.tournament_id = ? AND m.status = 'accepted' $stageClause
             ORDER BY m.round DESC"
        );
        $params = [$tournamentId];
        if ($stageId) $params[] = $stageId;
        $stmt->execute($params);
        $matches = $stmt->fetchAll();

        // Find the highest round played to determine the final
        if (empty($matches)) return [];

        $maxRound = (int)$matches[0]['round'];
        $losers   = [];
        $winner   = null;

        foreach ($matches as $m) {
            if (!$m['winner_id']) continue;
            $loserId = ((int)$m['winner_id'] === (int)$m['home_team_id'])
                ? $m['away_team_id'] : $m['home_team_id'];
            $loserName = ((int)$m['winner_id'] === (int)$m['home_team_id'])
                ? $m['away_name'] : $m['home_name'];

            if ((int)$m['round'] === $maxRound && !$winner) {
                $winnerName = ((int)$m['winner_id'] === (int)$m['home_team_id'])
                    ? $m['home_name'] : $m['away_name'];
                $winner = ['team_id' => (int)$m['winner_id'], 'name' => $winnerName, 'place' => 1];
            }

            if ($loserId) {
                $losers[] = ['team_id' => (int)$loserId, 'name' => $loserName, 'round_exit' => (int)$m['round']];
            }
        }

        // Sort losers: later exit = higher placement
        usort($losers, fn($a, $b) => $b['round_exit'] <=> $a['round_exit']);
        $place = 2;
        foreach ($losers as &$l) {
            $l['place'] = $place++;
        }
        unset($l);

        return $winner ? array_merge([$winner], $losers) : $losers;
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
}
