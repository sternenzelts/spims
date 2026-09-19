<?php

use App\Enums\Role;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\Admin\WithdrawalPurposes;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Parts\Form as PartForm;
use App\Livewire\Parts\StockLevelsForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Livewire\Users\Form as UserForm;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Every data-changing action in Plan 2 authorizes again, on top of mount(). Only mount() is exercised
// by the screen access matrix, so deleting authorize() from any of these actions would fail nothing
// without this test. Record parameters are built lazily inside each row's own closure, because a Pest
// dataset is collected before RefreshDatabase and the Seed attribute have run for the test.
dataset('reauthorized actions', [
    'Users\Form::save' => [
        Role::Admin, UserForm::class, fn () => ['user' => User::factory()->create()], 'save', fn () => [],
    ],
    'Admin\InstallSettingsForm::save' => [
        Role::Admin, InstallSettingsForm::class, fn () => [], 'save', fn () => [],
    ],
    'Admin\WithdrawalPurposes::save' => [
        Role::Admin, WithdrawalPurposes::class, fn () => [], 'save', fn () => [],
    ],
    'Admin\WithdrawalPurposes::remove' => [
        Role::Admin, WithdrawalPurposes::class, fn () => [], 'remove',
        fn () => [WithdrawalPurpose::query()->where('code', 'machine_modification')->sole()->id],
    ],
    'MasterData\StorageLocations\Form::save' => [
        Role::Supervisor, StorageLocationForm::class, fn () => ['location' => StorageLocation::factory()->create()], 'save', fn () => [],
    ],
    'MasterData\Equipment\Form::save' => [
        Role::Supervisor, EquipmentForm::class, fn () => ['equipment' => Equipment::factory()->create()], 'save', fn () => [],
    ],
    'Parts\Form::save' => [
        Role::Supervisor, PartForm::class, fn () => [], 'save', fn () => [],
    ],
    'Parts\StockLevelsForm::save' => [
        Role::Planner, StockLevelsForm::class, fn () => ['part' => Part::factory()->create()], 'save', fn () => [],
    ],
    'Personnel\Form::save' => [
        Role::Supervisor, PersonnelForm::class, fn () => ['personnel' => Personnel::factory()->create()], 'save', fn () => [],
    ],
    'Personnel\Form::enrollBadge' => [
        Role::Supervisor, PersonnelForm::class, fn () => ['personnel' => Personnel::factory()->create()], 'enrollBadge', fn () => [],
    ],
]);

it('re-checks the permission in the action, not only in mount', function (Role $role, string $component, Closure $mountParams, string $action, Closure $actionArgs) {
    $user = userWithRoles($role);

    $screen = Livewire::actingAs($user)->test($component, $mountParams());

    $user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $screen->call($action, ...$actionArgs())->assertForbidden();
})->with('reauthorized actions');
