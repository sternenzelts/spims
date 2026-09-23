<?php

namespace App\Livewire\Station;

use App\Domain\Parts\PartSearch;
use App\Enums\Permission;
use App\Models\Part;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The read PRD FR-SPIMS-016 asks for at the counter: what a spare part is, where it is, and how
 * much of it there is. Nothing more — the movement history is Plan 6.
 */
#[Title('Part lookup')]
class PartLookup extends Component
{
    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::PartsView);
    }

    /**
     * @return Collection<int, Part>
     */
    #[Computed]
    public function results(): Collection
    {
        if (trim($this->search) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->search)
            ->with(['stock', 'defaultLocation'])
            ->limit(25)
            ->get();
    }
}
