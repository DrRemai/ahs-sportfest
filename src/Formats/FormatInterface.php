<?php
declare(strict_types=1);

interface FormatInterface
{
    /**
     * Generate the initial set of matches for a tournament (or stage).
     *
     * @param int   $tournamentId
     * @param int[] $teamIds      Ordered list (seed 1 = index 0).
     * @param array $config       Format-specific options. May include:
     *                              'mode'     => 'manual'|'random'
     *                              'stage_id' => int (multi-stage use)
     *                              (plus format-specific keys)
     */
    public function generate(int $tournamentId, array $teamIds, array $config): void;

    /**
     * Called after a match result is accepted.
     * Creates or fills the next match(es) in the tournament structure.
     */
    public function advance(int $matchId): void;

    /**
     * Return the current standings for this format.
     * Structure varies by format; see individual implementations.
     *
     * @param int      $tournamentId
     * @param int|null $stageId  Scope to a specific stage (multi-stage use).
     */
    public function standings(int $tournamentId, ?int $stageId = null): array;

    /**
     * Whether the tournament (or stage, if stageId given) is fully complete —
     * i.e. a champion/final standings can be declared.
     */
    public function isComplete(int $tournamentId, ?int $stageId = null): bool;
}
