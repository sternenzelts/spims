<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $partId === null ? __('New spare part') : __('Edit spare part') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2">
            <flux:input wire:model="part_number" :label="__('Part number')" :disabled="! $this->canEditAllFields" required />
            @if ($duplicateOfId !== null)
                <flux:link :href="route('parts.show', $duplicateOfId)" wire:navigate class="text-sm">{{ __('Open the existing spare part') }}</flux:link>
            @endif
        </div>

        <flux:input wire:model="description" :label="__('Description')" required />
        <flux:input wire:model="unit_of_measure" :label="__('Unit of measure')" :description="__('Such as pcs, m, L or kg. Fixed once the part has stock movements.')" required />

        <flux:select wire:model="default_location_id" :label="__('Storage location')">
            <flux:select.option value="">{{ __('Not assigned') }}</flux:select.option>
            @foreach ($this->locationOptions as $location)
                <flux:select.option :value="$location->id">{{ $location->code }} — {{ $location->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->canEditAllFields)
            <div class="space-y-3">
                <flux:input wire:model.live.debounce.300ms="equipmentFilter" icon="magnifying-glass" :label="__('Used on equipment')" :placeholder="__('Filter equipment')" />

                <flux:checkbox.group wire:model="equipment_ids">
                    @foreach ($this->equipmentOptions as $machine)
                        <flux:checkbox :value="(string) $machine->id" :label="$machine->code.' — '.$machine->name" />
                    @endforeach
                </flux:checkbox.group>
            </div>

            @if ($partId !== null)
                <flux:switch wire:model.live="is_active" :label="__('Active')" />

                @if (! $is_active && $this->holdsStock)
                    <flux:checkbox wire:model="confirmDeactivation" :label="__('This part still has stock. I understand it will no longer be available for withdrawal.')" />
                    <flux:error name="confirmDeactivation" />
                @endif
            @endif
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="$partId === null ? route('parts.index') : route('parts.show', $partId)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
