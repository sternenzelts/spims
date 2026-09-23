<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Part lookup') }}</flux:heading>

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :label="__('Part number or description')"
        class="mb-6 max-w-lg"
        autofocus
    />

    @if ($this->results->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Part number') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Location') }}</flux:table.column>
                <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->results as $part)
                    <flux:table.row wire:key="lookup-{{ $part->id }}">
                        <flux:table.cell>{{ $part->part_number }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $part->description }}</flux:table.cell>
                        <flux:table.cell>{{ $part->defaultLocation?->code }}</flux:table.cell>
                        <flux:table.cell align="end">
                            {{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">
                                {{ $part->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
