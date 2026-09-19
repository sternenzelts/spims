<?php

use App\Enums\Role;
use App\Livewire\MasterData\StorageLocations\Form;
use App\Livewire\MasterData\StorageLocations\Index;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\StorageLocation;
use Livewire\Livewire;

it('lets supervisors, planners and administrators manage storage locations', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $location = StorageLocation::factory()->create();

    $this->actingAs($user)->get(route('storage-locations.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('storage-locations.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('storage-locations.edit', $location))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Storage locations', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-SPA'));

it('creates a location with its code trimmed and uppercased, audited', function () {
    $supervisor = userWithRoles(Role::Supervisor);

    Livewire::actingAs($supervisor)->test(Form::class)
        ->set('code', ' a-01 ')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('storage-locations.index'));

    $location = StorageLocation::query()->sole();

    expect($location->code)->toBe('A-01')
        ->and(AuditLog::query()->where('auditable_type', 'storage_location')->where('event', 'created')->sole())
        ->user_id->toBe($supervisor->id)
        ->new_values->toBe(['code' => 'A-01', 'name' => 'Aisle A, bin 1', 'is_active' => true, 'description' => null]);
});

it('refuses a code already in use, in any letter case', function () {
    StorageLocation::factory()->create(['code' => 'A-01']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('code', 'a-01')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors('code');
});

it('refuses to deactivate a location that holds Active spare parts, and names them', function () {
    $location = StorageLocation::factory()->create(['code' => 'A-01']);
    Part::factory()->create(['part_number' => 'BRG-6204', 'default_location_id' => $location->id]);
    Part::factory()->create(['part_number' => 'OLD-0001', 'default_location_id' => $location->id, 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['location' => $location])
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active')
        ->assertSee('BRG-6204')
        ->assertDontSee('OLD-0001');

    expect($location->refresh()->is_active)->toBeTrue();
});

it('deactivates a location once no Active part is kept there, recording old and new values', function () {
    $location = StorageLocation::factory()->create();
    Part::factory()->create(['default_location_id' => $location->id, 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['location' => $location])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($location->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', 'storage_location')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('lists Active locations by default and finds them by code or name', function () {
    StorageLocation::factory()->create(['code' => 'A-01', 'name' => 'Aisle A bin 1']);
    StorageLocation::factory()->create(['code' => 'B-02', 'name' => 'Bearings cabinet']);
    StorageLocation::factory()->create(['code' => 'Z-99', 'name' => 'Old rack', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Index::class)
        ->assertSee('A-01')
        ->assertSee('B-02')
        ->assertDontSee('Z-99')
        ->set('search', 'bearings')
        ->assertSee('B-02')
        ->assertDontSee('A-01')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('Z-99')
        ->assertDontSee('A-01');
});
