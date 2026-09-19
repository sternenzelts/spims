{{-- PRD FR-SPIMS-019: a demo install shows a Demo label on every screen. --}}
@if (\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo)
    <flux:callout variant="warning" icon="beaker" class="mb-6" data-test="demo-label">
        <flux:callout.heading>{{ __('Demo install') }}</flux:callout.heading>
        <flux:callout.text>{{ __('Sample data for demonstrations. Nothing here is real stock.') }}</flux:callout.text>
    </flux:callout>
@endif
