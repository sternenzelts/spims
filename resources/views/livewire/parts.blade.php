<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Spare parts') }}</flux:heading>

        @can('create', \App\Models\Part::class)
            <flux:button variant="primary" icon="plus" :href="route('parts.create')" wire:navigate>{{ __('New spare part') }}</flux:button>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Part number or description')" class="max-w-sm" autofocus />

        <flux:select wire:model.live="criticality" class="max-w-xs">
            <flux:select.option value="">{{ __('Any criticality') }}</flux:select.option>
            @foreach (\App\Enums\Criticality::cases() as $option)
                <flux:select.option :value="$option->value">{{ __(ucfirst($option->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="location" class="max-w-xs">
            <flux:select.option value="">{{ __('Any location') }}</flux:select.option>
            @foreach ($this->locations as $option)
                <flux:select.option :value="$option->id">{{ $option->code }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->parts">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
            <flux:table.column>{{ __('Criticality') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->parts as $part)
                <flux:table.row :key="$part->id">
                    <flux:table.cell>
                        <flux:link :href="route('parts.show', $part)" wire:navigate>{{ $part->part_number }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $part->description }}</flux:table.cell>
                    <flux:table.cell>{{ $part->defaultLocation?->code }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell>{{ __(ucfirst($part->criticality->value)) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">
                            {{ $part->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
