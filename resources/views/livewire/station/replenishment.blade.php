<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Record a delivery') }}</flux:heading>

    <flux:error name="draft" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input wire:model="supplier" :label="__('Supplier')" class="max-w-sm" autofocus />
        <flux:input wire:model="referenceNo" :label="__('DR or PO reference')" class="max-w-xs" />
        <flux:input wire:model="receivedOn" type="date" :label="__('Date received')" class="max-w-xs" />
        <flux:button wire:click="saveDelivery">{{ __('Save delivery') }}</flux:button>
    </div>
    <flux:error name="receivedOn" />

    @if ($this->duplicate)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.heading>{{ __('This reference is already recorded for this supplier.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Recorded as :number. Confirm only if this really is a second delivery.', ['number' => $this->duplicate->transaction_no]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->overMaximum !== [])
        <flux:callout variant="warning" icon="arrow-trending-up" class="mb-6">
            <flux:callout.heading>{{ __('This delivery takes spare parts above their maximum level.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ implode(', ', array_map(fn ($part) => $part->part_number, $this->overMaximum)) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input
            wire:model="partEntry"
            wire:keydown.enter.prevent="enterPart"
            :label="__('Part number')"
            :placeholder="__('Scan or type, then press Enter')"
            class="max-w-sm"
        />
        <flux:input wire:model="quantity" :label="__('Quantity received')" class="max-w-32" inputmode="decimal" />
        <flux:input wire:model="unitCost" :label="__('Unit cost')" class="max-w-32" inputmode="decimal" />
        <flux:button wire:click="enterPart">{{ __('Add') }}</flux:button>
        <flux:button variant="subtle" :href="route('parts.create')" wire:navigate>{{ __('New spare part') }}</flux:button>
    </div>
    <flux:error name="partEntry" />

    @if ($this->matches->isNotEmpty())
        <div class="mb-6 space-y-2">
            @foreach ($this->matches as $match)
                <flux:button size="sm" wire:click="addPart({{ $match->id }})" wire:key="rp-match-{{ $match->id }}">
                    {{ $match->part_number }} — {{ $match->description }}
                </flux:button>
            @endforeach
        </div>
    @endif

    <flux:table class="mb-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Unit cost') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->draft->lines()->with('part')->orderBy('line_no')->get() as $line)
                <flux:table.row wire:key="rp-line-{{ $line->id }}">
                    <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($line->quantity) }} {{ $line->part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $line->unit_cost }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $line->id }})">
                            {{ __('Remove') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="flex flex-wrap gap-4">
        <flux:button variant="primary" wire:click="confirm">{{ __('Confirm delivery') }}</flux:button>
        <flux:button variant="danger" wire:click="cancelDraft">{{ __('Cancel') }}</flux:button>
    </div>
</section>
