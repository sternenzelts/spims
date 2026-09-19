<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $locationId === null ? __('New storage location') : __('Edit storage location') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="code" :label="__('Code')" :description="__('Stored in capitals, such as A-01.')" required />
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        @if ($locationId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" />
            <flux:error name="is_active" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('storage-locations.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
