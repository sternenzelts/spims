@php($transaction = $this->transaction)

<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $transaction->transaction_no }}</flux:heading>
        <flux:button :href="route('station.withdrawal')" wire:navigate>{{ __('New withdrawal') }}</flux:button>
    </div>

    <dl class="mb-6 grid grid-cols-2 gap-4">
        <div>
            <flux:text size="sm">{{ __('Date and time') }}</flux:text>
            <flux:text>{{ $transaction->recorded_at?->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone()) }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Custodian') }}</flux:text>
            <flux:text>{{ $transaction->custodian->name }}</flux:text>
        </div>
        @if ($transaction->personnel)
            <div>
                <flux:text size="sm">{{ __('Received by') }}</flux:text>
                <flux:text>{{ $transaction->personnel->full_name }} ({{ $transaction->personnel->employee_no }})</flux:text>
            </div>
            {{-- FR-SPIMS-007 step 3 / the Done-When walk (checklist 14): the receipt of a manual
                 identification must say so, name the authorising supervisor and the reason — and
                 never print a badge line, since no badge was scanned. --}}
            @if ($transaction->identification_method === \App\Enums\IdentificationMethod::BadgeScan)
                <div>
                    <flux:text size="sm">{{ __('Badge') }}</flux:text>
                    <flux:text>{{ $transaction->personnel->badge_uid_masked }}</flux:text>
                </div>
            @elseif ($transaction->identification_method === \App\Enums\IdentificationMethod::SupervisorManual)
                <div class="col-span-2">
                    <flux:text size="sm">{{ __('Identification') }}</flux:text>
                    <flux:text>
                        {{ __('Identified manually by :name, reason: :reason', [
                            'name' => $transaction->identificationAuthorisedBy?->name,
                            'reason' => $transaction->identification_reason,
                        ]) }}
                    </flux:text>
                </div>
            @endif
        @endif
        <div>
            <flux:text size="sm">{{ __('Equipment') }}</flux:text>
            <flux:text>
                @if ($transaction->equipment)
                    {{ $transaction->equipment->code }} — {{ $transaction->equipment->name }}
                @endif
            </flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Purpose') }}</flux:text>
            <flux:text>{{ $transaction->purpose?->name }}</flux:text>
        </div>
        @if ($transaction->remarks)
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Remarks') }}</flux:text>
                <flux:text>{{ $transaction->remarks }}</flux:text>
            </div>
        @endif
    </dl>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Remaining balance') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($transaction->lines as $line)
                <flux:table.row wire:key="receipt-line-{{ $line->id }}">
                    <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($line->quantity) }} {{ $line->part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($transaction->movements->firstWhere('stock_transaction_line_id', $line->id)?->balance_after) }} {{ $line->part->unit_of_measure }}
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
