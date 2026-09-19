@props([
    'sidebar' => false,
])

@php
    $settings = app(\App\Domain\Settings\InstallSettings::class);
    $companyName = $settings->companyName() ?? config('app.name', 'SPIMS');
    $logoPath = $settings->get('company.logo_path');
    $logoUrl = is_string($logoPath) ? route('media.show', ['path' => $logoPath]) : null;
@endphp

@if ($logoUrl !== null)
    @if ($sidebar)
        <flux:sidebar.brand :name="$companyName" :logo="$logoUrl" {{ $attributes }} />
    @else
        <flux:brand :name="$companyName" :logo="$logoUrl" {{ $attributes }} />
    @endif
@elseif ($sidebar)
    <flux:sidebar.brand :name="$companyName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$companyName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
