<?php

namespace App\Livewire\Parts;

use App\Enums\Permission;
use App\Models\Part;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Spare part')]
class Show extends Component
{
    #[Locked]
    public int $partId;

    public function mount(Part $part): void
    {
        $this->authorize(Permission::PartsView);

        $this->partId = $part->id;
    }

    #[Computed]
    public function part(): Part
    {
        return Part::query()
            ->with(['stock', 'defaultLocation', 'equipment' => fn ($query) => $query->orderBy('code')])
            ->findOrFail($this->partId);
    }
}
