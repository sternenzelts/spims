<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Withdrawal station') }}</flux:heading>

        <flux:button icon="magnifying-glass" :href="route('station.part-lookup')" wire:navigate>
            {{ __('Part lookup') }}
        </flux:button>
    </div>

    <flux:error name="draft" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input
            wire:model="partEntry"
            wire:keydown.enter.prevent="enterPart"
            :label="__('Part number')"
            :placeholder="__('Scan or type, then press Enter')"
            class="max-w-sm"
            autofocus
        />
        <flux:input wire:model="quantity" :label="__('Quantity')" class="max-w-32" inputmode="decimal" />
        <flux:button wire:click="enterPart">{{ __('Add') }}</flux:button>
    </div>
    <flux:error name="partEntry" />

    @if ($this->matches->isNotEmpty())
        <div class="mb-6 space-y-2">
            <flux:text>{{ __('Matches') }}</flux:text>
            @foreach ($this->matches as $match)
                <flux:button size="sm" wire:click="addPart({{ $match->id }})" wire:key="match-{{ $match->id }}">
                    {{ $match->part_number }} — {{ $match->description }}
                    ({{ \App\Support\Quantity::display($match->stock?->on_hand) }} {{ $match->unit_of_measure }})
                </flux:button>
            @endforeach
        </div>
    @endif

    <flux:table class="mb-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Resulting') }}</flux:table.column>
            <flux:table.column>{{ __('Warning') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->review->lines as $line)
                <flux:table.row wire:key="line-{{ $line->line->id }}">
                    <flux:table.cell>{{ $line->line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->line->part->description }}</flux:table.cell>
                    <flux:table.cell>{{ $line->line->part->defaultLocation?->code }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->onHand) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->line->quantity) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->resulting) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($line->needsOverride())
                            <flux:badge size="sm" color="red">{{ __('Not enough stock') }}</flux:badge>
                        @elseif ($line->override)
                            <flux:badge size="sm" color="amber">{{ __('Override authorised') }}</flux:badge>
                        @elseif ($line->level)
                            <flux:badge size="sm" color="amber">{{ $line->level->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($line->needsOverride())
                            <flux:button size="sm" wire:click="requestOverride({{ $line->line->part_id }})">
                                {{ __('Request override') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $line->line->id }})">
                            {{ __('Remove') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:select wire:model="equipmentId" :label="__('Equipment')" class="max-w-xs">
            <flux:select.option value="">{{ __('Choose equipment') }}</flux:select.option>
            @foreach ($this->equipmentOptions as $option)
                <flux:select.option :value="$option->id">{{ $option->code }} — {{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="purposeId" :label="__('Purpose')" class="max-w-xs">
            <flux:select.option value="">{{ __('Choose a purpose') }}</flux:select.option>
            @foreach ($this->purposeOptions as $option)
                <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="remarks" :label="__('Remarks')" class="max-w-sm" />
        <flux:button wire:click="saveContext">{{ __('Save') }}</flux:button>
    </div>
    <flux:error name="equipmentId" />

    <flux:separator class="my-6" />

    @if ($this->draft->personnel)
        <flux:callout icon="identification" class="mb-6">
            <flux:callout.heading>{{ $this->draft->personnel->full_name }}</flux:callout.heading>
            <flux:callout.text>
                {{ $this->draft->personnel->employee_no }}
                @if ($this->draft->personnel->department) · {{ $this->draft->personnel->department }} @endif
                · {{ $this->draft->personnel->badge_uid_masked }}
            </flux:callout.text>
        </flux:callout>

        <div class="mb-6">
            <flux:button size="sm" wire:click="clearIdentification">{{ __('Not this person') }}</flux:button>
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input
                wire:model="badge"
                wire:keydown.enter.prevent="scan"
                :label="__('Scan personnel ID')"
                :placeholder="__('Ask the person to scan their badge')"
                class="max-w-sm"
            />
            <flux:button wire:click="scan">{{ __('Scan') }}</flux:button>
            <flux:button variant="subtle" wire:click="requestManualIdentification">{{ __('No badge?') }}</flux:button>
        </div>
        <flux:error name="badge" />
    @endif

    <div class="flex flex-wrap gap-4">
        <flux:button variant="primary" wire:click="confirm">{{ __('Confirm withdrawal') }}</flux:button>
        <flux:button variant="danger" wire:click="cancelDraft">{{ __('Cancel') }}</flux:button>
    </div>

    <flux:modal name="supervisor-override" class="md:w-96">
        <form wire:submit="authoriseOverride" class="space-y-6">
            <flux:heading size="lg">{{ __('Supervisor override') }}</flux:heading>
            @if ($this->overridePart)
                <flux:text variant="strong">{{ $this->overridePart->part_number }} — {{ $this->overridePart->description }}</flux:text>
            @endif
            <flux:text>{{ __('Enter the quantity counted on the shelf. The adjustment is recorded when the withdrawal is confirmed.') }}</flux:text>

            <flux:input wire:model="verifiedQty" :label="__('Verified quantity on the shelf')" inputmode="decimal" />

            <flux:select wire:model="overrideReason" :label="__('Reason')">
                @foreach (\App\Models\WithdrawalOverride::REASONS as $reason)
                    <flux:select.option :value="$reason->value">{{ \Illuminate\Support\Str::headline($reason->value) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="supervisorEmail" type="email" :label="__('Supervisor email')" />
            <flux:input wire:model="supervisorPassword" type="password" :label="__('Supervisor password')" />

            <flux:button variant="primary" type="submit">{{ __('Authorise') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="manual-identification" class="md:w-96">
        <form wire:submit="identifyManually" class="space-y-6">
            <flux:heading size="lg">{{ __('Identify without a badge') }}</flux:heading>

            <flux:select wire:model="manualPersonnelId" :label="__('Person receiving the parts')">
                <flux:select.option value="">{{ __('Choose a person') }}</flux:select.option>
                @foreach ($this->personnelOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->full_name }} ({{ $option->employee_no }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="manualReason" :label="__('Reason the badge could not be scanned')" />
            <flux:input wire:model="supervisorEmail" type="email" :label="__('Supervisor email')" />
            <flux:input wire:model="supervisorPassword" type="password" :label="__('Supervisor password')" />

            <flux:button variant="primary" type="submit">{{ __('Identify') }}</flux:button>
        </form>
    </flux:modal>
</section>
