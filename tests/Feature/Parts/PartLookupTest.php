<?php

use App\Enums\Role;
use App\Livewire\Station\PartLookup;
use App\Models\StorageLocation;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->part = partWithStock('7.5', [
        'part_number' => 'BRG-6204',
        'description' => 'Deep groove ball bearing',
        'unit_of_measure' => 'pc',
    ]);
});

it('opens the lookup for every role that can see spare parts', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.part-lookup'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CSPA'));

it('shows the description, the location and what is on hand', function () {
    $this->part->defaultLocation()->associate(StorageLocation::factory()->create(['code' => 'A-01-3']))->save();

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(PartLookup::class)
        ->set('search', 'ball bearing')
        ->assertSee('BRG-6204')
        ->assertSee('Deep groove ball bearing')
        ->assertSee('A-01-3')
        ->assertSee('7.5');
});

it('shows nothing until something is typed', function () {
    Livewire::actingAs(userWithRoles(Role::Custodian))->test(PartLookup::class)
        ->assertDontSee('BRG-6204');
});
