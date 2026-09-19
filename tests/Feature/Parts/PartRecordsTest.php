<?php

use App\Enums\Role;
use App\Livewire\Parts\Form;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use Livewire\Livewire;

it('lets supervisors, planners and administrators create spare parts', function (Role $role, bool $allowed) {
    $this->actingAs(userWithRoles($role))->get(route('parts.create'))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('-SPA'));

it('lets every role open the edit form, custodians for basic fields', function (Role $role, bool $allowed) {
    $part = Part::factory()->create();

    $this->actingAs(userWithRoles($role))->get(route('parts.edit', $part))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('CSPA'));

it('creates a part at zero stock with its equipment, audited', function () {
    $planner = userWithRoles(Role::Planner);
    $rack = StorageLocation::factory()->create(['code' => 'A-01']);
    $cnc = Equipment::factory()->create(['code' => 'CNC-01']);
    $press = Equipment::factory()->create(['code' => 'PRS-02']);

    Livewire::actingAs($planner)->test(Form::class)
        ->set('part_number', ' BRG-6204 ')
        ->set('description', 'Deep groove ball bearing')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) $rack->id)
        ->set('equipment_ids', [(string) $cnc->id, (string) $press->id])
        ->call('save')
        ->assertHasNoErrors();

    $part = Part::query()->sole();

    expect($part->part_number)->toBe('BRG-6204')
        ->and(onHand($part))->toBe('0.000')
        ->and($part->equipment()->orderBy('code')->pluck('code')->all())->toBe(['CNC-01', 'PRS-02'])
        ->and(AuditLog::query()->where('auditable_type', 'part')->where('event', 'created')->sole())
        ->user_id->toBe($planner->id)
        ->new_values->toBe([
            'equipment' => ['CNC-01', 'PRS-02'],
            'is_active' => true,
            'description' => 'Deep groove ball bearing',
            'part_number' => 'BRG-6204',
            'unit_of_measure' => 'pcs',
            'default_location' => 'A-01',
        ]);
});

it('refuses a part number that exists in any letter case, and links to it', function () {
    $existing = Part::factory()->create(['part_number' => 'BRG-6204']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class)
        ->set('part_number', 'brg-6204')
        ->set('description', 'Duplicate')
        ->set('unit_of_measure', 'pcs')
        ->call('save')
        ->assertHasErrors('part_number')
        ->assertSet('duplicateOfId', $existing->id)
        ->assertSee(route('parts.show', $existing));
});

it('refuses an Inactive storage location', function () {
    $closed = StorageLocation::factory()->create(['is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class)
        ->set('part_number', 'SEAL-9')
        ->set('description', 'Oil seal')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) $closed->id)
        ->call('save')
        ->assertHasErrors('default_location_id');
});

it('refuses to reactivate a part kept at an Inactive storage location', function () {
    $closed = StorageLocation::factory()->create(['is_active' => false]);
    $part = Part::factory()->create(['is_active' => false, 'default_location_id' => $closed->id]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $part])
        ->set('is_active', true)
        ->call('save')
        ->assertHasErrors('default_location_id');

    expect($part->refresh()->is_active)->toBeFalse();
});

it('reactivates a part kept at an Active storage location', function () {
    $rack = StorageLocation::factory()->create(['is_active' => true]);
    $part = Part::factory()->create(['is_active' => false, 'default_location_id' => $rack->id]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $part])
        ->set('is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($part->refresh()->is_active)->toBeTrue();
});

it('fixes the unit of measure once the part has stock movements', function () {
    configureInstall();
    $moved = partWithStock('5', ['unit_of_measure' => 'L']);
    $unmoved = Part::factory()->create(['unit_of_measure' => 'L']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $moved])
        ->set('unit_of_measure', 'mL')
        ->call('save')
        ->assertHasErrors('unit_of_measure');

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $unmoved])
        ->set('unit_of_measure', 'mL')
        ->call('save')
        ->assertHasNoErrors();

    expect($moved->refresh()->unit_of_measure)->toBe('L')
        ->and($unmoved->refresh()->unit_of_measure)->toBe('mL');
});

it('asks for confirmation before deactivating a part that still has stock', function () {
    configureInstall();
    $part = partWithStock('2');

    $screen = Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['part' => $part])
        ->set('is_active', false)
        ->assertSee('no longer be available for withdrawal')
        ->call('save')
        ->assertHasErrors('confirmDeactivation');

    expect($part->refresh()->is_active)->toBeTrue();

    $screen->set('confirmDeactivation', true)->call('save')->assertHasNoErrors();

    expect($part->refresh()->is_active)->toBeFalse();
});

it('lets a custodian change only the description, unit of measure and location', function () {
    $part = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $rack = StorageLocation::factory()->create();
    $machine = Equipment::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(Form::class, ['part' => $part])
        ->set('part_number', 'CHANGED')
        ->set('is_active', false)
        ->set('equipment_ids', [(string) $machine->id])
        ->set('description', 'Deep groove ball bearing')
        ->set('default_location_id', (string) $rack->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($part->refresh())
        ->part_number->toBe('BRG-6204')
        ->is_active->toBeTrue()
        ->description->toBe('Deep groove ball bearing')
        ->default_location_id->toBe($rack->id)
        ->and($part->equipment()->exists())->toBeFalse();
});

it('records the old and new values of an edit, equipment links included', function () {
    $old = Equipment::factory()->create(['code' => 'CNC-01']);
    $new = Equipment::factory()->create(['code' => 'PRS-02']);
    $part = Part::factory()->create(['description' => 'Bearing']);
    $part->equipment()->attach($old->id);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $part])
        ->set('description', 'Deep groove ball bearing')
        ->set('equipment_ids', [(string) $new->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(AuditLog::query()->where('auditable_type', 'part')->where('event', 'updated')->sole())
        ->old_values->toBe(['equipment' => ['CNC-01'], 'description' => 'Bearing'])
        ->new_values->toBe(['equipment' => ['PRS-02'], 'description' => 'Deep groove ball bearing']);
});
