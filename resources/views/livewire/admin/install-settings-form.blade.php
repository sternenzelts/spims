<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Install settings') }}</flux:heading>
        <flux:badge :color="\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo ? 'yellow' : 'zinc'">
            {{ __(\App\Enums\InstallType::current()->label()) }}
        </flux:badge>
    </div>

    <flux:card class="mb-8">
        <flux:heading size="lg">{{ __('Setup checklist') }}</flux:heading>

        <ul class="mt-4 space-y-2">
            @foreach ($this->checklist as $item)
                <li class="flex items-center gap-2">
                    <flux:icon :name="$item['done'] ? 'check-circle' : 'minus-circle'" variant="mini" />
                    <flux:text>{{ __($item['label']) }}</flux:text>
                    <span class="sr-only">{{ $item['done'] ? __('done') : __('to do') }}</span>
                </li>
            @endforeach
        </ul>
    </flux:card>

    <form wire:submit="save" class="space-y-10">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Company profile') }}</flux:heading>
            <flux:input wire:model="settings.company.name" :label="__('Company name')" :description="__('Required before stock can be recorded.')" />
            <flux:textarea wire:model="settings.company.address" :label="__('Address')" rows="3" />
            <flux:input type="file" wire:model="logo" :label="__('Logo')" accept="image/png,image/jpeg,image/webp" />
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Regional') }}</flux:heading>

            <flux:select wire:model.live="settings.regional.timezone" :label="__('Time zone')">
                @foreach (\DateTimeZone::listIdentifiers() as $zone)
                    <flux:select.option :value="$zone">{{ $zone }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->needsRegroupingConfirmation)
                <flux:checkbox wire:model="confirmRegrouping" :label="__('I understand that daily, weekly and monthly figures will be regrouped in the new time zone.')" />
                <flux:error name="confirmRegrouping" />
            @endif

            <flux:input wire:model.live.debounce.500ms="settings.regional.currency" :label="__('Currency for unit costs')" :description="__('Three-letter code, such as PHP.')" maxlength="3" />

            @if ($this->needsCurrencyConfirmation)
                <flux:checkbox wire:model="confirmCurrency" :label="__('I understand that unit costs already recorded are not converted.')" />
                <flux:error name="confirmCurrency" />
            @endif
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Transaction numbering') }}</flux:heading>
            <flux:text>{{ __('Letters only. New transactions use a new prefix; existing numbers keep theirs.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Livewire\Admin\InstallSettingsForm::PREFIXES as $type => $label)
                    <flux:input wire:model="settings.numbering.prefixes.{{ $type }}" :label="__($label)" maxlength="10" />
                @endforeach
            </div>
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Thresholds') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Livewire\Admin\InstallSettingsForm::THRESHOLDS as $key => $label)
                    <flux:input wire:model="settings.{{ $key }}" :label="__($label)" type="number" step="any" min="0" />
                @endforeach
            </div>
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Notifications') }}</flux:heading>
            <flux:textarea wire:model="recipients" :label="__('Daily alert and exceptions summary recipients')" :description="__('One email address per line.')" rows="4" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save settings') }}</flux:button>
    </form>
</section>
