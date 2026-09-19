<?php

namespace App\Livewire\Personnel;

use App\Enums\Permission;
use App\Models\Personnel;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Personnel')]
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
        $this->authorize(Permission::PersonnelManage);
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
     * @return LengthAwarePaginator<int, Personnel>
     */
    #[Computed]
    public function personnel(): LengthAwarePaginator
    {
        return Personnel::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('employee_no', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('full_name', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('department', 'ilike', SearchTerm::contains($this->search))))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('full_name')
            ->paginate(25);
    }
}
