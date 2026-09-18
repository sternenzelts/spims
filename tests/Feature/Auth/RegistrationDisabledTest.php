<?php

use Illuminate\Support\Facades\Route;

it('does not offer self-registration: System Administrators create accounts', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
});

it('still shows the sign-in page, without a sign-up link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up');
});
