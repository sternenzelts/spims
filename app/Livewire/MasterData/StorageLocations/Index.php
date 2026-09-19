<?php

namespace App\Livewire\MasterData\StorageLocations;

use App\Enums\Permission;
use App\Models\StorageLocation;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Storage locations')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, StorageLocation>
     */
    #[Computed]
    public function locations(): LengthAwarePaginator
    {
        return StorageLocation::query()
            ->withCount(['parts as active_parts_count' => fn ($query) => $query->where('is_active', true)])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('name', 'ilike', SearchTerm::contains($this->search))))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('code')
            ->paginate(25);
    }
}
