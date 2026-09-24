<?php

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->counter = app(CounterAuthorisation::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita.ilagan@microtech.test', 'password' => Hash::make('counter-secret')])->save();
});

afterEach(function () {
    RateLimiter::clear('counter-authorisation:rita.ilagan@microtech.test|10.0.0.5');
    RateLimiter::clear('counter-authorisation:rita.ilagan@microtech.test|10.0.0.9');
});

it('identifies the supervisor standing at the counter', function () {
    $user = $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect($user->is($this->supervisor))->toBeTrue();
});

it('lets only an Inventory Supervisor authorise at the counter', function (Role $role, bool $allowed, Permission $permission) {
    $user = userWithRoles($role);
    $user->forceFill(['email' => 'counter.user@microtech.test', 'password' => Hash::make('counter-secret')])->save();
    $verify = fn () => $this->counter->verify('counter.user@microtech.test', 'counter-secret', $permission, '10.0.0.7');

    $allowed
        ? expect($verify()->is($user))->toBeTrue()
        : expect($verify)->toThrow(AuthorisationRefused::class, 'not allowed to authorise');
})->with(roleMatrix('S'))->with([
    'an override' => Permission::OverridesAuthorise,
    'a manual identification' => Permission::IdentificationAuthorise,
]);

it('never signs the supervisor in', function () {
    $this->actingAs($custodian = userWithRoles(Role::Custodian));
    $session = session()->all();
    $rememberToken = $this->supervisor->remember_token;
    Event::fake([Attempting::class, Authenticated::class, Login::class]);

    $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect(auth()->user()?->is($custodian))->toBeTrue()
        ->and(session()->all())->toBe($session)
        ->and($this->supervisor->fresh()->remember_token)->toBe($rememberToken);
    Event::assertNotDispatched(Attempting::class);
    Event::assertNotDispatched(Authenticated::class);
    Event::assertNotDispatched(Login::class);
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
    // The same answer as a wrong password: the counter never confirms a former employee's password.
    'deactivated account' => [
        function (CounterAuthorisation $c, User $supervisor) {
            $supervisor->forceFill(['is_active' => false])->save();

            return $c->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');
        },
        'do not match',
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

it('locks the counter after repeated failures for fifteen minutes, then releases it', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');

    $this->travel(CounterAuthorisation::DECAY_SECONDS - 1)->seconds();

    expect(CounterAuthorisation::DECAY_SECONDS)->toBe(900)
        ->and(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');

    $this->travel(2)->seconds();

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('shares one budget of five attempts across overrides and manual identifications', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect(CounterAuthorisation::MAX_ATTEMPTS)->toBe(5)
        ->and(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::IdentificationAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');
});

it('charges an attempt for a right password on an account that cannot authorise', function (Closure $disable, Closure $restore) {
    for ($attempt = 1; $attempt < CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    $disable($this->supervisor);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'));
    $restore($this->supervisor);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // That was the fifth attempt, so even the supervisor's own, now valid, account waits.
    expect(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');
})->with([
    'a deactivated account' => [
        fn (User $supervisor) => $supervisor->forceFill(['is_active' => false])->save(),
        fn (User $supervisor) => $supervisor->forceFill(['is_active' => true])->save(),
    ],
    'a role without the permission' => [
        fn (User $supervisor) => $supervisor->syncRoles([Role::Custodian]),
        fn (User $supervisor) => $supervisor->syncRoles([Role::Supervisor]),
    ],
]);

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
