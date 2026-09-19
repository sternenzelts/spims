<?php

namespace App\Domain\Parts;

use App\Domain\Audit\AuditLogger;
use App\Enums\Criticality;
use App\Models\Part;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * Stock levels, criticality and lead time (PRD FR-SPIMS-002, design §4.4). A critical level above
 * the minimum, or a maximum below it, is refused; the parts_levels_ordered CHECK refuses it too. A
 * reorder point outside minimum to maximum is saved after a warning, because a company may use the
 * levels differently (open question Q8).
 */
final readonly class StockLevels
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The warnings a user must confirm before these levels are saved.
     *
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null}  $levels
     * @return list<string>
     */
    public function warnings(array $levels): array
    {
        $reorder = BigDecimal::of($levels['reorder_point']);
        $warnings = [];

        if ($reorder->isLessThan($levels['min_level'])) {
            $warnings[] = 'The reorder point is below the minimum level, so replenishment would be requested only after stock falls below safety stock.';
        }

        if ($levels['max_level'] !== null && $reorder->isGreaterThan($levels['max_level'])) {
            $warnings[] = 'The reorder point is above the maximum level, so a replenishment could never lift stock above it.';
        }

        return $warnings;
    }

    /**
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: Criticality, lead_time_days: int|null}  $levels
     */
    public function update(Part $part, array $levels, User $actor): void
    {
        $old = $this->snapshot($part);

        $part->forceFill($levels)->save();
        // Read back as numeric(14,3) stores them, so "4" and "4.000" are the same value.
        $part->refresh();

        $new = $this->snapshot($part);

        $this->audit->recordChanges($part, $old, $new, $actor);
    }

    /**
     * @return array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: string, lead_time_days: int|null}
     */
    private function snapshot(Part $part): array
    {
        return [
            'reorder_point' => $part->reorder_point,
            'min_level' => $part->min_level,
            'critical_level' => $part->critical_level,
            'max_level' => $part->max_level,
            'criticality' => $part->criticality->value,
            'lead_time_days' => $part->lead_time_days,
        ];
    }
}
