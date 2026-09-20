<?php

namespace App\Livewire\Imports;

use App\Enums\Permission;
use App\Models\ImportBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Spare part imports')]
class Index extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize(Permission::PartsImport);
    }

    /**
     * @return LengthAwarePaginator<int, ImportBatch>
     */
    #[Computed]
    public function batches(): LengthAwarePaginator
    {
        return ImportBatch::query()->with('importedBy')->latest('id')->paginate(25);
    }
}
