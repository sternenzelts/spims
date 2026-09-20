<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-2">{{ __('New spare part import') }}</flux:heading>
    <flux:text class="mb-6">
        {{ __('Upload an .xlsx or .csv file with a header row naming its columns and one spare part per row. Nothing is saved until you check the file and confirm the import.') }}
    </flux:text>

    <form wire:submit="save" class="space-y-6">
        <flux:input type="file" wire:model="file" :label="__('File')" accept=".xlsx,.csv" />

        <div class="flex flex-wrap gap-4">
            <flux:button variant="primary" type="submit">{{ __('Upload') }}</flux:button>
            <flux:button icon="arrow-down-tray" :href="route('imports.template')">{{ __('Download template') }}</flux:button>
            <flux:button :href="route('imports.index')" wire:navigate>{{ __('Back to imports') }}</flux:button>
        </div>
    </form>
</section>
