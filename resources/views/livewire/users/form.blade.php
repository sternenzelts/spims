<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $userId === null ? __('New user') : __('Edit user') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:input wire:model="email" :label="__('Email')" type="email" required />

        <flux:input
            wire:model="password"
            :label="$userId === null ? __('Password') : __('New password (leave blank to keep the current one)')"
            type="password"
            autocomplete="new-password"
            viewable
        />
        <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" autocomplete="new-password" viewable />

        <flux:checkbox.group wire:model="roles" :label="__('Roles')">
            @foreach (\App\Enums\Role::cases() as $role)
                <flux:checkbox :value="$role->value" :label="$role->label()" />
            @endforeach
        </flux:checkbox.group>

        @if ($userId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" />
            <flux:error name="is_active" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('users.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
