<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Parts\Form as PartForm;
use App\Livewire\Parts\StockLevelsForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;
use Livewire\Livewire;

it('provisions an install through the screens alone: design §3.6 steps 4, 5, 6 and 8', function () {
    // Step 4: the first System Administrator.
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->assertSuccessful();
    $admin = User::query()->where('email', 'it.admin@microtech.test')->sole();

    // Step 5: company profile.
    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->call('save')
        ->assertHasNoErrors();

    // Step 6: storage locations and the equipment register.
    Livewire::actingAs($admin)->test(StorageLocationForm::class)
        ->set('code', 'A-01')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(EquipmentForm::class)
        ->set('code', 'CNC-01')
        ->set('name', 'CNC lathe 1')
        ->call('save')
        ->assertHasNoErrors();

    // Spare parts one at a time, until the import in Plan 2b.
    Livewire::actingAs($admin)->test(PartForm::class)
        ->set('part_number', 'BRG-6204')
        ->set('description', 'Deep groove ball bearing')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) StorageLocation::query()->sole()->id)
        ->set('equipment_ids', [(string) Equipment::query()->sole()->id])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(StockLevelsForm::class, ['part' => Part::query()->sole()])
        ->set('reorder_point', '10')
        ->set('min_level', '4')
        ->set('critical_level', '2')
        ->set('criticality', 'critical')
        ->call('save')
        ->assertHasNoErrors();

    // Step 8: personnel and badges.
    Livewire::actingAs($admin)->test(PersonnelForm::class)
        ->set('employee_no', 'EMP-00042')
        ->set('full_name', 'Jose Rizal')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(PersonnelForm::class, ['personnel' => Personnel::query()->sole()])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    $checklist = collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label');

    expect(app(InstallSettings::class)->isReadyForStockTransactions())->toBeTrue()
        ->and($checklist->except('Opening balances')->every(fn (bool $done): bool => $done))->toBeTrue()
        ->and($checklist['Opening balances'])->toBeFalse()
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('user_id', $admin->id)->distinct()->pluck('auditable_type')->sort()->values()->all())
        ->toBe(['equipment', 'part', 'personnel', 'setting', 'storage_location']);
});
