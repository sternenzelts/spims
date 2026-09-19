<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\Exceptions\DuplicatePartNumber;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Domain\Parts\PartRecords;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Support\SearchTerm;
use Brick\Math\BigDecimal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Spare part')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The part being edited; null while creating one. */
    #[Locked]
    public ?int $partId = null;

    /** The existing part a refused part number belongs to, for the "open it" link. */
    #[Locked]
    public ?int $duplicateOfId = null;

    public string $part_number = '';

    public string $description = '';

    public string $unit_of_measure = 'pcs';

    /** A storage location id, or empty for none. */
    public string $default_location_id = '';

    /** @var list<string> */
    public array $equipment_ids = [];

    public string $equipmentFilter = '';

    public bool $is_active = true;

    public bool $confirmDeactivation = false;

    public function mount(?Part $part = null): void
    {
        if (! $part?->exists) {
            $this->authorize('create', Part::class);

            return;
        }

        $this->authorize('update', $part);

        $this->partId = $part->id;
        $this->part_number = $part->part_number;
        $this->description = $part->description;
        $this->unit_of_measure = $part->unit_of_measure;
        $this->default_location_id = (string) $part->default_location_id;
        $this->equipment_ids = array_values(array_map(
            fn (mixed $id): string => (string) $id,
            $part->equipment()->pluck('equipment.id')->all(),
        ));
        $this->is_active = $part->is_active;
    }

    public function save(PartRecords $records): void
    {
        $part = $this->partId === null ? null : Part::query()->findOrFail($this->partId);
        $this->authorize($part === null ? 'create' : 'update', $part ?? Part::class);
        $allFields = $this->canEditAllFields();

        $this->validate([
            'part_number' => $allFields ? ['required', 'string', 'max:100'] : [],
            'description' => ['required', 'string', 'max:500'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'default_location_id' => ['nullable', 'integer', Rule::exists('storage_locations', 'id')],
            'equipment_ids' => ['array'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')],
            'is_active' => ['boolean'],
        ]);

        $locationId = $this->default_location_id === '' ? null : (int) $this->default_location_id;
        $equipmentIds = array_map(fn (string $id): int => (int) $id, $this->equipment_ids);

        // PRD FR-SPIMS-001: warn, then save once confirmed.
        if ($part !== null && $allFields && $part->is_active && ! $this->is_active && $this->holdsStock() && ! $this->confirmDeactivation) {
            $this->addError('confirmDeactivation', __('This part still has stock. Confirm that it will no longer be available for withdrawal.'));

            return;
        }

        try {
            if ($part === null) {
                $part = $records->create([
                    'part_number' => $this->part_number,
                    'description' => $this->description,
                    'unit_of_measure' => $this->unit_of_measure,
                    'default_location_id' => $locationId,
                ], $equipmentIds, $this->actor());
            } else {
                $changes = [
                    'description' => $this->description,
                    'unit_of_measure' => $this->unit_of_measure,
                    'default_location_id' => $locationId,
                ];

                if ($allFields) {
                    $changes['part_number'] = $this->part_number;
                    $changes['is_active'] = $this->is_active;
                }

                $records->update($part, $changes, $allFields ? $equipmentIds : null, $this->actor());
            }
        } catch (DuplicatePartNumber $duplicate) {
            $this->duplicateOfId = $duplicate->existing->id;
            $this->addError('part_number', $duplicate->getMessage());

            return;
        } catch (PartChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Spare part saved.'));

        $this->redirectRoute('parts.show', ['part' => $part], navigate: true);
    }

    /**
     * Part number, status and equipment links are for PartsEdit holders; a custodian sees them
     * read-only.
     */
    #[Computed]
    public function canEditAllFields(): bool
    {
        return $this->partId === null
            || $this->actor()->can('updateAllFields', Part::query()->findOrFail($this->partId));
    }

    #[Computed]
    public function holdsStock(): bool
    {
        $onHand = $this->partId === null ? null : Part::query()->findOrFail($this->partId)->stock?->on_hand;

        return $onHand !== null && BigDecimal::of($onHand)->isPositive();
    }

    /**
     * Active locations, plus the part's current one if it has since been deactivated.
     *
     * @return Collection<int, StorageLocation>
     */
    #[Computed]
    public function locationOptions(): Collection
    {
        return StorageLocation::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($this->default_location_id !== '', fn ($query) => $query->orWhere('id', (int) $this->default_location_id)))
            ->orderBy('code')
            ->get();
    }

    /**
     * Active equipment, plus any already linked, narrowed by the filter.
     *
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function equipmentOptions(): Collection
    {
        $linked = array_map(fn (string $id): int => (int) $id, $this->equipment_ids);

        return Equipment::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $linked))
            ->when(trim($this->equipmentFilter) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'ilike', SearchTerm::contains($this->equipmentFilter))
                ->orWhere('name', 'ilike', SearchTerm::contains($this->equipmentFilter))))
            ->orderBy('code')
            ->limit(200)
            ->get();
    }
}
