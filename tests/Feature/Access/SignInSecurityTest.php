<?php

use App\Auth\LoginLockout;
use App\Models\AuditLog;
use App\Models\User;

it('refuses sign-in to a deactivated account', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('ends the session of an account deactivated while signed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('locks sign-in for 15 minutes after five failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // Locked: even the right password is refused.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(LoginLockout::LOCK_SECONDS + 1)->seconds();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('counts only failures, and a successful sign-in clears them', function () {
    $user = User::factory()->create();
    $fail = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    foreach (range(1, 4) as $attempt) {
        $fail();
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    foreach (range(1, 4) as $attempt) {
        $fail();
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('audits sign-ins, failed sign-ins and lockouts against the account', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->travel(LoginLockout::LOCK_SECONDS + 1)->seconds();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(AuditLog::query()
        ->where('auditable_type', 'user')
        ->where('auditable_id', (string) $user->id)
        ->orderBy('id')
        ->pluck('event')
        ->all()
    )->toBe([...array_fill(0, 5, 'sign_in_failed'), 'locked_out', 'signed_in']);
});

it('records nothing for a failed sign-in to an email with no account', function () {
    $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'wrong-password']);

    expect(AuditLog::query()->exists())->toBeFalse();
});
