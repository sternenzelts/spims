<?php

namespace App\Livewire\MasterData\StorageLocations;

use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Domain\MasterData\StorageLocations;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\StorageLocation;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Storage location')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The location being edited; null while creating one. */
    #[Locked]
    public ?int $locationId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public bool $is_active = true;

    public function mount(?StorageLocation $location = null): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        if ($location?->exists) {
            $this->locationId = $location->id;
            $this->code = $location->code;
            $this->name = $location->name;
            $this->description = (string) $location->description;
            $this->is_active = $location->is_active;
        }
    }

    public function save(StorageLocations $locations): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->locationId === null) {
                $locations->create($this->code, $this->name, $this->description, $this->actor());
            } else {
                $locations->update(
                    StorageLocation::query()->findOrFail($this->locationId),
                    $this->code,
                    $this->name,
                    $this->description,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (MasterDataChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Storage location saved.'));

        $this->redirectRoute('storage-locations.index', navigate: true);
    }
}
