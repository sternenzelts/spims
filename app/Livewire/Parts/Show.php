<?php

namespace App\Livewire\Parts;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Part;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Changes to this spare part and its stock levels, newest first. PRD §8 shows them to
     * PartAuditView holders; the view checks that.
     *
     * @return Collection<int, AuditLog>
     */
    #[Computed]
    public function history(): Collection
    {
        return AuditLog::query()
            ->with('user')
            ->where('auditable_type', (new Part)->getMorphClass())
            ->where('auditable_id', (string) $this->partId)
            ->latest('id')
            ->limit(50)
            ->get();
    }
}
