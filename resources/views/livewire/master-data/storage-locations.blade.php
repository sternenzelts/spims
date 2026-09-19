<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Storage locations') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('storage-locations.create')" wire:navigate>{{ __('New location') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search code or name')" class="max-w-sm" />

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->locations">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Active spare parts') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->locations as $location)
                <flux:table.row :key="$location->id">
                    <flux:table.cell>
                        <flux:link :href="route('storage-locations.edit', $location)" wire:navigate>{{ $location->code }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $location->name }}</flux:table.cell>
                    <flux:table.cell>{{ $location->active_parts_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$location->is_active ? 'green' : 'zinc'">
                            {{ $location->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
