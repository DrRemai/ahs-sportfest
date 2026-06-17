<?php
declare(strict_types=1);

class FormatFactory
{
    public static function make(string $format): FormatInterface
    {
        return match ($format) {
            'single_elim'  => new SingleElim(),
            'double_elim'  => new DoubleElim(),
            'round_robin'  => new RoundRobin(),
            'swiss'        => new Swiss(),
            'multi_stage'  => new MultiStage(),
            default        => throw new \InvalidArgumentException("Unknown format: {$format}"),
        };
    }

    /** All valid format identifiers. */
    public static function validFormats(): array
    {
        return ['single_elim', 'double_elim', 'round_robin', 'swiss', 'multi_stage'];
    }
}
