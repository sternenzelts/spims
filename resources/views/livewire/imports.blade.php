@php($timezone = app(\App\Domain\Settings\InstallSettings::class)->timezone())

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Spare part imports') }}</flux:heading>

        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" :href="route('imports.template')">{{ __('Download template') }}</flux:button>
            <flux:button variant="primary" icon="arrow-up-tray" :href="route('imports.create')" wire:navigate>{{ __('New import') }}</flux:button>
        </div>
    </div>

    <flux:table :paginate="$this->batches">
        <flux:table.columns>
            <flux:table.column>{{ __('File') }}</flux:table.column>
            <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
            <flux:table.column>{{ __('By') }}</flux:table.column>
            <flux:table.column>{{ __('Rows') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->batches as $batch)
                <flux:table.row :key="$batch->id">
                    <flux:table.cell>
                        <flux:link :href="route('imports.show', $batch)" wire:navigate>{{ $batch->filename }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $batch->created_at->setTimezone($timezone)->format('Y-m-d H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $batch->importedBy->name }}</flux:table.cell>
                    <flux:table.cell>{{ $batch->rows_total }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$batch->isImporting() ? 'blue' : $batch->status->color()">
                            {{ $batch->isImporting() ? __('Importing') : __($batch->status->label()) }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
