<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use Livewire\Livewire;

it('stores a changed email lowercased, so the account can still sign in', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Profile::class)
        ->set('email', '  New.Address@Example.TEST ')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('new.address@example.test');
});

it('refuses an email another account holds in any letter case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::actingAs(User::factory()->create())->test(Profile::class)
        ->set('email', 'Taken@Example.test')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => 'unique']);
});

it('offers no way to delete your own account', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');
});
