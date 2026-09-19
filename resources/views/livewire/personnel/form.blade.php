<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $personnelId === null ? __('Register personnel') : __('Edit personnel') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="employee_no" :label="__('Employee number')" required />
        <flux:input wire:model="full_name" :label="__('Full name')" required />
        <flux:input wire:model="department" :label="__('Department')" />

        <div class="flex items-center gap-4">
            @if ($this->photoUrl !== null)
                <img src="{{ $this->photoUrl }}" alt="{{ __('Photo of :name', ['name' => $full_name]) }}" class="size-20 rounded object-cover" />
            @endif
            <flux:input type="file" wire:model="photo" :label="__('Photo')" accept="image/jpeg,image/png,image/webp" />
        </div>

        @if ($personnelId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('An Inactive person\'s badge no longer confirms withdrawals. Their history is kept.')" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('personnel.index')" wire:navigate>{{ __('Back to personnel') }}</flux:button>
        </div>
    </form>

    @if ($personnelId !== null)
        <flux:card class="mt-10 space-y-4">
            <flux:heading size="lg">{{ __('Badge') }}</flux:heading>

            <flux:text>
                {{ $this->badgeMasked === null ? __('No badge enrolled.') : __('Enrolled badge: :badge', ['badge' => $this->badgeMasked]) }}
            </flux:text>

            @if ($revealedBadge !== null)
                <flux:text>{{ __('Full badge identifier: :badge', ['badge' => $revealedBadge]) }}</flux:text>
            @elseif ($this->badgeMasked !== null)
                @can(\App\Enums\Permission::BadgesViewFull)
                    <flux:button size="sm" wire:click="revealBadge">{{ __('Show full badge') }}</flux:button>
                @endcan
            @endif

            <flux:input
                wire:model="badgeScan"
                wire:keydown.enter.prevent="enrollBadge"
                :label="$this->badgeMasked === null ? __('Scan the badge to enroll it') : __('Scan a new badge to replace it')"
                autocomplete="off"
            />
            <flux:button wire:click="enrollBadge">{{ __('Enroll badge') }}</flux:button>
        </flux:card>
    @endif
</section>
