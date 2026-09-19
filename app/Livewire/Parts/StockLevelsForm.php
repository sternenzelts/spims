<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\StockLevels;
use App\Enums\Criticality;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Stock levels')]
class StockLevelsForm extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $partId;

    public string $reorder_point = '0';

    public string $min_level = '0';

    public string $critical_level = '0';

    /** Empty when no maximum is set. */
    public string $max_level = '';

    public string $criticality = 'normal';

    /** Empty when no lead time is recorded. */
    public string $lead_time_days = '';

    public bool $confirmWarnings = false;

    public function mount(Part $part): void
    {
        $this->authorize('manageStockLevels', $part);

        $this->partId = $part->id;
        $this->reorder_point = Quantity::display($part->reorder_point);
        $this->min_level = Quantity::display($part->min_level);
        $this->critical_level = Quantity::display($part->critical_level);
        $this->max_level = Quantity::display($part->max_level);
        $this->criticality = $part->criticality->value;
        $this->lead_time_days = (string) $part->lead_time_days;
    }

    #[Computed]
    public function part(): Part
    {
        return Part::query()->findOrFail($this->partId);
    }

    /**
     * Warnings for the levels as typed, shown before saving. None while a value is not yet a number.
     *
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        foreach ($this->levels() as $value) {
            if ($value !== null && ! is_numeric($value)) {
                return [];
            }
        }

        return app(StockLevels::class)->warnings($this->levels());
    }

    public function save(StockLevels $stockLevels): void
    {
        $part = Part::query()->findOrFail($this->partId);
        $this->authorize('manageStockLevels', $part);

        $this->validate();

        if ($stockLevels->warnings($this->levels()) !== [] && ! $this->confirmWarnings) {
            $this->addError('confirmWarnings', __('Confirm the warning to save these levels.'));

            return;
        }

        $stockLevels->update($part, [
            ...$this->levels(),
            'criticality' => Criticality::from($this->criticality),
            'lead_time_days' => $this->lead_time_days === '' ? null : (int) $this->lead_time_days,
        ], $this->actor());

        Flux::toast(variant: 'success', text: __('Stock levels saved.'));

        $this->redirectRoute('parts.show', ['part' => $part], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $quantity = ['numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'];

        return [
            'reorder_point' => ['required', ...$quantity],
            'min_level' => ['required', ...$quantity],
            // PRD FR-SPIMS-002: blocked outright, as the parts_levels_ordered CHECK blocks it.
            'critical_level' => ['required', ...$quantity, 'lte:min_level'],
            'max_level' => ['nullable', ...$quantity, 'gte:min_level'],
            'criticality' => ['required', Rule::enum(Criticality::class)],
            'lead_time_days' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'critical_level.lte' => 'The critical level cannot be above the minimum level.',
            'max_level.gte' => 'The maximum level cannot be below the minimum level.',
        ];
    }

    /**
     * @return array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null}
     */
    private function levels(): array
    {
        return [
            'reorder_point' => $this->reorder_point,
            'min_level' => $this->min_level,
            'critical_level' => $this->critical_level,
            'max_level' => $this->max_level === '' ? null : $this->max_level,
        ];
    }
}
