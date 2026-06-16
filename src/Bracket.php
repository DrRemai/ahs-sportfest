<?php
declare(strict_types=1);

class Bracket
{
    /**
     * Generate round-1 matches for a tournament given an ordered list of team IDs.
     * Deletes any existing round-1 matches before inserting new ones.
     *
     * @param int    $tournamentId
     * @param int[]  $teamIds   Ordered list; seed 1 = index 0.
     * @param string $mode      'manual' (use order as given) | 'random' (shuffle first)
     */
    public static function generate(int $tournamentId, array $teamIds, string $mode = 'manual'): void
    {
        if (count($teamIds) < 2) {
            throw new \InvalidArgumentException('At least 2 teams are required to generate a bracket.');
        }

        $stmt = db()->prepare('SELECT format FROM tournaments WHERE id = ?');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();

        if (!$tournament) {
            throw new \RuntimeException('Tournament not found.');
        }

        match ($tournament['format']) {
            'single_elimination' => self::generateSingleElimination($tournamentId, $teamIds, $mode),
            default              => throw new \RuntimeException(
                'Bracket generation not yet implemented for format: ' . $tournament['format']
            ),
        };
    }

    /**
     * Called after a match result is set to 'accepted'.
     * Advances the winner into the appropriate slot of the next-round match,
     * creating that match row if it does not yet exist.
     */
    public static function advance(int $matchId): void
    {
        $stmt = db()->prepare(
            'SELECT m.*, t.format
             FROM matches m
             JOIN tournaments t ON t.id = m.tournament_id
             WHERE m.id = ?'
        );
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();

        if (!$match || !$match['winner_id']) {
            return;
        }

        match ($match['format']) {
            'single_elimination' => self::advanceSingleElimination($match),
            default              => null,
        };
    }

    // -------------------------------------------------------------------------

    private static function generateSingleElimination(
        int    $tournamentId,
        array  $teamIds,
        string $mode
    ): void {
        if ($mode === 'random') {
            shuffle($teamIds);
        }

        $n = count($teamIds);

        // Expand to next power of 2; null slots become byes
        $size = 1;
        while ($size < $n) {
            $size <<= 1;
        }
        while (count($teamIds) < $size) {
            $teamIds[] = null;
        }

        $db = db();

        // Clean existing round-1 matches so re-seeding is idempotent
        $db->prepare('DELETE FROM matches WHERE tournament_id = ? AND round = 1')
           ->execute([$tournamentId]);

        // Standard seeding: slot[0] vs slot[size-1], slot[1] vs slot[size-2], …
        // This places seed 1 and seed 2 on opposite sides of the bracket.
        $matchNum = 1;
        for ($i = 0; $i < $size / 2; $i++) {
            $home = $teamIds[$i];
            $away = $teamIds[$size - 1 - $i];

            if ($home === null && $away === null) {
                continue;
            }

            if ($home === null || $away === null) {
                // One team gets a bye — they advance automatically
                $winner = $home ?? $away;
                $db->prepare(
                    'INSERT INTO matches
                     (tournament_id, round, match_number, home_team_id, away_team_id, status, winner_id)
                     VALUES (?, 1, ?, ?, ?, \'bye\', ?)'
                )->execute([$tournamentId, $matchNum, $home, $away, $winner]);
            } else {
                $db->prepare(
                    'INSERT INTO matches
                     (tournament_id, round, match_number, home_team_id, away_team_id, status)
                     VALUES (?, 1, ?, ?, ?, \'pending\')'
                )->execute([$tournamentId, $matchNum, $home, $away]);
            }

            $matchNum++;
        }

        // Immediately advance any bye matches so later rounds get their slots filled
        $byes = $db->prepare(
            'SELECT id FROM matches WHERE tournament_id = ? AND round = 1 AND status = \'bye\''
        );
        $byes->execute([$tournamentId]);
        foreach ($byes->fetchAll() as $row) {
            self::advance((int)$row['id']);
        }
    }

    private static function advanceSingleElimination(array $match): void
    {
        $nextRound    = (int)$match['round'] + 1;
        // Match M feeds into match ceil(M/2) in the next round.
        $nextMatchNum = (int)ceil((int)$match['match_number'] / 2);
        // Odd match_number → home slot; even → away slot.
        $isHome = ((int)$match['match_number'] % 2 !== 0);

        $db = db();

        $stmt = $db->prepare(
            'SELECT id FROM matches
             WHERE tournament_id = ? AND round = ? AND match_number = ?'
        );
        $stmt->execute([$match['tournament_id'], $nextRound, $nextMatchNum]);
        $nextMatch = $stmt->fetch();

        if ($nextMatch) {
            if ($isHome) {
                $db->prepare(
                    'UPDATE matches SET home_team_id = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$match['winner_id'], $nextMatch['id']]);
            } else {
                $db->prepare(
                    'UPDATE matches SET away_team_id = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$match['winner_id'], $nextMatch['id']]);
            }
        } else {
            // Next-round match doesn't exist yet — create it with one slot filled
            if ($isHome) {
                $db->prepare(
                    'INSERT INTO matches
                     (tournament_id, round, match_number, home_team_id, status)
                     VALUES (?, ?, ?, ?, \'pending\')'
                )->execute([$match['tournament_id'], $nextRound, $nextMatchNum, $match['winner_id']]);
            } else {
                $db->prepare(
                    'INSERT INTO matches
                     (tournament_id, round, match_number, away_team_id, status)
                     VALUES (?, ?, ?, ?, \'pending\')'
                )->execute([$match['tournament_id'], $nextRound, $nextMatchNum, $match['winner_id']]);
            }
        }
    }
}
