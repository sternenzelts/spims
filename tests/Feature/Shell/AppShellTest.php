<?php

use App\Enums\InstallType;
use App\Enums\Role;
use App\Models\User;

it('reads the install type from the environment', function () {
    config(['spims.install_type' => 'demo']);
    expect(InstallType::current())->toBe(InstallType::Demo);

    config(['spims.install_type' => 'production']);
    expect(InstallType::current())->toBe(InstallType::Production);
});

it('refuses an install type it does not know', function () {
    config(['spims.install_type' => 'staging']);

    expect(fn () => InstallType::current())
        ->toThrow(UnexpectedValueException::class, 'APP_INSTALL_TYPE must be demo or production, got [staging].');
});

it('labels every screen of a demo install, including sign-in', function () {
    config(['spims.install_type' => 'demo']);

    $this->get(route('login'))->assertSee('Demo install');
    $this->actingAs(userWithRoles(Role::Custodian))->get(route('dashboard'))->assertSee('Demo install');
});

it('shows no demo label on a production install', function () {
    $this->get(route('login'))->assertDontSee('Demo install');
    $this->actingAs(userWithRoles(Role::Custodian))->get(route('dashboard'))->assertDontSee('Demo install');
});

it('shows the company name from install settings', function () {
    configureInstall();

    $this->actingAs(userWithRoles(Role::Custodian))
        ->get(route('dashboard'))
        ->assertSee('Microtech Industrial Solutions, Inc.');
});

it('sends the home page to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('drops the starter kit links', function () {
    $this->actingAs(userWithRoles(Role::Custodian))
        ->get(route('dashboard'))
        ->assertDontSee('livewire-starter-kit');
});

it('shows each user only the menu items they may open', function () {
    expect(menuLabels(User::factory()->create()))->toBe([])
        ->and(menuLabels(userWithRoles(Role::Custodian)))
        ->toBe(['Dashboard', 'Withdrawal station', 'Record a delivery', 'Part lookup', 'Spare parts'])
        ->and(menuLabels(userWithRoles(Role::Planner)))
        ->not->toContain('Withdrawal station');
});
