<?php

namespace App\Livewire\MasterData\Equipment;

use App\Domain\MasterData\EquipmentRegister;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Equipment')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The equipment being edited; null while registering new equipment. */
    #[Locked]
    public ?int $equipmentId = null;

    public string $code = '';

    public string $name = '';

    public string $area = '';

    public bool $is_active = true;

    public function mount(?Equipment $equipment = null): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        if ($equipment?->exists) {
            $this->equipmentId = $equipment->id;
            $this->code = $equipment->code;
            $this->name = $equipment->name;
            $this->area = (string) $equipment->area;
            $this->is_active = $equipment->is_active;
        }
    }

    /**
     * The spare parts linked to this equipment. Links are edited on the spare part form.
     *
     * @return Collection<int, Part>
     */
    #[Computed]
    public function linkedParts(): Collection
    {
        return $this->equipmentId === null
            ? new Collection
            : Part::query()
                ->whereHas('equipment', fn ($query) => $query->whereKey($this->equipmentId))
                ->orderBy('part_number')
                ->get();
    }

    public function save(EquipmentRegister $register): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->equipmentId === null) {
                $register->create($this->code, $this->name, $this->area, $this->actor());
            } else {
                $register->update(
                    Equipment::query()->findOrFail($this->equipmentId),
                    $this->code,
                    $this->name,
                    $this->area,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (MasterDataChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Equipment saved.'));

        $this->redirectRoute('equipment.index', navigate: true);
    }
}
