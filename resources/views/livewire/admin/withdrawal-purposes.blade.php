<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Withdrawal purposes') }}</flux:heading>
            <flux:subheading>{{ __('Offered at the station in this order. A purpose used on a transaction can be renamed or set to Inactive, never deleted.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New purpose') }}</flux:button>
    </div>

    <flux:error name="purposes" class="mb-4" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Purpose') }}</flux:table.column>
            <flux:table.column>{{ __('Remarks') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->purposes as $purpose)
                <flux:table.row :key="$purpose->id">
                    <flux:table.cell>{{ $purpose->name }}</flux:table.cell>
                    <flux:table.cell>{{ $purpose->requires_remarks ? __('Required') : __('Optional') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$purpose->is_active ? 'green' : 'zinc'">
                            {{ $purpose->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" wire:click="edit({{ $purpose->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="remove({{ $purpose->id }})" wire:confirm="{{ __('Delete this purpose? Only a purpose no transaction has used can be deleted.') }}">
                            {{ __('Delete') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="purpose-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? __('New purpose') : __('Edit purpose') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:checkbox wire:model="requires_remarks" :label="__('Requires remarks')" />

            @if ($editingId !== null)
                <flux:switch wire:model="is_active" :label="__('Active')" />
                <flux:error name="is_active" />
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
