<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('Stock levels') }}</flux:heading>
    <flux:subheading class="mb-6">
        {{ $this->part->part_number }} — {{ $this->part->description }} ({{ $this->part->unit_of_measure }})
    </flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model.live.blur="reorder_point" :label="__('Reorder point')" :description="__('Request replenishment at this level.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="min_level" :label="__('Minimum level')" :description="__('Safety stock. Reaching it means replenishment is overdue.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="critical_level" :label="__('Critical level')" :description="__('Equipment downtime risk is imminent at this level.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="max_level" :label="__('Maximum level')" :description="__('Optional. The ceiling for a replenishment.')" inputmode="decimal" />
        </div>

        <flux:select wire:model="criticality" :label="__('Criticality')">
            @foreach (\App\Enums\Criticality::cases() as $option)
                <flux:select.option :value="$option->value">{{ __(ucfirst($option->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="lead_time_days" type="number" min="1" step="1" :label="__('Lead time (days)')" :description="__('Optional. Typical days from order to receipt.')" />

        @foreach ($this->warnings as $warning)
            <flux:callout variant="warning" icon="exclamation-triangle" :text="__($warning)" />
        @endforeach

        @if ($this->warnings !== [])
            <flux:checkbox wire:model="confirmWarnings" :label="__('Save these levels anyway')" />
            <flux:error name="confirmWarnings" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('parts.show', $partId)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
