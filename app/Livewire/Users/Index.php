<?php

namespace App\Livewire\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    public function mount(): void
    {
        $this->authorize(Permission::UsersManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $role = Role::tryFrom($this->role);

        return User::query()
            ->with('roles')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('email', 'ilike', SearchTerm::contains($this->search))))
            ->when($role, fn ($query, Role $role) => $query->role($role))
            ->orderBy('name')
            ->paginate(25);
    }
}
