-- Add group_label column for multi-stage group tracking.
-- bracket_side stays 'none' for all group-phase matches;
-- the group letter ('A', 'B', …) is stored here instead.

ALTER TABLE matches ADD COLUMN IF NOT EXISTS group_label TEXT;
