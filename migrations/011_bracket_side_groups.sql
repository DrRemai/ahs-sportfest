-- Allow single uppercase group letters (A, B, C, …) as bracket_side values.
-- The existing check only permitted 'none', 'winners', 'losers', 'grand_final'.
-- RoundRobin::generate() uses chr(65+n) ('A', 'B', …) to tag group matches.

BEGIN;

ALTER TABLE matches
    DROP CONSTRAINT IF EXISTS matches_bracket_side_check;

ALTER TABLE matches
    ADD CONSTRAINT matches_bracket_side_check
        CHECK (
            bracket_side IN ('none', 'winners', 'losers', 'grand_final')
            OR (LENGTH(bracket_side) = 1 AND bracket_side ~ '^[A-Z]$')
        );

COMMIT;
