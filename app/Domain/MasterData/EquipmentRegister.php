<?php

namespace App\Domain\MasterData;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Models\Equipment;
use App\Models\User;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The machines spare parts are used on (PRD FR-SPIMS-003). Equipment is set to Inactive, never
 * deleted: it keeps its history and its part links, and leaves the withdrawal station's choices.
 */
final readonly class EquipmentRegister
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws MasterDataChangeRefused
     */
    public function create(string $code, string $name, ?string $area, User $actor): Equipment
    {
        $this->guardUniqueCode($code, null);

        return DB::transaction(function () use ($code, $name, $area, $actor): Equipment {
            $equipment = Equipment::query()->create([
                'code' => $code,
                'name' => trim($name),
                'area' => Text::nullIfBlank($area),
                'is_active' => true,
            ]);

            $this->audit->record('created', $equipment, null, $this->snapshot($equipment), $actor);

            return $equipment;
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    public function update(Equipment $equipment, string $code, string $name, ?string $area, bool $isActive, User $actor): void
    {
        $this->guardUniqueCode($code, $equipment);

        DB::transaction(function () use ($equipment, $code, $name, $area, $isActive, $actor): void {
            $old = $this->snapshot($equipment);

            $equipment->forceFill([
                'code' => $code,
                'name' => trim($name),
                'area' => Text::nullIfBlank($area),
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($equipment);

            $this->audit->recordChanges($equipment, $old, $new, $actor);
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    private function guardUniqueCode(string $code, ?Equipment $except): void
    {
        $code = Str::upper(trim($code));

        $taken = Equipment::query()
            ->where('code', $code)
            ->when($except, fn ($query, Equipment $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw MasterDataChangeRefused::duplicateCode($code);
        }
    }

    /**
     * @return array{code: string, name: string, area: string|null, is_active: bool}
     */
    private function snapshot(Equipment $equipment): array
    {
        return [
            'code' => $equipment->code,
            'name' => $equipment->name,
            'area' => $equipment->area,
            'is_active' => $equipment->is_active,
        ];
    }
}
