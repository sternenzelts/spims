<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\PartSearch;
use App\Enums\Criticality;
use App\Enums\Permission;
use App\Models\Part;
use App\Models\StorageLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Spare parts')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    #[Url]
    public string $criticality = '';

    /** A storage location id, or empty for every location. */
    #[Url]
    public string $location = '';

    public function mount(): void
    {
        $this->authorize(Permission::PartsView);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'criticality', 'location'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Part>
     */
    #[Computed]
    public function parts(): LengthAwarePaginator
    {
        $criticality = Criticality::tryFrom($this->criticality);

        return app(PartSearch::class)->matching($this->search)
            ->with(['stock', 'defaultLocation'])
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($criticality, fn ($query, Criticality $criticality) => $query->where('criticality', $criticality->value))
            ->when($this->location !== '', fn ($query) => $query->where('default_location_id', (int) $this->location))
            ->paginate(25);
    }

    /**
     * @return Collection<int, StorageLocation>
     */
    #[Computed]
    public function locations(): Collection
    {
        return StorageLocation::query()->orderBy('code')->get();
    }
}
