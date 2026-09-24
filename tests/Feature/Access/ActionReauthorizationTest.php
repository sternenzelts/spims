<?php

use App\Enums\Role;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\Admin\WithdrawalPurposes;
use App\Livewire\Imports\Review as ImportReview;
use App\Livewire\Imports\Upload as ImportUpload;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Parts\Form as PartForm;
use App\Livewire\Parts\StockLevelsForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Livewire\Station\Replenishment as StationReplenishment;
use App\Livewire\Station\Withdrawal as StationWithdrawal;
use App\Livewire\Users\Form as UserForm;
use App\Models\Equipment;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Every data-changing action in Plans 2 and 2b authorizes again, on top of mount(). Only mount() is exercised
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
    'Imports\Upload::save' => [
        Role::Supervisor, ImportUpload::class, fn () => [], 'save', fn () => [],
    ],
    'Imports\Review::check' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'check', fn () => [],
    ],
    'Imports\Review::import' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'import', fn () => [],
    ],
    'Imports\Review::cancel' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'cancel', fn () => [],
    ],
    // Every station action calls authorize() as its first statement, which is why a row can pass
    // an argument the action never reaches: scan with no badge and confirm on an empty draft are
    // both refused before either is looked at.
    'Station\Withdrawal::enterPart' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Station\Withdrawal::addPart' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'addPart', fn () => [Part::factory()->create()->id],
    ],
    'Station\Withdrawal::removeLine' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'removeLine', fn () => [0],
    ],
    'Station\Withdrawal::saveContext' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'saveContext', fn () => [],
    ],
    'Station\Withdrawal::scan' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'scan', fn () => [],
    ],
    'Station\Withdrawal::clearIdentification' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'clearIdentification', fn () => [],
    ],
    'Station\Withdrawal::requestOverride' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'requestOverride', fn () => [Part::factory()->create()->id],
    ],
    'Station\Withdrawal::authoriseOverride' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'authoriseOverride', fn () => [],
    ],
    'Station\Withdrawal::requestManualIdentification' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'requestManualIdentification', fn () => [],
    ],
    'Station\Withdrawal::forgetSupervisorCredentials' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'forgetSupervisorCredentials', fn () => [],
    ],
    'Station\Withdrawal::identifyManually' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'identifyManually', fn () => [],
    ],
    'Station\Withdrawal::confirm' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'confirm', fn () => [],
    ],
    'Station\Withdrawal::cancelDraft' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'cancelDraft', fn () => [],
    ],
    'Station\Replenishment::saveDelivery' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'saveDelivery', fn () => [],
    ],
    'Station\Replenishment::enterPart' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Station\Replenishment::addPart' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'addPart', fn () => [Part::factory()->create()->id],
    ],
    'Station\Replenishment::removeLine' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'removeLine', fn () => [0],
    ],
    'Station\Replenishment::confirm' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'confirm', fn () => [],
    ],
    'Station\Replenishment::cancelDraft' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'cancelDraft', fn () => [],
    ],
]);

it('re-checks the permission in the action, not only in mount', function (Role $role, string $component, Closure $mountParams, string $action, Closure $actionArgs) {
    $user = userWithRoles($role);

    $screen = Livewire::actingAs($user)->test($component, $mountParams());

    $user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $screen->call($action, ...$actionArgs())->assertForbidden();
})->with('reauthorized actions');
