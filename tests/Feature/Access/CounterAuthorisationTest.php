<?php

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->counter = app(CounterAuthorisation::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita.ilagan@microtech.test', 'password' => Hash::make('counter-secret')])->save();
});

afterEach(function () {
    RateLimiter::clear('counter-authorisation:'.Permission::OverridesAuthorise->value.'|rita.ilagan@microtech.test|10.0.0.5');
    RateLimiter::clear('counter-authorisation:'.Permission::OverridesAuthorise->value.'|rita.ilagan@microtech.test|10.0.0.9');
});

it('identifies the supervisor standing at the counter', function () {
    $user = $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect($user->is($this->supervisor))->toBeTrue();
});

it('never signs the supervisor in', function () {
    $this->actingAs($custodian = userWithRoles(Role::Custodian));

    $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect(auth()->user()?->is($custodian))->toBeTrue();
});

it('matches the email whatever case it was saved in', function () {
    $this->supervisor->forceFill(['email' => 'Rita.Ilagan@Microtech.test'])->save();

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('refuses the wrong password, an unknown email, a deactivated account and a role without the permission', function (Closure $attempt, string $expected) {
    expect(fn () => $attempt($this->counter, $this->supervisor))->toThrow(AuthorisationRefused::class, $expected);
})->with([
    'wrong password' => [
        fn (CounterAuthorisation $c) => $c->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'),
        'do not match',
    ],
    'unknown email' => [
        fn (CounterAuthorisation $c) => $c->verify('nobody@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'),
        'do not match',
    ],
    'deactivated account' => [
        function (CounterAuthorisation $c, User $supervisor) {
            $supervisor->forceFill(['is_active' => false])->save();

            return $c->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');
        },
        'no longer Active',
    ],
    'role without the permission' => [
        function (CounterAuthorisation $c, User $supervisor) {
            $supervisor->syncRoles([Role::Custodian]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $c->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');
        },
        'not allowed to authorise',
    ],
]);

it('locks the counter after repeated failures and releases it later', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');

    $this->travel(CounterAuthorisation::DECAY_SECONDS + 1)->seconds();

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('forgets the failures once the supervisor gets it right', function () {
    rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS - 1; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('counts each station separately', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.9')->is($this->supervisor))
        ->toBeTrue();
});
