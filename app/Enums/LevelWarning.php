<?php

namespace App\Enums;

/**
 * How far a withdrawal would take a spare part down (PRD FR-SPIMS-006 step 7). Critical is the
 * more severe of the two, and a line can only carry one.
 */
enum LevelWarning: string
{
    case Critical = 'critical';
    case Minimum = 'minimum';

    public function label(): string
    {
        return match ($this) {
            self::Critical => __('At or below the critical level'),
            self::Minimum => __('At or below the minimum level'),
        };
    }
}
