@php($part = $this->part)

<section class="w-full max-w-4xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $part->part_number }}</flux:heading>
            <flux:subheading>{{ $part->description }}</flux:subheading>
            <div class="mt-2 flex gap-2">
                <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">{{ $part->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                <flux:badge size="sm">{{ __(ucfirst($part->criticality->value)) }}</flux:badge>
            </div>
        </div>

        <div class="flex gap-2" data-part-actions>
            @can('update', $part)
                <flux:button icon="pencil-square" :href="route('parts.edit', $part)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endcan
            @can('manageStockLevels', $part)
                <flux:button icon="adjustments-horizontal" :href="route('parts.stock-levels', $part)" wire:navigate>{{ __('Stock levels') }}</flux:button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="lg">{{ __('Stock') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-2 gap-y-2">
                <dt><flux:text>{{ __('On hand') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}</dd>
                <dt><flux:text>{{ __('Unit of measure') }}</flux:text></dt>
                <dd>{{ $part->unit_of_measure }}</dd>
                <dt><flux:text>{{ __('Storage location') }}</flux:text></dt>
                <dd>{{ $part->defaultLocation ? $part->defaultLocation->code.' — '.$part->defaultLocation->name : __('Not assigned') }}</dd>
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Stock levels') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-2 gap-y-2">
                <dt><flux:text>{{ __('Reorder point') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->reorder_point) }}</dd>
                <dt><flux:text>{{ __('Minimum level') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->min_level) }}</dd>
                <dt><flux:text>{{ __('Critical level') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->critical_level) }}</dd>
                <dt><flux:text>{{ __('Maximum level') }}</flux:text></dt>
                <dd>{{ $part->max_level === null ? __('Not set') : \App\Support\Quantity::display($part->max_level) }}</dd>
                <dt><flux:text>{{ __('Lead time') }}</flux:text></dt>
                <dd>{{ $part->lead_time_days === null ? __('Not set') : trans_choice(':count day|:count days', $part->lead_time_days) }}</dd>
            </dl>
        </flux:card>
    </div>

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('Used on equipment') }}</flux:heading>
        <ul class="mt-4 space-y-1">
            @forelse ($part->equipment as $machine)
                <li>{{ $machine->code }} <flux:text class="inline">{{ $machine->name }}</flux:text></li>
            @empty
                <li><flux:text>{{ __('No equipment linked yet.') }}</flux:text></li>
            @endforelse
        </ul>
    </flux:card>

    @can(\App\Enums\Permission::PartAuditView)
        <flux:card class="mt-6">
            <flux:heading size="lg">{{ __('Change history') }}</flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('Who') }}</flux:table.column>
                    <flux:table.column>{{ __('Change') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->history as $entry)
                        <flux:table.row :key="$entry->id">
                            <flux:table.cell>
                                {{ $entry->created_at->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i') }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $entry->user?->name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div>{{ __(ucfirst(str_replace('_', ' ', $entry->event))) }}</div>
                                @foreach ($entry->new_values ?? [] as $field => $value)
                                    <div>
                                        <flux:text class="inline">{{ __(str_replace('_', ' ', $field)) }}:</flux:text>
                                        {{ \App\Support\AuditValue::display($entry->old_values[$field] ?? null) }}
                                        → {{ \App\Support\AuditValue::display($value) }}
                                    </div>
                                @endforeach
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endcan
</section>
