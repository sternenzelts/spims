<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Personnel') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('personnel.create')" wire:navigate>{{ __('Register personnel') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Employee number, name or department')" class="max-w-sm" />

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->personnel">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee number') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Badge') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->personnel as $person)
                <flux:table.row :key="$person->id">
                    <flux:table.cell>
                        <flux:link :href="route('personnel.edit', $person)" wire:navigate>{{ $person->employee_no }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $person->full_name }}</flux:table.cell>
                    <flux:table.cell>{{ $person->department }}</flux:table.cell>
                    <flux:table.cell>{{ $person->badge_uid_masked ?? __('Not enrolled') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$person->is_active ? 'green' : 'zinc'">
                            {{ $person->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
