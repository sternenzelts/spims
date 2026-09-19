<?php

use App\Enums\Role;
use App\Livewire\Users\Form;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets only System Administrators manage users', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('users.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('users.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('users.edit', $user))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Users', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('creates an account with its roles, ready to sign in', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Maria Santos')
        ->set('email', ' Maria.Santos@Microtech.TEST ')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->set('roles', ['supervisor', 'custodian'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'maria.santos@microtech.test')->sole();

    expect($user->hasAllRoles([Role::Custodian, Role::Supervisor]))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-password-1', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'created')->where('auditable_type', 'user')->sole())
        ->auditable_id->toBe((string) $user->id)
        ->user_id->toBe($admin->id)
        ->new_values->toBe([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@microtech.test',
            'roles' => ['custodian', 'supervisor'],
            'is_active' => true,
        ]);
});

it('refuses an email another account holds, in any letter case', function () {
    User::factory()->create(['email' => 'taken@microtech.test']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'Taken@Microtech.test')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->set('roles', ['planner'])
        ->call('save')
        ->assertHasErrors(['email' => 'unique']);
});

it('requires at least one role', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'someone@microtech.test')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->call('save')
        ->assertHasErrors(['roles' => 'required']);
});

it('changes roles and deactivates an account, recording old and new values', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Custodian);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->assertSet('roles', ['custodian'])
        ->set('roles', ['supervisor'])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();

    expect($user->is_active)->toBeFalse()
        ->and($user->hasRole(Role::Supervisor))->toBeTrue()
        ->and($user->hasRole(Role::Custodian))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'updated')->where('auditable_id', (string) $user->id)->sole())
        ->old_values->toBe(['roles' => ['custodian'], 'is_active' => true])
        ->new_values->toBe(['roles' => ['supervisor'], 'is_active' => false]);
});

it('does not let administrators remove their own administrator role', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $admin])
        ->set('roles', ['planner'])
        ->call('save')
        ->assertHasErrors('roles');

    expect($admin->fresh()->hasRole(Role::Admin))->toBeTrue();
});

it('does not let administrators deactivate themselves', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $admin])
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active');

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('sets a new password without recording it, and keeps it when left blank', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Planner);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->set('password', 'another-password-2')
        ->set('password_confirmation', 'another-password-2')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('another-password-2', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'password_reset')->sole())
        ->old_values->toBeNull()
        ->new_values->toBeNull();

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('another-password-2', $user->fresh()->password))->toBeTrue();
});

it('finds users by name or email and filters them by role', function () {
    $admin = userWithRoles(Role::Admin);
    User::factory()->create(['name' => 'Pedro Reyes'])->assignRole(Role::Planner);
    User::factory()->create(['name' => 'Ana Cruz', 'email' => 'ana.cruz@microtech.test'])->assignRole(Role::Custodian);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('search', 'pedro')
        ->assertSee('Pedro Reyes')
        ->assertDontSee('Ana Cruz')
        ->set('search', 'ANA.CRUZ@')
        ->assertSee('Ana Cruz')
        ->assertDontSee('Pedro Reyes')
        ->set('search', '')
        ->set('role', 'planner')
        ->assertSee('Pedro Reyes')
        ->assertDontSee('Ana Cruz');
});
