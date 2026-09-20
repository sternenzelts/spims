@php($batch = $this->batch)

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $batch->filename }}</flux:heading>
            <flux:text>
                {{ __('Uploaded by :name on :date', [
                    'name' => $batch->importedBy->name,
                    'date' => $batch->created_at->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i'),
                ]) }}
            </flux:text>
        </div>

        <flux:badge :color="$batch->isImporting() ? 'blue' : $batch->status->color()">
            {{ $batch->isImporting() ? __('Importing') : __($batch->status->label()) }}
        </flux:badge>
    </div>

    <flux:error name="batch" />

    @if ($batch->isImporting())
        <div wire:poll.2s>
            <flux:callout icon="arrow-path">
                <flux:callout.heading>{{ __('The import is running.') }}</flux:callout.heading>
                <flux:callout.text>{{ __('This page updates when it finishes. You can leave it and come back.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @elseif ($batch->status === \App\Enums\ImportStatus::Completed)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.heading>{{ __('Imported') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __(':created created, :updated updated, :opening opening balances recorded.', [
                    'created' => $batch->rows_created,
                    'updated' => $batch->rows_updated,
                    'opening' => $batch->opening_balances,
                ]) }}
            </flux:callout.text>
        </flux:callout>

        <flux:button :href="route('parts.index')" wire:navigate>{{ __('Go to spare parts') }}</flux:button>
    @elseif ($batch->status === \App\Enums\ImportStatus::Cancelled)
        <flux:callout icon="x-circle">
            <flux:callout.heading>{{ __('Cancelled. Nothing was saved.') }}</flux:callout.heading>
        </flux:callout>
    @else
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Match the columns') }}</flux:heading>
            <flux:text>{{ __('Choose the column of the file that holds each field.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($this->columns as $column)
                    <flux:select
                        wire:model="columnMap.{{ $column['value'] }}"
                        :label="__($column['label'])"
                        :description="$column['required'] ? __('Required on every row.') : ($column['permitted'] ? null : __('You may not import this field. Leave its cells blank.'))"
                    >
                        <flux:select.option value="">{{ __('Not in the file') }}</flux:select.option>
                        @foreach ($batch->headers as $index => $header)
                            <flux:select.option :value="(string) $index">{{ $header }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endforeach
            </div>

            <flux:error name="columnMap" />

            <div class="flex flex-wrap gap-4">
                <flux:button variant="primary" wire:click="check">
                    {{ $batch->status === \App\Enums\ImportStatus::Pending ? __('Check the file') : __('Check again') }}
                </flux:button>
                <flux:button wire:click="cancel" wire:confirm="{{ __('Cancel this import? Nothing will be saved.') }}">{{ __('Cancel import') }}</flux:button>
            </div>
        </flux:card>

        @if ($batch->status !== \App\Enums\ImportStatus::Pending)
            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('Check result') }}</flux:heading>

                <ul class="space-y-1">
                    <li><flux:text>{{ __('Rows read: :count', ['count' => $batch->rows_total]) }}</flux:text></li>
                    <li><flux:text>{{ __('Spare parts to create: :count', ['count' => $batch->rows_created]) }}</flux:text></li>
                    <li><flux:text>{{ __('Spare parts to update: :count', ['count' => $batch->rows_updated]) }}</flux:text></li>
                    <li><flux:text>{{ __('Opening balances to record: :count', ['count' => $batch->opening_balances]) }}</flux:text></li>
                    @if ($batch->rows_failed > 0)
                        <li><flux:text>{{ __('Rows with errors: :count', ['count' => $batch->rows_failed]) }}</flux:text></li>
                    @endif
                </ul>

                @if ($batch->errors !== [])
                    <flux:callout variant="danger" icon="x-circle">
                        <flux:callout.heading>{{ __('Nothing can be imported while the file has errors.') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Correct the file and upload it again, or change the column matching and check again.') }}</flux:callout.text>
                    </flux:callout>

                    <ul class="space-y-1">
                        @foreach (array_slice($batch->errors, 0, 200) as $error)
                            <li>
                                <flux:text>
                                    {{ $error['row'] === 0
                                        ? __('File: :message', ['message' => $error['message']])
                                        : __('Row :row: :message', ['row' => $error['row'], 'message' => $error['message']]) }}
                                </flux:text>
                            </li>
                        @endforeach
                    </ul>

                    @if (count($batch->errors) > 200)
                        <flux:text>{{ __('Showing the first 200 of :count errors.', ['count' => count($batch->errors)]) }}</flux:text>
                    @endif
                @else
                    @if ($batch->warnings !== [])
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.heading>{{ __('Importing confirms these warnings.') }}</flux:callout.heading>
                            @foreach ($batch->warnings as $warning)
                                <flux:callout.text>{{ __('Row :row: :message', ['row' => $warning['row'], 'message' => $warning['message']]) }}</flux:callout.text>
                            @endforeach
                        </flux:callout>
                    @endif

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Row') }}</flux:table.column>
                            <flux:table.column>{{ __('Part number') }}</flux:table.column>
                            <flux:table.column>{{ __('Action') }}</flux:table.column>
                            <flux:table.column>{{ __('Opening quantity') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($batch->preview as $row)
                                <flux:table.row>
                                    <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['part_number'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['action'] === 'create' ? __('Create') : __('Update') }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['opening_quantity'] ?? '' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @if (count($batch->preview) < $batch->rows_created + $batch->rows_updated)
                        <flux:text>{{ __('Showing the first :count rows.', ['count' => count($batch->preview)]) }}</flux:text>
                    @endif

                    <flux:button
                        variant="primary"
                        wire:click="import"
                        wire:confirm="{{ __('Import :count rows? Spare parts are created or updated and opening balances are recorded, all at once.', ['count' => $batch->rows_total]) }}"
                    >
                        {{ __('Import') }}
                    </flux:button>
                @endif
            </flux:card>
        @endif
    @endif
</section>
