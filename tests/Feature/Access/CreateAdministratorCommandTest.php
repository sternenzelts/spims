<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified System Administrator and audits it', function () {
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->expectsOutputToContain('System Administrator it.admin@microtech.test created.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'it.admin@microtech.test')->sole();

    expect($user->hasRole(Role::Admin))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', (string) $user->id)->sole()->event)
        ->toBe('created');
});

it('refuses an email address that is already taken', function () {
    User::factory()->create(['email' => 'it.admin@microtech.test']);

    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->where('email', 'it.admin@microtech.test')->count())->toBe(1);
});

it('stores the email in lowercase, because sign-in lowercases what is typed', function () {
    $this->artisan('spims:create-admin', ['email' => 'IT.Admin@Microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->assertSuccessful();

    expect(User::query()->sole()->email)->toBe('it.admin@microtech.test');

    $this->post(route('login.store'), ['email' => 'IT.Admin@Microtech.test', 'password' => 'correct-horse-battery-staple']);

    $this->assertAuthenticated();
});

it('refuses an email address that differs from a taken one only in case', function () {
    User::factory()->create(['email' => 'it.admin@microtech.test']);

    $this->artisan('spims:create-admin', ['email' => 'IT.Admin@Microtech.test', 'name' => 'IT Administrator'])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
