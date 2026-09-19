<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-install-notices />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
