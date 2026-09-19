<?php

use App\Enums\Role;
use App\Livewire\Admin\WithdrawalPurposes;
use App\Models\AuditLog;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use Livewire\Livewire;

it('lets only System Administrators manage withdrawal purposes', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('withdrawal-purposes.index'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Withdrawal purposes', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('adds a purpose at the end of the list, audited', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(WithdrawalPurposes::class)
        ->call('create')
        ->set('name', 'Calibration')
        ->set('requires_remarks', true)
        ->call('save')
        ->assertHasNoErrors();

    $purpose = WithdrawalPurpose::query()->where('name', 'Calibration')->sole();

    expect($purpose->code)->toBe('calibration')
        ->and($purpose->requires_remarks)->toBeTrue()
        ->and($purpose->sort_order)->toBe(70)
        ->and(AuditLog::query()->where('auditable_type', 'withdrawal_purpose')->where('event', 'created')->sole())
        ->auditable_id->toBe((string) $purpose->id)
        ->user_id->toBe($admin->id);
});

it('renames and deactivates a purpose, recording old and new values', function () {
    $purpose = WithdrawalPurpose::query()->where('code', 'breakdown_repair')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('edit', $purpose->id)
        ->set('name', 'Emergency Breakdown')
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($purpose->refresh())
        ->name->toBe('Emergency Breakdown')
        ->is_active->toBeFalse()
        ->and(AuditLog::query()->where('event', 'updated')->sole())
        ->old_values->toBe(['name' => 'Breakdown Repair', 'is_active' => true])
        ->new_values->toBe(['name' => 'Emergency Breakdown', 'is_active' => false]);
});

it('refuses a name another purpose holds, in any letter case', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('create')
        ->set('name', 'breakdown repair')
        ->call('save')
        ->assertHasErrors('name');
});

it('keeps at least one purpose Active', function () {
    WithdrawalPurpose::query()->where('code', '!=', 'other')->update(['is_active' => false]);
    $last = WithdrawalPurpose::query()->where('code', 'other')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('edit', $last->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active');

    expect($last->refresh()->is_active)->toBeTrue();
});

it('deletes a purpose no transaction has used', function () {
    $unused = WithdrawalPurpose::query()->where('code', 'machine_modification')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('remove', $unused->id)
        ->assertHasNoErrors();

    expect(WithdrawalPurpose::query()->find($unused->id))->toBeNull()
        ->and(AuditLog::query()->where('event', 'deleted')->sole())
        ->auditable_id->toBe((string) $unused->id)
        ->old_values->toMatchArray(['name' => 'Machine Modification']);
});

it('never deletes a purpose a transaction has used', function () {
    $used = WithdrawalPurpose::query()->where('code', 'preventive_maintenance')->sole();
    StockTransaction::factory()->create(['purpose_id' => $used->id]);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('remove', $used->id)
        ->assertHasErrors('purposes');

    expect(WithdrawalPurpose::query()->find($used->id))->not->toBeNull();
});
