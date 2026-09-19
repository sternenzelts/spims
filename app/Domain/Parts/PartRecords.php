<?php

namespace App\Domain\Parts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Parts\Exceptions\DuplicatePartNumber;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spare part records (PRD FR-SPIMS-001). The stock balance is never entered here: a new part starts
 * at zero through the parts_create_stock_row trigger and changes only through stock movements.
 * Parts are set to Inactive, never deleted.
 */
final readonly class PartRecords
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{part_number: string, description: string, unit_of_measure: string, default_location_id: int|null}  $attributes
     * @param  list<int>  $equipmentIds
     *
     * @throws DuplicatePartNumber
     * @throws PartChangeRefused
     */
    public function create(array $attributes, array $equipmentIds, User $actor): Part
    {
        $partNumber = trim($attributes['part_number']);
        $this->guardUniquePartNumber($partNumber, null);
        $this->guardActiveLocation($attributes['default_location_id'], null);

        return DB::transaction(function () use ($attributes, $partNumber, $equipmentIds, $actor): Part {
            $part = Part::query()->create([
                'part_number' => $partNumber,
                'description' => trim($attributes['description']),
                'unit_of_measure' => trim($attributes['unit_of_measure']),
                'default_location_id' => $attributes['default_location_id'],
                'is_active' => true,
            ]);

            // Plain ids: every row gets the same pivot keys (Plan 1 Task 5 review).
            $part->equipment()->sync($equipmentIds);

            $this->audit->record('created', $part, null, $this->snapshot($part), $actor);

            return $part;
        });
    }

    /**
     * Change only the fields given, so a custodian's save cannot touch the others.
     *
     * @param  array{part_number?: string, description?: string, unit_of_measure?: string, default_location_id?: int|null, is_active?: bool}  $changes
     * @param  list<int>|null  $equipmentIds  null leaves the equipment links as they are
     *
     * @throws DuplicatePartNumber
     * @throws PartChangeRefused
     */
    public function update(Part $part, array $changes, ?array $equipmentIds, User $actor): void
    {
        foreach (['part_number', 'description', 'unit_of_measure'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = trim($changes[$field]);
            }
        }

        if (isset($changes['part_number'])) {
            $this->guardUniquePartNumber($changes['part_number'], $part);
        }

        if (array_key_exists('default_location_id', $changes)) {
            $this->guardActiveLocation($changes['default_location_id'], $part);
        }

        // PRD FR-SPIMS-003: reactivating a part must not leave it at a location that has since been
        // deactivated, even when the location itself is not part of this change.
        if (($changes['is_active'] ?? null) === true && ! $part->is_active) {
            $locationId = array_key_exists('default_location_id', $changes)
                ? $changes['default_location_id']
                : $part->default_location_id;

            $this->guardActiveLocation($locationId, null);
        }

        if (isset($changes['unit_of_measure']) && $changes['unit_of_measure'] !== $part->unit_of_measure) {
            $this->guardUnitOfMeasureUnlocked($part);
        }

        DB::transaction(function () use ($part, $changes, $equipmentIds, $actor): void {
            $old = $this->snapshot($part);

            $part->forceFill($changes)->save();

            if ($equipmentIds !== null) {
                $part->equipment()->sync($equipmentIds);
            }

            $new = $this->snapshot($part);

            $this->audit->recordChanges($part, $old, $new, $actor);
        });
    }

    /**
     * @throws DuplicatePartNumber
     */
    private function guardUniquePartNumber(string $partNumber, ?Part $except): void
    {
        $existing = Part::query()
            ->whereRaw('lower(part_number) = ?', [Str::lower($partNumber)])
            ->when($except, fn ($query, Part $except) => $query->whereKeyNot($except->id))
            ->first();

        if ($existing !== null) {
            throw new DuplicatePartNumber($existing);
        }
    }

    /**
     * PRD FR-SPIMS-003: Inactive locations cannot be assigned. A part may keep the location it already has.
     *
     * @throws PartChangeRefused
     */
    private function guardActiveLocation(?int $locationId, ?Part $part): void
    {
        if ($locationId === null || $locationId === $part?->default_location_id) {
            return;
        }

        if (! StorageLocation::query()->whereKey($locationId)->where('is_active', true)->exists()) {
            throw PartChangeRefused::inactiveLocation();
        }
    }

    /**
     * @throws PartChangeRefused
     */
    private function guardUnitOfMeasureUnlocked(Part $part): void
    {
        if (StockMovement::query()->where('part_id', $part->id)->exists()) {
            throw PartChangeRefused::unitOfMeasureLocked();
        }
    }

    /**
     * What an audit entry shows: codes rather than ids, read fresh from the database.
     *
     * @return array{part_number: string, description: string, unit_of_measure: string, default_location: string|null, is_active: bool, equipment: list<string>}
     */
    private function snapshot(Part $part): array
    {
        return [
            'part_number' => $part->part_number,
            'description' => $part->description,
            'unit_of_measure' => $part->unit_of_measure,
            'default_location' => $part->default_location_id === null
                ? null
                : (string) StorageLocation::query()->whereKey($part->default_location_id)->value('code'),
            'is_active' => $part->is_active,
            'equipment' => array_values(array_map(
                fn (mixed $code): string => (string) $code,
                $part->equipment()->orderBy('code')->pluck('code')->all(),
            )),
        ];
    }
}
