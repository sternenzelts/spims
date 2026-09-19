<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $equipmentId === null ? __('New equipment') : __('Edit equipment') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="code" :label="__('Code')" :description="__('Stored in capitals, such as CNC-01.')" required />
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:input wire:model="area" :label="__('Area')" />

        @if ($equipmentId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive equipment keeps its history and cannot be chosen on new withdrawals.')" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('equipment.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if ($equipmentId !== null)
        <div class="mt-10">
            <flux:heading size="lg">{{ __('Linked spare parts') }}</flux:heading>
            <flux:subheading>{{ __('Link spare parts to this equipment from each spare part\'s form.') }}</flux:subheading>

            <ul class="mt-4 space-y-1">
                @forelse ($this->linkedParts as $part)
                    <li>
                        <flux:link :href="route('parts.show', $part)" wire:navigate>{{ $part->part_number }}</flux:link>
                        <flux:text class="inline">{{ $part->description }}</flux:text>
                    </li>
                @empty
                    <li><flux:text>{{ __('No spare parts linked yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    @endif
</section>
