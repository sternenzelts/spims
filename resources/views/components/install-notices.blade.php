{{-- PRD FR-SPIMS-019: a demo install shows a Demo label on every screen. --}}
@if (\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo)
    <flux:callout variant="warning" icon="beaker" class="mb-6" data-test="demo-label">
        <flux:callout.heading>{{ __('Demo install') }}</flux:callout.heading>
        <flux:callout.text>{{ __('Sample data for demonstrations. Nothing here is real stock.') }}</flux:callout.text>
    </flux:callout>
@endif

{{-- PRD FR-SPIMS-018: until the company name is set, System Administrators are prompted to set it. --}}
@auth
    @if (auth()->user()->can(\App\Enums\Permission::SettingsManage) && app(\App\Domain\Settings\InstallSettings::class)->companyName() === null)
        <flux:callout icon="cog-6-tooth" class="mb-6" data-test="setup-prompt">
            <flux:callout.heading>{{ __('Finish setting up this install') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Stock cannot be recorded until the company name is set.') }}</flux:callout.text>

            <x-slot name="actions">
                <flux:button size="sm" :href="route('install-settings.edit')" wire:navigate>{{ __('Open install settings') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif
@endauth
