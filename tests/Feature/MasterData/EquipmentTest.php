<?php

use App\Enums\Role;
use App\Livewire\MasterData\Equipment\Form;
use App\Livewire\MasterData\Equipment\Index;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use Livewire\Livewire;

it('lets supervisors, planners and administrators manage equipment', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $equipment = Equipment::factory()->create();

    $this->actingAs($user)->get(route('equipment.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('equipment.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('equipment.edit', $equipment))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Equipment', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-SPA'));

it('registers equipment with its code trimmed and uppercased, audited', function () {
    $planner = userWithRoles(Role::Planner);

    Livewire::actingAs($planner)->test(Form::class)
        ->set('code', ' cnc-01 ')
        ->set('name', 'CNC lathe 1')
        ->set('area', 'Machine shop')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('equipment.index'));

    $equipment = Equipment::query()->sole();

    expect($equipment->code)->toBe('CNC-01')
        ->and(AuditLog::query()->where('auditable_type', 'equipment')->where('event', 'created')->sole())
        ->user_id->toBe($planner->id)
        ->new_values->toBe(['area' => 'Machine shop', 'code' => 'CNC-01', 'name' => 'CNC lathe 1', 'is_active' => true]);
});

it('refuses a code already in use, in any letter case', function () {
    Equipment::factory()->create(['code' => 'CNC-01']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class)
        ->set('code', 'cnc-01')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors('code');
});

it('deactivates equipment that has spare parts linked, keeping the links', function () {
    $equipment = Equipment::factory()->create();
    $part = Part::factory()->create();
    $part->equipment()->attach($equipment->id);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['equipment' => $equipment])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($equipment->refresh()->is_active)->toBeFalse()
        ->and($equipment->parts()->pluck('parts.id')->all())->toBe([$part->id])
        ->and(AuditLog::query()->where('auditable_type', 'equipment')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('shows the spare parts linked to the equipment', function () {
    $equipment = Equipment::factory()->create();
    Part::factory()->create(['part_number' => 'BELT-A42'])->equipment()->attach($equipment->id);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['equipment' => $equipment])
        ->assertSee('BELT-A42');
});

it('lists Active equipment by default and finds it by code, name or area', function () {
    Equipment::factory()->create(['code' => 'CNC-01', 'name' => 'CNC lathe 1', 'area' => 'Machine shop']);
    Equipment::factory()->create(['code' => 'BLR-01', 'name' => 'Boiler 1', 'area' => 'Utilities']);
    Equipment::factory()->create(['code' => 'OLD-01', 'name' => 'Retired press', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Index::class)
        ->assertSee('CNC-01')
        ->assertSee('BLR-01')
        ->assertDontSee('OLD-01')
        ->set('search', 'utilities')
        ->assertSee('BLR-01')
        ->assertDontSee('CNC-01')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('OLD-01')
        ->assertDontSee('CNC-01');
});
