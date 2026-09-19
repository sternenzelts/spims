<?php

namespace App\Domain\MasterData;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Models\StorageLocation;
use App\Models\User;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where spare parts are kept (PRD FR-SPIMS-003). A location is set to Inactive, never deleted, and
 * only once no Active spare part is assigned to it.
 */
final readonly class StorageLocations
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws MasterDataChangeRefused
     */
    public function create(string $code, string $name, ?string $description, User $actor): StorageLocation
    {
        $this->guardUniqueCode($code, null);

        return DB::transaction(function () use ($code, $name, $description, $actor): StorageLocation {
            $location = StorageLocation::query()->create([
                'code' => $code,
                'name' => trim($name),
                'description' => Text::nullIfBlank($description),
                'is_active' => true,
            ]);

            $this->audit->record('created', $location, null, $this->snapshot($location), $actor);

            return $location;
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    public function update(StorageLocation $location, string $code, string $name, ?string $description, bool $isActive, User $actor): void
    {
        $this->guardUniqueCode($code, $location);

        DB::transaction(function () use ($location, $code, $name, $description, $isActive, $actor): void {
            if ($location->is_active && ! $isActive) {
                $this->guardNoActiveParts($location);
            }

            $old = $this->snapshot($location);

            $location->forceFill([
                'code' => $code,
                'name' => trim($name),
                'description' => Text::nullIfBlank($description),
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($location);

            $this->audit->recordChanges($location, $old, $new, $actor);
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    private function guardUniqueCode(string $code, ?StorageLocation $except): void
    {
        $code = Str::upper(trim($code));

        $taken = StorageLocation::query()
            ->where('code', $code)
            ->when($except, fn ($query, StorageLocation $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw MasterDataChangeRefused::duplicateCode($code);
        }
    }

    /**
     * PRD FR-SPIMS-003: a location still assigned to Active spare parts stays Active.
     *
     * @throws MasterDataChangeRefused
     */
    private function guardNoActiveParts(StorageLocation $location): void
    {
        $partNumbers = $location->parts()->where('is_active', true)->orderBy('part_number')->pluck('part_number');

        if ($partNumbers->isNotEmpty()) {
            throw MasterDataChangeRefused::locationHoldsActiveParts(
                $location,
                array_values($partNumbers->map(fn (mixed $number): string => (string) $number)->all()),
            );
        }
    }

    /**
     * @return array{code: string, name: string, description: string|null, is_active: bool}
     */
    private function snapshot(StorageLocation $location): array
    {
        return [
            'code' => $location->code,
            'name' => $location->name,
            'description' => $location->description,
            'is_active' => $location->is_active,
        ];
    }
}
