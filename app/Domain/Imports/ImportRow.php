<?php

namespace App\Domain\Imports;

use App\Enums\Criticality;

/**
 * One row of an import that passed every check, in the form the writer applies.
 */
final readonly class ImportRow
{
    /**
     * @param  int|null  $partId  the existing part this row updates, or null to create one
     * @param  array{description?: string, unit_of_measure?: string, default_location_id?: int}  $details  the part fields the row sets
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: Criticality, lead_time_days: int|null}|null  $levels  the part's complete stock levels with the row's values applied, or null when the row sets none
     * @param  list<int>|null  $equipmentIds  the equipment the part is used on, or null when the row names none
     * @param  string|null  $openingQuantity  a positive quantity to three decimals, or null for no opening balance
     */
    public function __construct(
        public int $rowNumber,
        public string $partNumber,
        public ?int $partId,
        public array $details,
        public ?array $levels,
        public ?array $equipmentIds,
        public ?string $openingQuantity,
    ) {}

    public function creates(): bool
    {
        return $this->partId === null;
    }
}
